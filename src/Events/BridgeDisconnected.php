<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a CLI bridge WebSocket connection is closed.
 *
 * This can happen due to explicit disconnect, network failure,
 * or heartbeat timeout.
 */
class BridgeDisconnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** The user ID that this bridge was authenticated as. */
        public readonly int|string $userId,
        /** The unique connection ID of the disconnected session. */
        public readonly string $connectionId,
        /** The reason for disconnection, if known. */
        public readonly ?string $reason = null,
    ) {}
}
