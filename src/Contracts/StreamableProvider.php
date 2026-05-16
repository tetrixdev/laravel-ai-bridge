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
 *
 * IMPORTANT — ARCHITECTURAL NOTE (ARCH-001):
 * This interface intentionally conflates two behaviorally incompatible contracts
 * for start(). The two implementations are NEVER used polymorphically — callers
 * always go through AiBridgeManager, which knows the concrete type via ProviderMode.
 * Future refactoring could split this into SynchronousStreamableProvider and
 * AsynchronousStreamableProvider, but for now the distinction is enforced through
 * documentation rather than the type system.
 *
 * BLOCKING behavior of start():
 *
 * - ChatCompletionsStream::start() is SYNCHRONOUS — it blocks the current
 *   thread/process while reading the HTTP SSE stream. When start() returns,
 *   the stream is complete and all callbacks have been invoked.
 *
 * - BridgeStream::start() is ASYNCHRONOUS — it sends the ai_request message
 *   over WebSocket and returns immediately. Events arrive later via the
 *   WebSocket server and are dispatched to the StreamHandler by the
 *   MessageHandler as they come in. The consuming app must handle this
 *   async flow (e.g. via event listeners or a WebSocket server loop).
 *
 * DO NOT write generic code that calls start() on a StreamableProvider without
 * knowing which concrete type it is — the post-start behavior is incompatible.
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
     * For synchronous providers (ChatCompletionsStream), this method blocks
     * until the stream is complete, an error occurs, or the stream is cancelled.
     *
     * For asynchronous providers (BridgeStream), this method sends the request
     * and returns immediately. Events are dispatched asynchronously via the
     * WebSocket server.
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

    /**
     * Mark the stream as completed. Called by StreamHandler after terminal events.
     *
     * Implement as a no-op if the provider doesn't need completion tracking.
     */
    public function markCompleted(): void;
}
