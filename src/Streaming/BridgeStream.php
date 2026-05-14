<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * Streams AI responses through a CLI bridge connected via WebSocket.
 *
 * When the consuming app calls start(), this class:
 * 1. Looks up the user's active bridge connection
 * 2. Sends an ai_request message over the WebSocket
 * 3. Registers the request as pending so incoming events can be routed
 *
 * Note: BridgeStream is inherently asynchronous. Unlike ChatCompletionsStream which
 * blocks during start() until the HTTP SSE stream completes, BridgeStream's start()
 * returns immediately after sending the ai_request. Events arrive asynchronously via
 * the WebSocket server and are dispatched to the StreamHandler by the MessageHandler.
 *
 * ## PHP-FPM vs Long-Running Process
 *
 * When running under a long-running process (e.g. the bridge WebSocket server itself,
 * Octane, or Swoole), BridgeConnectionManager holds connections in-memory and start()
 * can send directly via the WebSocket.
 *
 * Under PHP-FPM (shared-nothing model), the BridgeConnectionManager is empty because
 * the WebSocket server runs in a separate process. In this case, BridgeStream falls
 * back to relaying the request through the bridge server's internal HTTP API
 * (POST /api/request on the configured server port). The response events still arrive
 * asynchronously via the WebSocket server and must be delivered to the client via
 * broadcasting (Reverb), NOT SSE (which requires a blocking connection).
 */
class BridgeStream implements StreamableProvider
{
    private string $conversationId = '';

    private string $message = '';

    /** @var array<string, mixed> */
    private array $options = [];

    private StreamHandler $streamHandler;

    private bool $cancelled = false;

    /**
     * Whether the stream has completed (done or error received).
     * Useful for the consuming app to check completion state in async scenarios.
     */
    private bool $completed = false;

    /** The provider name to route to on the bridge (e.g. 'claude', 'codex', 'gemini'). */
    private string $provider = '';

    public function __construct(
        private readonly BridgeConnectionManager $connectionManager,
        private readonly ToolRegistry $toolRegistry,
        private readonly int|string $userId,
    ) {
        $this->streamHandler = new StreamHandler($this);
    }

    public function setConversationId(string $conversationId): static
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Set the provider name for routing on the bridge side.
     *
     * @param  string  $provider  The provider name (e.g. 'claude', 'codex', 'gemini').
     */
    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getStreamHandler(): StreamHandler
    {
        return $this->streamHandler;
    }

    /**
     * Check whether the stream has completed (done or error received).
     */
    public function isCompleted(): bool
    {
        return $this->completed;
    }

    /**
     * Mark the stream as completed.
     *
     * @internal Called by the StreamHandler when done or error is dispatched.
     */
    public function markCompleted(): void
    {
        $this->completed = true;
    }

