<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Tetrix\AiBridge\Enums\ProviderMode;

/**
 * Dispatched when an AI response stream completes (successfully or with error).
 *
 * Consuming applications can use this for logging, billing, analytics, etc.
 *
 * SerializesModels is intentionally retained here (unlike BridgeConnected /
 * BridgeDisconnected) because StreamCompleted is the primary hook for billing
 * and analytics, which are commonly handled by queued listeners. Queued listeners
 * require SerializesModels to safely serialize and re-hydrate event properties
 * across process boundaries.
 */
class StreamCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** The conversation ID this stream belonged to. */
        public readonly string $conversationId,
        /** The unique request ID for this stream. */
        public readonly string $requestId,
        /** Which provider mode was used. */
        public readonly ProviderMode $mode,
        /** Whether the stream completed successfully. */
        public readonly bool $success,
        /** Token usage data, if available. */
        public readonly ?array $usage = null,
        /** Error message, if the stream failed. */
        public readonly ?string $error = null,
        /** Duration of the stream in milliseconds. */
        public readonly ?int $durationMs = null,
        /**
         * How the stream ended: 'success', 'error', or 'cancelled'.
         *
         * BL-012: Provides a clean, non-fragile way to distinguish cancellations
         * from errors in analytics listeners — no need to parse the error string prefix.
         */
        public readonly string $terminatedBy = 'success',
    ) {}
}
