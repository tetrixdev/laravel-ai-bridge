<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Tetrix\AiBridge\Models\Connection;

/**
 * Fired after `ai-bridge:prune-unused` deleted a bridge connection that was never connected.
 *
 * The counterpart of ConnectionDeleted for a removal with no HTTP request behind it.
 * Dispatched synchronously after the row is deleted; the instance still holds its attributes.
 */
class ConnectionPruned
{
    use Dispatchable;

    public function __construct(
        public readonly Connection $connection,
    ) {}
}
