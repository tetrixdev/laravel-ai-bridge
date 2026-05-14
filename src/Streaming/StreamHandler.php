<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Closure;
use Illuminate\Support\Str;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Protocol\StreamEvent;

/**
 * The unified streaming interface for AI responses.
 *
 * StreamHandler is the main class consuming applications interact with.
 * It provides a consistent callback-based API regardless of whether the
 * AI response comes from a CLI bridge, BYOK, or managed provider.
 *
 * Usage:
 *   $stream = AiBridge::stream($conversationId, $message);
 *   $stream->onBlockStart(fn (StreamEvent $e) => ...);
 *   $stream->onBlockDelta(fn (StreamEvent $e) => ...);
 *   $stream->onBlockStop(fn (StreamEvent $e) => ...);
 *   $stream->onToolCall(fn (string $name, array $params, string $callId) => ...);
 *   $stream->onDone(fn (?array $usage) => ...);
 *   $stream->onError(fn (string $code, string $message) => ...);
 *   $stream->start();
 */
class StreamHandler
{
    /** @var Closure[] */
    private array $blockStartCallbacks = [];

    /** @var Closure[] */
    private array $blockDeltaCallbacks = [];

    /** @var Closure[] */
    private array $blockStopCallbacks = [];

    /** @var Closure[] */
    private array $toolCallCallbacks = [];

    /** @var Closure[] */
    private array $doneCallbacks = [];

    /** @var Closure[] */
    private array $errorCallbacks = [];

    private bool $cancelled = false;

    public readonly string $requestId;

    public function __construct(
        private readonly StreamableProvider $provider,
    ) {
        $this->requestId = Str::uuid()->toString();
    }

    /**
     * Register a callback for when a content block starts.
     *
     * The callback receives a StreamEvent with block_type and block_index in data.
     */
    public function onBlockStart(Closure $callback): static
    {
        $this->blockStartCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for content deltas within a block.
     *
     * The callback receives a StreamEvent with block_type, block_index, and content in data.
     */
    public function onBlockDelta(Closure $callback): static
    {
        $this->blockDeltaCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for when a content block ends.
     *
     * The callback receives a StreamEvent with block_type and block_index in data.
     */
    public function onBlockStop(Closure $callback): static
    {
        $this->blockStopCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for tool calls from the AI.
     *
     * The callback receives: string $toolName, array $params, string $callId.
     */
    public function onToolCall(Closure $callback): static
    {
        $this->toolCallCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for when the stream completes.
     *
     * The callback receives: ?array $usage (token counts, if available).
     */
    public function onDone(Closure $callback): static
    {
        $this->doneCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for errors during streaming.
     *
     * The callback receives: string $code, string $message.
     */
    public function onError(Closure $callback): static
    {
        $this->errorCallbacks[] = $callback;

        return $this;
    }

    /**
     * Start the stream. This method blocks until the stream completes, errors, or is cancelled.
     */
    public function start(): void
    {
        $this->cancelled = false;
        $this->provider->start();
    }

    /**
     * Cancel the active stream.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
        $this->provider->cancel();
    }

    /**
     * Check if the stream has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    // ── Internal dispatch methods (called by provider implementations) ──

    /**
     * Dispatch a block_start event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockStart(BlockType $blockType, int $blockIndex): void
    {
        if ($this->cancelled) {
            return;
        }

        $event = StreamEvent::blockStart($this->requestId, $blockType, $blockIndex);

        foreach ($this->blockStartCallbacks as $callback) {
            $callback($event);
        }
    }

    /**
     * Dispatch a block_delta event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockDelta(BlockType $blockType, int $blockIndex, string $content): void
    {
        if ($this->cancelled) {
            return;
        }

        $event = StreamEvent::blockDelta($this->requestId, $blockType, $blockIndex, $content);

        foreach ($this->blockDeltaCallbacks as $callback) {
            $callback($event);
        }
    }

    /**
     * Dispatch a block_stop event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockStop(BlockType $blockType, int $blockIndex): void
    {
        if ($this->cancelled) {
            return;
        }

        $event = StreamEvent::blockStop($this->requestId, $blockType, $blockIndex);

        foreach ($this->blockStopCallbacks as $callback) {
            $callback($event);
        }
    }

    /**
     * Dispatch a tool_call event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchToolCall(string $toolName, array $params, string $callId): void
    {
        if ($this->cancelled) {
            return;
        }

        foreach ($this->toolCallCallbacks as $callback) {
            $callback($toolName, $params, $callId);
        }
    }

    /**
     * Dispatch a done event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchDone(?array $usage = null): void
    {
        foreach ($this->doneCallbacks as $callback) {
            $callback($usage);
        }
    }

    /**
     * Dispatch an error event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchError(string $code, string $message): void
    {
        foreach ($this->errorCallbacks as $callback) {
            $callback($code, $message);
        }
    }

    /**
     * Dispatch a raw StreamEvent. Routes to the appropriate typed dispatch method.
     *
     * @internal Useful for providers that produce StreamEvent objects directly.
     */
    public function dispatchEvent(StreamEvent $event): void
    {
        match ($event->event) {
            'block_start' => $this->dispatchBlockStart(
                BlockType::from($event->data['block_type']),
                $event->data['block_index'],
            ),
            'block_delta' => $this->dispatchBlockDelta(
                BlockType::from($event->data['block_type']),
                $event->data['block_index'],
                $event->data['content'] ?? '',
            ),
            'block_stop' => $this->dispatchBlockStop(
                BlockType::from($event->data['block_type']),
                $event->data['block_index'],
            ),
            'tool_call' => $this->dispatchToolCall(
                $event->data['tool_name'],
                $event->data['parameters'] ?? [],
                $event->data['call_id'] ?? '',
            ),
            'done' => $this->dispatchDone($event->data['usage'] ?? null),
            'error' => $this->dispatchError(
                $event->data['code'] ?? 'unknown',
                $event->data['message'] ?? 'Unknown error',
            ),
            default => null, // Ignore unknown event types
        };
    }
}
