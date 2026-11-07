<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Events\ConnectionPruned;
use Tetrix\AiBridge\Facades\AiBridge;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

uses(RefreshDatabase::class);

function bridgeRow(string $key, int $ageHours, ?string $lastConnected = null, string $type = Connection::TYPE_BRIDGE): Connection
{
    $c = Connection::create([
        'type' => $type,
        'name' => $key,
        'connection_key' => $type === Connection::TYPE_BRIDGE ? $key : null,
        'endpoint' => $type === Connection::TYPE_BYOK ? 'https://example.test/v1' : null,
        'last_connected_at' => $lastConnected,
    ]);
    $c->forceFill(['created_at' => now()->subHours($ageHours)])->save();

    return $c;
}

beforeEach(function () {
    // The bridge server reports nothing attached, unless a test says otherwise.
    $this->bridgeLive = false;
    Http::fake(['*/api/status' => fn () => Http::response(['connected' => $this->bridgeLive, 'providers' => []])]);
});

it('deletes a never-connected bridge older than the threshold and keeps the rest', function () {
    Event::fake([ConnectionPruned::class]);

    $stale = bridgeRow('stale', 48);
    $young = bridgeRow('young', 2);
    $used = bridgeRow('used', 48, now()->subDays(3)->toDateTimeString());
    $byok = bridgeRow('byok', 48, null, Connection::TYPE_BYOK);
    $withConversation = bridgeRow('talked', 48);
    Conversation::create(['connection_id' => $withConversation->id, 'mode' => 'bridge', 'title' => 't']);

    $this->artisan('ai-bridge:prune-unused')
        ->expectsOutputToContain('1 deleted')
        ->assertSuccessful();

    expect(Connection::pluck('name')->sort()->values()->all())
        ->toBe(['byok', 'talked', 'used', 'young']);
    expect(Connection::find($stale->id))->toBeNull();
    Event::assertDispatched(ConnectionPruned::class, fn ($e) => $e->connection->id === $stale->id);
});

it('deletes nothing on --dry-run', function () {
    bridgeRow('stale', 48);

    $this->artisan('ai-bridge:prune-unused', ['--dry-run' => true])
        ->expectsOutputToContain('1 would be deleted')
        ->assertSuccessful();

    expect(Connection::count())->toBe(1);
});

it('honours --hours and the config default', function () {
    bridgeRow('five-hours', 5);

    $this->artisan('ai-bridge:prune-unused')->assertSuccessful();
    expect(Connection::count())->toBe(1);

    config()->set('ai-bridge.connections.prune_unused_after_hours', 4);
    $this->artisan('ai-bridge:prune-unused', ['--dry-run' => true])
        ->expectsOutputToContain('1 would be deleted');

    $this->artisan('ai-bridge:prune-unused', ['--hours' => 6])->assertSuccessful();
    expect(Connection::count())->toBe(1);

    $this->artisan('ai-bridge:prune-unused', ['--hours' => 0])->assertFailed();
});

it('keeps a connection the app protects', function () {
    $kept = bridgeRow('pinned', 48);
    AiBridge::protectConnectionsUsing(fn (Connection $c) => $c->id === $kept->id);

    $this->artisan('ai-bridge:prune-unused')
        ->expectsOutputToContain('kept_in_use')
        ->assertSuccessful();

    expect(Connection::find($kept->id))->not->toBeNull();
});

it('keeps a connection the bridge server reports connected right now', function () {
    $this->bridgeLive = true;
    $live = bridgeRow('live', 48);

    $this->artisan('ai-bridge:prune-unused')
        ->expectsOutputToContain('kept_connected')
        ->assertSuccessful();

    expect(Connection::find($live->id))->not->toBeNull();
});

it('stamps last_connected_at when a bridge completes a handshake', function () {
    $c = bridgeRow('fresh', 48);

    app(BridgeConnectionManager::class)->addConnection('fresh', 'conn-1');

    expect($c->fresh()->last_connected_at)->not->toBeNull();
});
