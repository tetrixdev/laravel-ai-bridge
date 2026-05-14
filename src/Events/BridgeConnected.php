<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a CLI bridge establishes a WebSocket connection.
 *
 * Consuming applications can listen for this event to update UI state,
 * log connections, or trigger other side effects.
 */
class BridgeConnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** The user ID that this bridge is authenticated as. */
        public readonly int|string $userId,
        /** The unique connection ID assigned to this bridge session. */
        public readonly string $connectionId,
        /** Timestamp when the connection was established. */
        public readonly int $connectedAt,
    ) {}
}
