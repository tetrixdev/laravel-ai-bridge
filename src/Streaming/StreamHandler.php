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

    private bool $cancelled = false;

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
     * Start the stream. This method blocks until the stream completes, errors, or is cancelled.
     */
    public function start(): void
    {
        $this->cancelled = false;
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
        if ($this->cancelled) {
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
        if ($this->cancelled) {
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
        if ($this->cancelled) {
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

        // Mark BridgeStream as completed if applicable
        if ($this->provider instanceof BridgeStream) {
            $this->provider->markCompleted();
        }
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

        // Mark BridgeStream as completed if applicable
        if ($this->provider instanceof BridgeStream) {
            $this->provider->markCompleted();
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
            MessageTypes::BLOCK_START => (function () use ($event) {
                $blockType = BlockType::tryFrom($event->data['block_type'] ?? '');
                if ($blockType === null) {
                    Log::warning('AI Bridge: unknown block_type in block_start, skipping', [
                        'request_id' => $this->requestId,
                        'block_type' => $event->data['block_type'] ?? '',
                    ]);
                    return;
                }
                $this->dispatchBlockStart($blockType, $event->data['block_index']);
            })(),
            MessageTypes::BLOCK_DELTA => (function () use ($event) {
                $blockType = BlockType::tryFrom($event->data['block_type'] ?? '');
                if ($blockType === null) {
                    return; // Silently skip — warning already logged on block_start
                }
                $this->dispatchBlockDelta($blockType, $event->data['block_index'], $event->data['content'] ?? '');
            })(),
            MessageTypes::BLOCK_STOP => (function () use ($event) {
                $blockType = BlockType::tryFrom($event->data['block_type'] ?? '');
                if ($blockType === null) {
                    return; // Silently skip — warning already logged on block_start
                }
                $this->dispatchBlockStop($blockType, $event->data['block_index']);
            })(),
            MessageTypes::TOOL_CALL => $this->dispatchToolCall(
                $event->data['tool_name'],
                $event->data['parameters'] ?? [],
                $event->data['call_id'] ?? $event->data['tool_call_id'] ?? '',
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
     * Dispatch the StreamCompleted Laravel event for logging/analytics.
     */
    private function dispatchStreamCompleted(bool $success, ?array $usage = null, ?string $error = null): void
    {
        $durationMs = $this->startedAt > 0
            ? (int) ((microtime(true) - $this->startedAt) * 1000)
            : null;

        $mode = $this->provider instanceof BridgeStream
            ? ProviderMode::Bridge
            : ProviderMode::Byok;

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
