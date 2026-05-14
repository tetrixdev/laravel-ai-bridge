<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Console;

use Illuminate\Console\Command;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Server\BridgeWebSocketServer;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * Artisan command to start the dedicated WebSocket server for CLI bridge connections.
 *
 * This starts a Ratchet/ReactPHP WebSocket server on a configurable port (default 8085).
 * Bridge clients (npx @tetrixdev/ai-bridge) connect to this server.
 *
 * Usage:
 *   php artisan ai-bridge:serve
 *   php artisan ai-bridge:serve --port=9090
 *   php artisan ai-bridge:serve --host=127.0.0.1 --port=8085
 */
class ServeCommand extends Command
{
    protected $signature = 'ai-bridge:serve
        {--host= : Bind address (default: from config or 0.0.0.0)}
        {--port= : Port number (default: from config or 8085)}';

    protected $description = 'Start the AI Bridge WebSocket server for CLI bridge connections';

    public function handle(
        BridgeConnectionManager $connectionManager,
        MessageHandler $messageHandler,
        TokenManager $tokenManager,
    ): int {
        $host = $this->option('host') ?? config('ai-bridge.server.host', '0.0.0.0');
        $port = (int) ($this->option('port') ?? config('ai-bridge.server.port', 8085));

        $server = new BridgeWebSocketServer(
            $connectionManager,
            $messageHandler,
            $tokenManager,
            $host,
            $port,
        );

        // Handle SIGINT/SIGTERM for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($server) {
                $this->newLine();
                $this->info('Shutting down AI Bridge server...');
                $server->stop();
            });

            pcntl_signal(SIGTERM, function () use ($server) {
                $this->newLine();
                $this->info('Shutting down AI Bridge server...');
                $server->stop();
            });
        }

        $this->showBanner($host, $port);

        $server->start(function () {
            $this->info('Server is ready and listening for connections.');
            $this->newLine();
        });

        $this->info('Server stopped.');

        return 0;
    }

    /**
     * Display the server startup banner.
     */
    private function showBanner(string $host, int $port): void
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>AI Bridge WebSocket Server</>');
        $this->line('  ========================');
        $this->newLine();
        $this->line("  Host:    <fg=green>{$host}</>");
        $this->line("  Port:    <fg=green>{$port}</>");
        $this->line("  URL:     <fg=green>ws://{$host}:{$port}</>");
        $this->newLine();
        $this->line('  Bridge clients can connect with:');
        $this->line("  <fg=yellow>npx @tetrixdev/ai-bridge --server=ws://{$host}:{$port} --token=<JWT></>");
        $this->newLine();
        $this->line('  Generate a token with:');
        $this->line('  <fg=yellow>php artisan ai-bridge:token</> ');
        $this->newLine();
        $this->line('  Press <fg=red>Ctrl+C</> to stop the server.');
        $this->newLine();
    }
}
