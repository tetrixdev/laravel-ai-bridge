<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The migrations, on PostgreSQL
|--------------------------------------------------------------------------
|
| The rest of the suite runs on SQLite, which will accept a good deal that
| PostgreSQL will not — an unconditional MySQL collation being the classic
| example, and one that leaves `migrate` dead half way with the schema part
| built. Consumers run this package on PostgreSQL, so the migrations get
| exercised against a real server rather than inferred from the fact that
| SQLite did not complain.
|
| Skipped unless a server is configured, so `composer test` stays offline:
|
|   AI_BRIDGE_TEST_PGSQL_HOST=127.0.0.1 vendor/bin/pest
|
| CI sets it on one leg. See .github/workflows/ci.yml.
|
*/

function pgHost(): ?string
{
    return getenv('AI_BRIDGE_TEST_PGSQL_HOST') ?: null;
}

beforeEach(function () {
    if (pgHost() === null) {
        test()->markTestSkipped('AI_BRIDGE_TEST_PGSQL_HOST is not set.');
    }

    config([
        'database.default' => 'pgsql_probe',
        'database.connections.pgsql_probe' => [
            'driver' => 'pgsql',
            'host' => pgHost(),
            'port' => (int) (getenv('AI_BRIDGE_TEST_PGSQL_PORT') ?: 5432),
            'database' => getenv('AI_BRIDGE_TEST_PGSQL_DB') ?: 'aibridge',
            'username' => getenv('AI_BRIDGE_TEST_PGSQL_USER') ?: 'postgres',
            'password' => getenv('AI_BRIDGE_TEST_PGSQL_PASSWORD') ?: 'probe',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ]);
});

it('creates every table and column the package needs', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);

    expect(Schema::hasTable('ai_bridge_connections'))->toBeTrue()
        ->and(Schema::hasTable('ai_bridge_conversations'))->toBeTrue()
        ->and(Schema::hasTable('ai_bridge_messages'))->toBeTrue();

    // The JSON columns, which are the ones a driver is most likely to differ on.
    expect(Schema::hasColumn('ai_bridge_connections', 'last_providers'))->toBeTrue()
        ->and(Schema::hasColumn('ai_bridge_connections', 'last_workspaces'))->toBeTrue()
        ->and(Schema::hasColumn('ai_bridge_conversations', 'allowed_tools'))->toBeTrue()
        ->and(Schema::hasColumn('ai_bridge_conversations', 'working_dir'))->toBeTrue()
        ->and(Schema::hasColumn('ai_bridge_messages', 'blocks'))->toBeTrue();
});

it('rolls back cleanly, which nothing else exercises', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    Artisan::call('migrate:rollback', ['--force' => true, '--step' => 100]);

    expect(Schema::hasTable('ai_bridge_connections'))->toBeFalse()
        ->and(Schema::hasTable('ai_bridge_conversations'))->toBeFalse()
        ->and(Schema::hasTable('ai_bridge_messages'))->toBeFalse();
});

it('round-trips a JSON column through the real driver', function () {
    // `->json()` is portable in the schema builder, but the cast on the way
    // back out is where a driver difference would actually surface.
    Artisan::call('migrate:fresh', ['--force' => true]);

    $connection = \Tetrix\AiBridge\Models\Connection::create([
        'type' => 'bridge',
        'name' => 'laptop',
        'connection_key' => 'key-1',
        'last_workspaces' => [['path' => '/repos/studio', 'label' => 'Studio']],
    ]);

    expect($connection->fresh()->last_workspaces)
        ->toBe([['path' => '/repos/studio', 'label' => 'Studio']]);
});
