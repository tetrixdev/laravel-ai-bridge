<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tetrix\AiBridge\Models\Connection;

/**
 * Fired after a connection is created through the package HTTP API.
 *
 * Consuming apps listen for this to link the new (unlinked) connection to
 * their own owner/session model.
 *
 * Dispatched synchronously; the $request is the live request that created it.
 */
class ConnectionCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Connection $connection,
        public readonly Request $request,
    ) {}
}
