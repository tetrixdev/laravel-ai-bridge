<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
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
        private readonly TokenManager $tokenManager,
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
     * Under PHP-FPM (when WebSocket server runs as separate process), this method
     * blocks for up to relay_timeout seconds while the internal HTTP relay completes.
     * True non-blocking behavior only applies to long-running server deployments
     * (Octane, bridge server itself) where BridgeConnectionManager holds connections
     * in-memory and sendToUser() returns immediately.
     *
     * NOTE: When using the PHP-FPM relay path with SSE mode, no stream events will
     * be delivered because events arrive asynchronously via the WebSocket server after
     * the PHP-FPM process has exited. Use broadcasting (Reverb) mode instead.
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
        // SEC-018: Allow configuring HTTPS for internal relay when the bridge server
        // runs on a separate host or behind a TLS proxy.
        $configuredUrl = config('ai-bridge.server.relay_url');

        if (! empty($configuredUrl)) {
            $url = rtrim($configuredUrl, '/') . '/api/request';
        } else {
            $host = config('ai-bridge.server.host', '127.0.0.1');
            $port = (int) config('ai-bridge.server.port', 8085);

            // Use 127.0.0.1 for localhost connections (0.0.0.0 is a listen address, not connectable)
            if ($host === '0.0.0.0') {
                $host = '127.0.0.1';
            }

            $url = "http://{$host}:{$port}/api/request";
        }

        try {
            // Use a short-lived token with 'scope' => 'internal_relay' to distinguish
            // internal relay requests from user-facing bridge connection tokens.
            $internalToken = $this->tokenManager->generate($this->userId, ['scope' => 'internal_relay'], 60);

            $response = Http::withToken($internalToken)
                ->timeout((int) config('ai-bridge.server.relay_timeout', 5))
                ->post($url, [
                    'request_id' => $payload['request_id'] ?? '',
                    'provider' => $payload['provider'] ?? '',
                    'message' => $payload['message'] ?? '',
                    'conversation_id' => $payload['conversation_id'] ?? '',
                    'system_prompt' => $payload['system_prompt'] ?? null,
                    'options' => $payload['options'] ?? [],
                    'messages' => $payload['messages'] ?? null,
                    'tools' => $payload['tools'] ?? [],
                ]);

            if ($response->failed()) {
                $body = $response->json();
                $error = $body['error'] ?? 'unknown';

                Log::error('AI Bridge: bridge server returned error', [
                    'request_id' => $payload['request_id'] ?? '',
                    'status' => $response->status(),
                    'error' => $error,
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                $this->streamHandler->dispatchError(
                    $error,
                    'Bridge server returned HTTP '.$response->status().'. Check server logs for details.'
                );

                return;
            }

            // Request was accepted by the bridge server. Events will arrive
            // asynchronously via WebSocket → MessageHandler → broadcasting.
            Log::info('AI Bridge: relayed request via HTTP API (events arrive via broadcasting)', [
                'request_id' => $payload['request_id'] ?? '',
                'user_id' => $this->userId,
            ]);

            // Warn: if the caller is using SSE (streamToResponse), the async events
            // won't be delivered because PHP-FPM exits after the HTTP response.
            Log::warning('AI Bridge: bridge mode under PHP-FPM — SSE callers will receive no stream events. Use broadcasting mode or switch to Octane/Swoole.', [
                'request_id' => $payload['request_id'] ?? '',
            ]);

        } catch (\Exception $e) {
            Log::error('AI Bridge: failed to relay request to bridge server', [
                'request_id' => $payload['request_id'] ?? '',
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Categorize the error for more actionable user-facing messages
            $userMessage = str_contains($e->getMessage(), 'timed out')
                ? 'Bridge server did not respond within the timeout period.'
                : (str_contains($e->getMessage(), 'Connection refused')
                    ? 'Could not connect to bridge server. Is it running?'
                    : 'Failed to relay request to bridge server. Check server logs for details.');

            $this->streamHandler->dispatchError('bridge_relay_failed', $userMessage);
        }
    }

    public function cancel(): void
    {
        $this->cancelled = true;

        // Send cancel message to bridge.
        // Don't remove pending request here — the bridge responds with a 'cancelled'
        // message, and handleCancelled() needs the pending request to dispatch the
        // terminal error event before cleaning up.
        $sent = $this->connectionManager->sendToUser($this->userId, [
            'type' => MessageTypes::CANCEL,
            'request_id' => $this->streamHandler->requestId,
        ]);

        // If the cancel message couldn't be sent (connection gone), dispatch error
        // and clean up the pending request since we won't receive a 'cancelled' response.
        if (! $sent) {
            $this->streamHandler->dispatchError(
                'cancel_send_failed',
                'Failed to send cancel to bridge — connection may be lost.'
            );
            $this->connectionManager->removePendingRequest($this->streamHandler->requestId);
        }
    }
}