    /**
     * Build the ai_request payload to send to the bridge.
     *
     * @return array<string, mixed>
     */
    public function buildRequestPayload(): array
    {
        $payload = [
            'type' => MessageTypes::AI_REQUEST,
            'request_id' => $this->streamHandler->requestId,
            'conversation_id' => $this->conversationId,
            'provider' => $this->provider,
            'message' => $this->message,
        ];

        if (isset($this->options['system_prompt'])) {
            $payload['system_prompt'] = $this->options['system_prompt'];
        }

        $requestOptions = [];
        if (isset($this->options['temperature'])) {
            $requestOptions['temperature'] = $this->options['temperature'];
        }

        if (isset($this->options['max_tokens'])) {
            $requestOptions['max_tokens'] = $this->options['max_tokens'];
        }

        if (isset($this->options['model'])) {
            $requestOptions['model'] = $this->options['model'];
        }

        if (! empty($requestOptions)) {
            $payload['options'] = $requestOptions;
        }

        if (isset($this->options['messages'])) {
            $payload['messages'] = $this->options['messages'];
        }

        // Include registered tools so the bridge knows what's available
        $tools = $this->toolRegistry->toArray();
        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    /**
     * Send the ai_request to the bridge and register as pending.
     *
     * NOTE: This method is non-blocking for BridgeStream. Unlike ChatCompletionsStream::start()
     * which blocks until the HTTP stream completes, this returns immediately after sending
     * the request. Events arrive asynchronously via WebSocket and are dispatched to the
     * StreamHandler by the MessageHandler as they arrive.
     */
    public function start(): void
    {
        $this->cancelled = false;
        $this->completed = false;

        $payload = $this->buildRequestPayload();

        // Try direct WebSocket send first (works in long-running processes like
        // the bridge server itself, Octane, or Swoole where connections are in-memory).
        if ($this->connectionManager->hasConnection($this->userId)) {
            $sent = $this->connectionManager->sendToUser($this->userId, $payload);

            if (! $sent) {
                $this->streamHandler->dispatchError(
                    'bridge_send_failed',
                    'Failed to send request to bridge.'
                );

                return;
            }

            // Register this stream as a pending request so incoming WebSocket
            // messages can be routed to the correct StreamHandler.
            $this->connectionManager->registerPendingRequest(
                $this->streamHandler->requestId,
                $this->streamHandler,
                (string) $this->userId,
            );

            return;
        }

        // Fallback: relay through the bridge server's internal HTTP API.
        // This is the PHP-FPM path — the ConnectionManager is empty because
        // the WebSocket server runs in a separate process.
        $this->relayViaHttpApi($payload);
    }

    /**
     * Relay an ai_request through the bridge server's internal HTTP API.
     *
     * Used under PHP-FPM where the WebSocket server is a separate process.
     * The internal API at POST /api/request accepts the request and forwards
     * it to the connected bridge client.
     *
     * NOTE: Response events arrive asynchronously via the WebSocket server.
     * For SSE mode, this means the response will be empty — use broadcasting
     * (Reverb) mode when running under PHP-FPM with bridge mode.
     */
    private function relayViaHttpApi(array $payload): void
    {
        $host = config('ai-bridge.server.host', '127.0.0.1');
        $port = (int) config('ai-bridge.server.port', 8085);
        $tokenSecret = config('ai-bridge.token.secret', '');

        // Use 127.0.0.1 for localhost connections (0.0.0.0 is a listen address, not connectable)
        if ($host === '0.0.0.0') {
            $host = '127.0.0.1';
        }

        $url = "http://{$host}:{$port}/api/request";

        try {
            // Generate a short-lived token for internal API auth
            $tokenManager = app(\Tetrix\AiBridge\Auth\TokenManager::class);
            $internalToken = $tokenManager->generate($this->userId, [], 60);

            $response = Http::withToken($internalToken)
                ->timeout(5)
                ->post($url, [
                    'provider' => $payload['provider'] ?? '',
                    'message' => $payload['message'] ?? '',
                    'conversation_id' => $payload['conversation_id'] ?? '',
                    'system_prompt' => $payload['system_prompt'] ?? null,
                    'options' => $payload['options'] ?? [],
                    'messages' => $payload['messages'] ?? null,
                ]);

            if ($response->failed()) {
                $body = $response->json();
                $error = $body['error'] ?? 'unknown';
                $message = $body['message'] ?? "HTTP API returned status {$response->status()}";

                $this->streamHandler->dispatchError($error, $message);

                return;
            }

            // Request was accepted by the bridge server. Events will arrive
            // asynchronously via WebSocket → MessageHandler → broadcasting.
            // NOTE: In SSE mode, the caller will NOT receive these events
            // because the HTTP response has already been sent. Use broadcasting mode.
            Log::debug('AI Bridge: relayed request via HTTP API', [
                'request_id' => $payload['request_id'] ?? '',
                'user_id' => $this->userId,
            ]);

        } catch (\Exception $e) {
            $this->streamHandler->dispatchError(
                'bridge_relay_failed',
                'Failed to relay request to bridge server: ' . $e->getMessage()
            );
        }
    }

    public function cancel(): void
    {
        $this->cancelled = true;

        // Send cancel message to bridge
        $this->connectionManager->sendToUser($this->userId, [
            'type' => MessageTypes::CANCEL,
            'request_id' => $this->streamHandler->requestId,
        ]);

        // Clean up the pending request
        $this->connectionManager->removePendingRequest($this->streamHandler->requestId);
    }
}
