<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tetrix\AiBridge\Models\Connection;

/**
 * Fired after a connection is deleted through the package HTTP API.
 *
 * Consuming apps listen for this to perform non-database cleanup tied to the
 * connection (the package's own rows, and host pivot rows with a cascading
 * foreign key, are removed automatically).
 *
 * Dispatched synchronously, after the row is deleted; the $connection instance
 * still holds its attributes (including the now-stale id) for the listener.
 */
class ConnectionDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly Connection $connection,
        public readonly Request $request,
    ) {}
}
