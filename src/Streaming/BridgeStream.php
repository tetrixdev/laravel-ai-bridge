<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

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
 * The actual WebSocket transport depends on the consuming app's WebSocket server
 * (e.g. Laravel Reverb, Ratchet, Swoole). This class provides the message
 * structure and event routing; the transport integration is handled by the
 * BridgeConnectionManager.
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

        // Verify the user has an active bridge connection
        if (! $this->connectionManager->hasConnection($this->userId)) {
            $this->streamHandler->dispatchError(
                'bridge_not_connected',
                'No active bridge connection for this user.'
            );

            return;
        }

        $payload = $this->buildRequestPayload();

        // Send the AI request to the bridge via WebSocket
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

        // The actual streaming happens asynchronously via the WebSocket server.
        // The MessageHandler will call dispatchEvent() on this StreamHandler
        // as messages arrive from the bridge. In a synchronous context (e.g. testing),
        // the connection manager may process messages inline.
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
