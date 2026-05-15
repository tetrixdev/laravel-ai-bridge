<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Events\StreamCompleted;
use Tetrix\AiBridge\Protocol\MessageTypes;
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

    /** @var Closure[] */
    private array $cancelledCallbacks = [];

    /** @var Closure[] */
    private array $toolResultCallbacks = [];

    private bool $cancelled = false;

    /**
     * Whether a terminal event (done or error) has already been dispatched.
     * Prevents double-dispatch if both done and error fire.
     */
    private bool $terminated = false;

    /** The resolved provider mode, for accurate StreamCompleted reporting. */
    private ?ProviderMode $mode = null;

    /** Track the start time for duration reporting in StreamCompleted. */
    private float $startedAt = 0;

    /** The conversation ID, set when start() is called for StreamCompleted dispatch. */
    private string $conversationId = '';

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
     * Register a callback for when the stream is cancelled.
     *
     * The callback receives: string $reason.
     */
    public function onCancelled(Closure $callback): static
    {
        $this->cancelledCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback for tool_result events from the bridge.
     *
     * The callback receives: string $toolCallId, mixed $result.
     */
    public function onToolResult(Closure $callback): static
    {
        $this->toolResultCallbacks[] = $callback;

        return $this;
    }

    /**
     * Start the stream. This method blocks until the stream completes, errors, or is cancelled.
     */
    public function start(): void
    {
        $this->cancelled = false;
        $this->terminated = false;
        $this->startedAt = microtime(true);
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

    /**
     * Set the conversation ID (used for StreamCompleted event dispatch).
     *
     * @internal Set by the provider or manager.
     */
    public function setConversationId(string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    /**
     * Set the provider mode (used for StreamCompleted event dispatch).
     *
     * @internal Set by AiBridgeManager after mode resolution.
     */
    public function setMode(ProviderMode $mode): void
    {
        $this->mode = $mode;
    }

    // ── Internal dispatch methods (called by provider implementations) ──

    /**
     * Dispatch a block_start event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockStart(BlockType $blockType, int $blockIndex): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $event = StreamEvent::blockStart($this->requestId, $blockType, $blockIndex);

        foreach ($this->blockStartCallbacks as $callback) {
            try {
                $callback($event);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in blockStart callback', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch a block_delta event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockDelta(BlockType $blockType, int $blockIndex, string $content): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $event = StreamEvent::blockDelta($this->requestId, $blockType, $blockIndex, $content);

        foreach ($this->blockDeltaCallbacks as $callback) {
            try {
                $callback($event);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in blockDelta callback', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch a block_stop event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchBlockStop(BlockType $blockType, int $blockIndex): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        $event = StreamEvent::blockStop($this->requestId, $blockType, $blockIndex);

        foreach ($this->blockStopCallbacks as $callback) {
            try {
                $callback($event);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in blockStop callback', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch a tool_call event to all registered callbacks.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchToolCall(string $toolName, array $params, string $callId): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        foreach ($this->toolCallCallbacks as $callback) {
            try {
                $callback($toolName, $params, $callId);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in toolCall callback', [
                    'request_id' => $this->requestId,
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch a done event to all registered callbacks.
     *
     * Also dispatches the StreamCompleted Laravel event for logging/analytics.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchDone(?array $usage = null): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }
        $this->terminated = true;

        foreach ($this->doneCallbacks as $callback) {
            try {
                $callback($usage);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in done callback', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->dispatchStreamCompleted(true, $usage);

        $this->provider->markCompleted();
    }

    /**
     * Dispatch an error event to all registered callbacks.
     *
     * Also dispatches the StreamCompleted Laravel event (with success=false).
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchError(string $code, string $message): void
    {
        if ($this->terminated) {
            return;
        }
        $this->terminated = true;

        foreach ($this->errorCallbacks as $callback) {
            try {
                $callback($code, $message);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in error callback', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->dispatchStreamCompleted(false, null, "{$code}: {$message}");

        $this->provider->markCompleted();
    }

    /**
     * Dispatch a tool_result event to all registered callbacks.
     *
     * @internal Called when the bridge acknowledges receipt of a tool result.
     */
    public function dispatchToolResult(string $toolCallId, mixed $result): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        Log::debug('AI Bridge: tool_result received', [
            'request_id' => $this->requestId,
            'tool_call_id' => $toolCallId,
        ]);

        foreach ($this->toolResultCallbacks as $callback) {
            try {
                $callback($toolCallId, $result);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in toolResult callback', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch a cancelled event to all registered callbacks.
     *
     * Unlike error, this emits event type 'cancelled' so consumers can
     * differentiate between errors and intentional cancellation.
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchCancelled(string $reason = 'Request was cancelled.'): void
    {
        if ($this->terminated) {
            return;
        }
        $this->terminated = true;

        foreach ($this->cancelledCallbacks as $callback) {
            try {
                $callback($reason);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: exception in cancelled callback', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->dispatchStreamCompleted(false, null, "cancelled: {$reason}");

        $this->provider->markCompleted();
    }

    /**
     * Dispatch a raw StreamEvent. Routes to the appropriate typed dispatch method.
     *
     * @internal Useful for providers that produce StreamEvent objects directly.
     */
    public function dispatchEvent(StreamEvent $event): void
    {
        match ($event->event) {
            MessageTypes::BLOCK_START => $this->dispatchBlockStartFromEvent($event),
            MessageTypes::BLOCK_DELTA => $this->dispatchBlockDeltaFromEvent($event),
            MessageTypes::BLOCK_STOP => $this->dispatchBlockStopFromEvent($event),
            MessageTypes::TOOL_CALL => $this->dispatchToolCall(
                $event->data['tool_name'],
                $event->data['parameters'] ?? [],
                $event->data['call_id'] ?? $event->data['tool_call_id'] ?? '',
            ),
            MessageTypes::TOOL_RESULT => $this->dispatchToolResult(
                $event->data['tool_call_id'] ?? $event->data['call_id'] ?? '',
                $event->data['result'] ?? null,
            ),
            MessageTypes::DONE => $this->dispatchDone($event->data['usage'] ?? null),
            MessageTypes::ERROR => $this->dispatchError(
                $event->data['code'] ?? 'unknown',
                $event->data['message'] ?? 'Unknown error',
            ),
            default => null, // Ignore unknown event types
        };
    }

    /**
     * Validate block_type and dispatch block_start from a raw StreamEvent.
     */
    private function dispatchBlockStartFromEvent(StreamEvent $event): void
    {
        $blockType = BlockType::tryFrom($event->data['block_type'] ?? '');
        if ($blockType === null) {
            Log::warning('AI Bridge: unknown block_type in block_start, skipping', [
                'request_id' => $this->requestId,
                'block_type' => $event->data['block_type'] ?? '',
            ]);

            return;
        }
        $this->dispatchBlockStart($blockType, $event->data['block_index']);
    }

    /**
     * Validate block_type and dispatch block_delta from a raw StreamEvent.
     */
    private function dispatchBlockDeltaFromEvent(StreamEvent $event): void
    {
        $blockType = BlockType::tryFrom($event->data['block_type'] ?? '');
        if ($blockType === null) {
            return; // Silently skip — warning already logged on block_start
        }
        $this->dispatchBlockDelta($blockType, $event->data['block_index'], $event->data['content'] ?? '');
    }

    /**
     * Validate block_type and dispatch block_stop from a raw StreamEvent.
     */
    private function dispatchBlockStopFromEvent(StreamEvent $event): void
    {
        $blockType = BlockType::tryFrom($event->data['block_type'] ?? '');
        if ($blockType === null) {
            return; // Silently skip — warning already logged on block_start
        }
        $this->dispatchBlockStop($blockType, $event->data['block_index']);
    }

    /**
     * Dispatch the StreamCompleted Laravel event for logging/analytics.
     */
    private function dispatchStreamCompleted(bool $success, ?array $usage = null, ?string $error = null): void
    {
        $durationMs = $this->startedAt > 0
            ? (int) ((microtime(true) - $this->startedAt) * 1000)
            : null;

        if ($this->mode === null) {
            Log::warning('AI Bridge: StreamHandler mode not set, defaulting to Byok. Call setMode() before start().');
        }
        $mode = $this->mode ?? ProviderMode::Byok;

        try {
            event(new StreamCompleted(
                conversationId: $this->conversationId,
                requestId: $this->requestId,
                mode: $mode,
                success: $success,
                usage: $usage,
                error: $error,
                durationMs: $durationMs,
            ));
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to dispatch StreamCompleted event', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
