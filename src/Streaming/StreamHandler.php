<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Enums\TerminatedBy;
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

    /** @var Closure[] */
    private array $attachmentCallbacks = [];

    private bool $cancelled = false;

    /**
     * Block type for each open block, keyed by block_index.
     *
     * Per PROTOCOL.md only `block_start` carries `block_type`; `block_delta`
     * and `block_stop` identify their block by `block_index` alone. We record
     * each block's type from its block_start so deltas/stops can be resolved.
     *
     * @var array<int, BlockType>
     */
    private array $blockTypes = [];

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

    /**
     * Per-request memo of the conversation's tool allowlist (null = all tools),
     * resolved once on the first tool call so the runtime guard doesn't re-query
     * the DB for every tool call on the shared event loop. See `cacheAllowedTools`.
     */
    private bool $allowedToolsResolved = false;

    /** @var list<string>|null */
    private ?array $allowedTools = null;

    public readonly string $requestId;

    public function __construct(
        private readonly StreamableProvider $provider,
        ?string $requestId = null,
    ) {
        $this->requestId = $requestId ?? Str::uuid()->toString();
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
     * Register a callback for attachment events — a file the assistant sent back.
     *
     * The callback receives the event's data array: `id`, `name`, `mime_type`,
     * `size`, and `description` when the model gave one. The `id` is the one
     * the app's own `storeAttachmentUsing()` returned, so the UI can render
     * the file straight from the app's attachment store.
     */
    public function onAttachment(Closure $callback): static
    {
        $this->attachmentCallbacks[] = $callback;

        return $this;
    }

    /**
     * Start the stream. This method blocks until the stream completes, errors, or is cancelled.
     */
    public function start(): void
    {
        $this->cancelled = false;
        $this->terminated = false;
        $this->blockTypes = [];
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
     * Get the conversation ID this stream belongs to ('' if unset).
     *
     * Used server-side to persist the CLI session id from a `done` event and
     * to rebuild the request when recovering a lost session.
     */
    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    /** Whether this request's tool allowlist has been resolved yet (see cacheAllowedTools). */
    public function hasResolvedAllowedTools(): bool
    {
        return $this->allowedToolsResolved;
    }

    /**
     * Memoize this request's tool allowlist (null = all tools). Set once per
     * request so the runtime tool guard resolves the conversation's allowlist a
     * single time rather than re-querying the DB on every tool call.
     *
     * @param  list<string>|null  $allowedTools
     */
    public function cacheAllowedTools(?array $allowedTools): void
    {
        $this->allowedTools = $allowedTools;
        $this->allowedToolsResolved = true;
    }

    /** @return list<string>|null */
    public function getAllowedTools(): ?array
    {
        return $this->allowedTools;
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
        $this->dispatchCallbacks($this->blockStartCallbacks, [$event], 'blockStart');
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
        $this->dispatchCallbacks($this->blockDeltaCallbacks, [$event], 'blockDelta');
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
        $this->dispatchCallbacks($this->blockStopCallbacks, [$event], 'blockStop');
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

        $this->dispatchCallbacks($this->toolCallCallbacks, [$toolName, $params, $callId], 'toolCall');
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

        $this->dispatchCallbacks($this->doneCallbacks, [$usage], 'done');

        $this->dispatchStreamCompleted(true, $usage, null, TerminatedBy::Success);

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

        $this->dispatchCallbacks($this->errorCallbacks, [$code, $message], 'error');

        $this->dispatchStreamCompleted(false, null, "{$code}: {$message}", TerminatedBy::Error);

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

        $this->dispatchCallbacks($this->toolResultCallbacks, [$toolCallId, $result], 'toolResult');
    }

    /**
     * Dispatch an attachment event to all registered callbacks.
     *
     * Not terminal: a `done` still follows, and this must not end the turn.
     *
     * It IS dropped after a cancel or a terminal event, like every other
     * non-terminal event — and that is a known rough edge rather than a
     * decision. The file is already stored server-side by the time this
     * arrives, so a turn cancelled in the window between the upload and the
     * event leaves an attachment in the app's store that nothing links to. In
     * practice the envelope rarely gets this far: an aborted turn has already
     * had its pending request removed, so the event is dropped upstream in
     * MessageHandler as belonging to an unknown request. Worth cleaning up
     * with an orphan sweep on the app side if it ever matters.
     *
     * @param  array<string, mixed>  $attachment
     *
     * @internal Called by StreamableProvider implementations.
     */
    public function dispatchAttachment(array $attachment): void
    {
        if ($this->cancelled || $this->terminated) {
            return;
        }

        Log::debug('AI Bridge: attachment received', [
            'request_id' => $this->requestId,
            'attachment_id' => $attachment['id'] ?? null,
        ]);

        $this->dispatchCallbacks($this->attachmentCallbacks, [$attachment], 'attachment');
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

        $this->dispatchCallbacks($this->cancelledCallbacks, [$reason], 'cancelled');

        $this->dispatchStreamCompleted(false, null, "cancelled: {$reason}", TerminatedBy::Cancelled);

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
                $event->data['tool_call_id'] ?? $event->data['call_id'] ?? '',
            ),
            MessageTypes::TOOL_RESULT => $this->dispatchToolResult(
                $event->data['tool_call_id'] ?? $event->data['call_id'] ?? '',
                $event->data['result'] ?? null,
            ),
            MessageTypes::ATTACHMENT => $this->dispatchAttachment($event->data),
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
        $blockIndex = (int) ($event->data['block_index'] ?? 0);
        $this->blockTypes[$blockIndex] = $blockType;
        $this->dispatchBlockStart($blockType, $blockIndex);
    }

    /**
     * Dispatch block_delta from a raw StreamEvent.
     *
     * block_delta events carry only `block_index` + `content` (PROTOCOL.md),
     * so the type is resolved from the matching block_start via resolveBlockType().
     */
    private function dispatchBlockDeltaFromEvent(StreamEvent $event): void
    {
        $blockIndex = (int) ($event->data['block_index'] ?? 0);
        $this->dispatchBlockDelta(
            $this->resolveBlockType($event, $blockIndex),
            $blockIndex,
            $event->data['content'] ?? '',
        );
    }

    /**
     * Dispatch block_stop from a raw StreamEvent.
     *
     * Like block_delta, block_stop carries only `block_index` — the type is
     * resolved from the matching block_start.
     */
    private function dispatchBlockStopFromEvent(StreamEvent $event): void
    {
        $blockIndex = (int) ($event->data['block_index'] ?? 0);
        $this->dispatchBlockStop($this->resolveBlockType($event, $blockIndex), $blockIndex);
    }

    /**
     * Resolve the block type for a block_delta / block_stop event.
     *
     * An explicit `block_type` on the event wins (legacy / BYOK providers that
     * include it); otherwise fall back to the type recorded from this block's
     * block_start, then to Text as a last resort.
     */
    private function resolveBlockType(StreamEvent $event, int $blockIndex): BlockType
    {
        return BlockType::tryFrom($event->data['block_type'] ?? '')
            ?? $this->blockTypes[$blockIndex]
            ?? BlockType::Text;
    }

    /**
     * Dispatch a set of callbacks with the given arguments.
     *
     * Centralizes the try/catch/Log::error pattern across all dispatch methods.
     * Each callback is invoked with the given $args; any Throwable is caught and
     * logged with the callback type name.
     *
     * @param  Closure[]  $callbacks  The array of callbacks to invoke.
     * @param  array<mixed>  $args    Arguments to pass to each callback.
     * @param  string  $callbackType  Human-readable type name for log messages.
     */
    private function dispatchCallbacks(array $callbacks, array $args, string $callbackType): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                Log::error("AI Bridge: exception in {$callbackType} callback", [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Dispatch the StreamCompleted Laravel event for logging/analytics.
     *
     * The $terminatedBy parameter lets event listeners distinguish cancellations
     * from errors without fragile string parsing.
     */
    private function dispatchStreamCompleted(bool $success, ?array $usage = null, ?string $error = null, TerminatedBy $terminatedBy = TerminatedBy::Success): void
    {
        $durationMs = $this->startedAt > 0
            ? (int) ((microtime(true) - $this->startedAt) * 1000)
            : null;

        if ($this->mode === null) {
            Log::warning('AI Bridge: StreamHandler mode not set, defaulting to Byok. Call setMode() before start().');
        }
        $mode = $this->mode ?? ProviderMode::Byok;

        try {
            Event::dispatch(new StreamCompleted(
                conversationId: $this->conversationId,
                requestId: $this->requestId,
                mode: $mode,
                success: $success,
                usage: $usage,
                error: $error,
                durationMs: $durationMs,
                terminatedBy: $terminatedBy,
            ));
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to dispatch StreamCompleted event', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
