<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

use Tetrix\AiBridge\Streaming\StreamHandler;

/**
 * Interface that all AI streaming providers must implement.
 *
 * Both BridgeStream (WebSocket-based) and ChatCompletionsStream (HTTP SSE-based)
 * implement this interface, ensuring the consuming application gets the same
 * streaming API regardless of the underlying transport.
 */
interface StreamableProvider
{
    /**
     * Set the conversation ID for this stream.
     */
    public function setConversationId(string $conversationId): static;

    /**
     * Set the user message to send to the AI.
     */
    public function setMessage(string $message): static;

    /**
     * Set additional options (system prompt, temperature, etc.).
     *
     * @param  array<string, mixed>  $options
     */
    public function setOptions(array $options): static;

    /**
     * Begin streaming the AI response.
     *
     * This method should not return until the stream is complete,
     * an error occurs, or the stream is cancelled.
     */
    public function start(): void;

    /**
     * Cancel an in-progress stream.
     */
    public function cancel(): void;

    /**
     * Get the StreamHandler that manages event callbacks for this provider.
     */
    public function getStreamHandler(): StreamHandler;
}
