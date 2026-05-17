<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Tetrix\AiBridge\AiBridgeServiceProvider;

/**
 * Base test case for feature tests that need the full Laravel application.
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiBridgeServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Set a valid token secret for tests (64 chars = well above 32 minimum)
        $app['config']->set('ai-bridge.token.secret', str_repeat('a', 64));
        $app['config']->set('ai-bridge.token.ttl', 3600);
        $app['config']->set('ai-bridge.mode', 'byok');
        $app['config']->set('ai-bridge.route_middleware', ['auth']);
        $app['config']->set('ai-bridge.broadcasting.enabled', true);
        $app['config']->set('ai-bridge.chat_completions.stream_timeout', 300);

        // In-memory SQLite for tests that exercise conversation persistence.
        // The package migrations auto-load via loadMigrationsFrom(); DB-backed
        // tests opt in with the RefreshDatabase trait.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
