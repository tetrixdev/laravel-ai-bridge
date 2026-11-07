<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Console;

use Illuminate\Console\Command;
use Tetrix\AiBridge\Connections\UnusedConnections;

/**
 * Delete CLI bridge connections that were registered but never connected, which also
 * revokes their tokens. See UnusedConnections for exactly what counts as unused.
 * Not scheduled by the package: the consuming app schedules it (e.g. daily).
 */
class PruneUnusedConnectionsCommand extends Command
{
    protected $signature = 'ai-bridge:prune-unused
        {--hours= : Only connections older than this many hours (default: config ai-bridge.connections.prune_unused_after_hours, 24)}
        {--dry-run : Show what would be deleted without deleting anything}';

    protected $description = 'Delete bridge connections that were never connected (revoking their tokens)';

    public function handle(UnusedConnections $unused): int
    {
        $hours = $this->option('hours');
        $hours = is_numeric($hours)
            ? (int) $hours
            : (int) config('ai-bridge.connections.prune_unused_after_hours', 24);

        if ($hours < 1) {
            $this->error('--hours must be at least 1.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = $unused->prune($hours, $dryRun);

        if ($report === []) {
            $this->info("No unused bridge connections older than {$hours} h.");

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'name', 'created_at', 'result'],
            array_map(fn (array $r) => [$r['id'], $r['name'] ?? '', $r['created_at'] ?? '', $r['result']], $report),
        );

        $counts = array_count_values(array_column($report, 'result'));
        $done = $dryRun
            ? ($counts['would_delete'] ?? 0).' would be deleted'
            : ($counts['deleted'] ?? 0).' deleted';
        $kept = ($counts['kept_in_use'] ?? 0) + ($counts['kept_connected'] ?? 0);
        $this->info(($dryRun ? '[dry run] ' : '')."{$done}, {$kept} kept (older than {$hours} h, never connected).");

        return self::SUCCESS;
    }
}
