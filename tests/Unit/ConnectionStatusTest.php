<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Models\Connection;

uses(RefreshDatabase::class);

it('reports a bridge connection live from the server status, refreshing the cache', function () {
    Http::fake(['*/api/status' => Http::response([
        'connected' => true,
        'connected_at' => now()->timestamp,
        'providers' => [['name' => 'claude', 'models' => [['id' => 'sonnet']]]],
    ])]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'box',
        'connection_key' => 'key-1',
        'last_providers' => [],
        'last_connected_at' => now()->subDay(),
    ]);

    $status = app(ConnectionStatus::class)->for($connection);

    expect($status['connected'])->toBeTrue();
    expect($status['providers'][0]['name'])->toBe('claude');
    // Cache write-through: capabilities + last_connected_at refreshed.
    expect($connection->fresh()->last_connected_at->isToday())->toBeTrue();
});

it('keeps the cached capabilities when a reachable poll reports disconnected', function () {
    // /api/status omits providers/connected_at while no CLI is attached — a successful
    // poll must not erase the cached snapshot.
    Http::fake(['*/api/status' => Http::response(['connected' => false])]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'box',
        'connection_key' => 'key-1',
        'last_providers' => [['name' => 'claude', 'models' => [['id' => 'sonnet']]]],
        'last_connected_at' => now()->subDay(),
    ]);

    $status = app(ConnectionStatus::class)->for($connection);

    expect($status['connected'])->toBeFalse();
    expect($status['providers'][0]['name'])->toBe('claude');                // not erased
    expect($connection->fresh()->last_providers[0]['name'])->toBe('claude'); // cache preserved
});

it('reports a bridge offline (cached providers) when the server is unreachable', function () {
    Http::fake(['*/api/status' => Http::response('', 500)]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'box',
        'connection_key' => 'key-1',
        'last_providers' => [['name' => 'claude', 'models' => [['id' => 'sonnet']]]],
        'last_connected_at' => now()->subDay(),
    ]);

    $status = app(ConnectionStatus::class)->for($connection);

    expect($status['connected'])->toBeFalse();
    expect($status['providers'][0]['name'])->toBe('claude');   // falls back to cache
});

it('treats a bridge with no connection key as offline without calling the server', function () {
    Http::fake();

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'unpaired',
        'connection_key' => null,
        'last_providers' => [],
    ]);

    expect(app(ConnectionStatus::class)->isConnected($connection))->toBeFalse();
    Http::assertNothingSent();
});

it('treats a BYOK connection as always connected with config-derived providers', function () {
    config([
        'ai-bridge.chat_completions.model' => 'gpt-5.5',
        'ai-bridge.chat_completions.allowed_models' => ['gpt-5.5', 'gpt-5.4'],
    ]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BYOK,
        'name' => 'my key',
    ]);

    $status = app(ConnectionStatus::class)->for($connection);

    expect($status['connected'])->toBeTrue();
    expect($status['providers'][0]['name'])->toBe('chat_completions');
    expect(collect($status['providers'][0]['models'])->pluck('id')->all())->toBe(['gpt-5.5', 'gpt-5.4']);
});

test('a successful-but-disconnected poll keeps the cached workspaces', function () {
    $connection = Connection::create([
        'type' => 'bridge',
        'name' => 'laptop',
        'connection_key' => 'key-1',
        'last_workspaces' => [['path' => '/repos', 'label' => 'Repos']],
    ]);

    Http::fake(['*/api/status' => Http::response(['connected' => false], 200)]);

    $status = app(ConnectionStatus::class)->for($connection);

    expect($status['workspaces'])->toBe([['path' => '/repos', 'label' => 'Repos']]);
    expect($connection->fresh()->last_workspaces)->toBe([['path' => '/repos', 'label' => 'Repos']]);
});

test('a serve process that does not report workspaces does not blank the cache', function () {
    // The rolling-deploy case: this package is updated, `ai-bridge:serve` has
    // not been restarted, so /api/status has no `workspaces` key at all.
    // Absent is not empty — treating it as empty would erase the picker's data
    // on every poll for the whole of the deploy.
    $connection = Connection::create([
        'type' => 'bridge',
        'name' => 'laptop',
        'connection_key' => 'key-1',
        'last_workspaces' => [['path' => '/repos', 'label' => 'Repos']],
    ]);

    Http::fake(['*/api/status' => Http::response([
        'connected' => true,
        'providers' => [['name' => 'claude', 'available' => true]],
    ], 200)]);

    $status = app(ConnectionStatus::class)->for($connection);

    expect($status['workspaces'])->toBe([['path' => '/repos', 'label' => 'Repos']]);
    expect($connection->fresh()->last_workspaces)->toBe([['path' => '/repos', 'label' => 'Repos']]);
});

test('a connected poll adopts the workspaces the bridge now advertises', function () {
    $connection = Connection::create([
        'type' => 'bridge',
        'name' => 'laptop',
        'connection_key' => 'key-1',
        'last_workspaces' => [['path' => '/old', 'label' => 'Old']],
    ]);

    Http::fake(['*/api/status' => Http::response([
        'connected' => true,
        'providers' => [],
        'workspaces' => [['path' => '/repos', 'label' => 'Repos']],
    ], 200)]);

    expect(app(ConnectionStatus::class)->for($connection)['workspaces'])
        ->toBe([['path' => '/repos', 'label' => 'Repos']]);
});

test('a BYOK connection reports no workspaces, having no machine to work on', function () {
    $connection = Connection::create(['type' => Connection::TYPE_BYOK, 'name' => 'openai']);

    expect(app(ConnectionStatus::class)->for($connection)['workspaces'])->toBe([]);
});
