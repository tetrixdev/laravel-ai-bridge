<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bridge closed a running turn's input: the turn goes on, but takes no more messages.
 *
 * Sent by bridge 0.25+ (`hello.input_closed`) as the `input_closed` stream event. From
 * here on a `turn_input` for the turn is answered `turn_ending`, so a message typed now
 * belongs to the next turn, started once this one's terminal frame arrives.
 *
 * Fired in the serve process. The same fact is written to the turn's stream metadata
 * (`input_open` => false, `input_closed_reason`), which is what
 * AiBridgeManager::inputOpen() reads, for a process that polls instead of listening; and
 * the event itself is relayed down the turn's stream as `input_closed`.
 */
class TurnInputClosed
{
    use Dispatchable;

    public function __construct(
        /** The user whose bridge runs the turn (the connection key for a managed connection). */
        public readonly int|string $userId,
        /** The turn's request id. */
        public readonly string $requestId,
        /** Why the bridge closed it: `idle` today; any other value means the same. Null when it gave none. */
        public readonly ?string $reason,
    ) {}
}
