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
    protected $signature = 'ai-bridge:token {--user-id=1 : User ID to generate token for} {--ttl= : Token TTL in seconds (default: from config ai-bridge.token.ttl)}';

    protected $description = 'Generate a JWT connection token for AI Bridge testing';

    public function handle(TokenManager $manager): int
    {
        $ttlOption = $this->option('ttl');
        // When --ttl is explicitly provided, pass it through verbatim (including 0)
        // so generate()'s guard can reject invalid values.
        $ttl = $ttlOption !== null ? (int) $ttlOption : null;

        try {
            $token = $manager->generate($this->option('user-id'), [], $ttl);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->newLine();
            $this->line('Make sure AI_BRIDGE_TOKEN_SECRET is set in your .env file.');

            return 1;
        }

        $port = (int) config('ai-bridge.server.port', 8085);

        $effectiveTtl = $ttl !== null ? $ttl : (int) config('ai-bridge.token.ttl', 86400);
        // Format in UTC so the timestamp matches the 'UTC' label regardless of
        // the application's configured timezone.
        $expiresAt = now()->addSeconds($effectiveTtl)->utc()->toDateTimeString().' UTC';
        $this->info('Token generated (TTL: '.$effectiveTtl.'s, expires at '.$expiresAt.'):');
        $this->line($token);
        $this->newLine();
        $this->info('Use with bridge:');
        $this->line("npx @tetrixdev/ai-bridge --server=ws://localhost:{$port} --token={$token}");

        return 0;
    }
}
