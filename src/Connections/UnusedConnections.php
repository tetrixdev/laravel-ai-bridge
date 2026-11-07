<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Connections;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Events\ConnectionPruned;
use Tetrix\AiBridge\Models\Connection;

/**
 * Finds and removes CLI bridge connections that were registered but never used.
 *
 * Registering a bridge connection hands out a long-lived token (`cid` claim). When nobody
 * ever starts a bridge with it, the row and its token linger: a token pasted somewhere it
 * should not be stays valid for as long as the row exists. Deleting the row revokes it,
 * because the WebSocket handshake rejects a token whose `cid` no longer exists.
 *
 * A connection is "unused" when ALL of these hold:
 *  - it is a bridge connection (BYOK rows never "connect", so they are never pruned);
 *  - `last_connected_at` is null: no bridge ever completed a handshake with it
 *    (the bridge server stamps it on every handshake, ConnectionStatus on every live poll);
 *  - it is older than the threshold (default 24 hours);
 *  - no conversation uses it;
 *  - the consuming app does not protect it (AiBridge::protectConnectionsUsing());
 *  - the bridge server does not report it connected right now.
 */
class UnusedConnections
{
    public function __construct(
        private readonly AiBridgeManager $manager,
        private readonly ConnectionStatus $status,
    ) {}

    /**
     * Bridge rows never connected, older than $hours, with no conversation.
     *
     * @return Builder<Connection>
     */
    public function candidates(int $hours): Builder
    {
        return Connection::query()
            ->where('type', Connection::TYPE_BRIDGE)
            ->whereNull('last_connected_at')
            ->where('created_at', '<=', now()->subHours($hours))
            ->whereDoesntHave('conversations')
            ->orderBy('id');
    }

    /**
     * Prune (or, with $dryRun, only report) every unused connection.
     *
     * @return list<array{id: int, name: string|null, created_at: string|null, result: string}>
     *         result is one of: deleted, would_delete, kept_in_use, kept_connected
     */
    public function prune(int $hours, bool $dryRun = false): array
    {
        $report = [];

        foreach ($this->candidates($hours)->get() as $connection) {
            $row = [
                'id' => (int) $connection->id,
                'name' => $connection->name,
                'created_at' => $connection->created_at?->toIso8601String(),
            ];

            if ($this->manager->connectionIsProtected($connection)) {
                $report[] = $row + ['result' => 'kept_in_use'];

                continue;
            }

            // Never trust the null stamp alone: a bridge attached before the server
            // stamped handshakes would read as unused. A live poll also stamps it.
            if ($this->status->isConnected($connection)) {
                $report[] = $row + ['result' => 'kept_connected'];

                continue;
            }

            if ($dryRun) {
                $report[] = $row + ['result' => 'would_delete'];

                continue;
            }

            $connection->delete();

            Log::info('AI Bridge: pruned a bridge connection that was never connected', [
                'connection_id' => $row['id'],
                'name' => $row['name'],
                'created_at' => $row['created_at'],
                'unused_after_hours' => $hours,
            ]);

            Event::dispatch(new ConnectionPruned($connection));

            $report[] = $row + ['result' => 'deleted'];
        }

        return $report;
    }
}
