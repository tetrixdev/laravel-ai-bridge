<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Console;

use Illuminate\Console\Command;
use Tetrix\AiBridge\Auth\TokenManager;

/**
 * Artisan command to generate a JWT connection token for AI Bridge testing.
 *
 * Usage:
 *   php artisan ai-bridge:token
 *   php artisan ai-bridge:token --user-id=42
 *   php artisan ai-bridge:token --user-id=42 --ttl=7200
 */
class GenerateTokenCommand extends Command
{
    protected $signature = 'ai-bridge:token {--user-id=1 : User ID to generate token for} {--ttl=3600 : Token TTL in seconds}';

    protected $description = 'Generate a JWT connection token for AI Bridge testing';

    public function handle(TokenManager $manager): int
    {
        $ttl = (int) $this->option('ttl');

        try {
            $token = $manager->generate($this->option('user-id'), [], $ttl > 0 ? $ttl : null);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->newLine();
            $this->line('Make sure AI_BRIDGE_TOKEN_SECRET is set in your .env file.');

            return 1;
        }

        $port = (int) config('ai-bridge.server.port', 8085);

        $this->info('Token generated:');
        $this->line($token);
        $this->newLine();
        $this->info('Use with bridge:');
        $this->line("npx @tetrixdev/ai-bridge --server=ws://localhost:{$port}/ai-bridge/ws --token={$token}");

        return 0;
    }
}
