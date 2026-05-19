<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Events\ConnectionDeleted;
use Tetrix\AiBridge\Http\Controllers\ConnectionController;
use Tetrix\AiBridge\Models\Connection;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ConnectionController API Tests
|--------------------------------------------------------------------------
|
| Covers the management endpoints introduced with the CLI-bridge management
| UI: PATCH /connections/{id}, POST /connections/{id}/regenerate, and the
| now-disconnect-aware DELETE /connections/{id}. Controllers are invoked
| directly (mirroring ConversationApiTest) so route middleware doesn't
| interfere. The bridge server's internal HTTP API is faked.
|
*/

function connectionController(): ConnectionController
{
    return app(ConnectionController::class);
}

function decodeJson($response): array
{
    return json_decode($response->getContent(), true);
}

beforeEach(function () {
    // BYOK connections encrypt their api_key via Laravel's Encrypter, which
    // requires an APP_KEY. TestCase doesn't set one (most tests don't need
    // it), so do it here so Connection::create() works for type=byok rows.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    app(AiBridgeManager::class)->resolveConnectionsUsing(fn ($request) => Connection::query());
    // Any unfaked call to the bridge server's internal API would otherwise
    // hit the network; fake by default so disconnect/status calls are inert.
    Http::fake();
});

// --- PATCH /connections/{id} — rename ---

it('renames a connection via PATCH', function () {
    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'connection_key' => 'key-1',
        'name' => 'old name',
    ]);

    $response = connectionController()->update(
        Request::create('/x', 'PATCH', ['name' => 'new name']),
        $connection->id,
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(decodeJson($response)['connection']['name'])->toBe('new name')
        ->and($connection->fresh()->name)->toBe('new name');
});

it('returns 404 when renaming an unknown connection', function () {
    $response = connectionController()->update(
        Request::create('/x', 'PATCH', ['name' => 'new']),
        999,
    );

    expect($response->getStatusCode())->toBe(404);
});

it('leaves the name untouched when PATCH omits the field', function () {
    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'connection_key' => 'key-1',
        'name' => 'keep me',
    ]);

    connectionController()->update(Request::create('/x', 'PATCH', []), $connection->id);

    expect($connection->fresh()->name)->toBe('keep me');
});

// --- POST /connections/{id}/regenerate — rotate token ---

it('rotates the connection_key and returns a fresh token + command', function () {
    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'connection_key' => 'old-key',
        'name' => 'br',
    ]);

    $response = connectionController()->regenerate(
        Request::create('/x', 'POST'),
        $connection->id,
    );

    $body = decodeJson($response);

    expect($response->getStatusCode())->toBe(200);
    expect($body['token'])->toBeString()->not->toBeEmpty();
    expect($body['command'])->toContain($body['token']);
    expect($connection->fresh()->connection_key)
        ->not->toBe('old-key')
        ->toBeString();

    // Fresh token carries the cid claim and the *new* subject — the rotated key.
    $decoded = app(TokenManager::class)->validate($body['token']);
    expect((string) $decoded->sub)->toBe($connection->fresh()->connection_key);
    expect((int) $decoded->cid)->toBe($connection->id);
});

it('regenerate posts /api/disconnect with a relay token scoped to the OLD key', function () {
    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'connection_key' => 'old-key',
    ]);

    connectionController()->regenerate(Request::create('/x', 'POST'), $connection->id);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/api/disconnect')) {
            return false;
        }

        // The disconnect call must be scoped to the OLD key — that is the
        // subject the live bridge is registered under in the websocket server.
        // validate() rejects relay-scoped tokens for user-facing auth, so we
        // pass the expected scope to opt into relay-token validation.
        $auth = $request->header('Authorization')[0] ?? '';
        $jwt = preg_replace('/^Bearer\s+/', '', $auth);
        $decoded = app(TokenManager::class)->validate($jwt, TokenManager::INTERNAL_RELAY_SCOPE);

        return (string) $decoded->sub === 'old-key';
    });
});

it('regenerate rejects a BYOK connection with 422', function () {
    $connection = Connection::create([
        'type' => Connection::TYPE_BYOK,
        'endpoint' => 'https://example.test',
        'api_key' => 'sk-xyz',
    ]);

    $response = connectionController()->regenerate(
        Request::create('/x', 'POST'),
        $connection->id,
    );

    expect($response->getStatusCode())->toBe(422);
    expect(decodeJson($response)['error'])->toBe('not_a_bridge');
});

it('regenerate returns 404 for an unknown connection', function () {
    $response = connectionController()->regenerate(Request::create('/x', 'POST'), 999);

    expect($response->getStatusCode())->toBe(404);
});

// --- DELETE /connections/{id} — disconnect-aware delete ---

it('deletes a bridge connection, kicks the live process, and dispatches ConnectionDeleted', function () {
    Event::fake([ConnectionDeleted::class]);

    $connection = Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'connection_key' => 'live-key',
    ]);

    $response = connectionController()->destroy(Request::create('/x', 'DELETE'), $connection->id);

    expect($response->getStatusCode())->toBe(200);
    expect(Connection::find($connection->id))->toBeNull();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/disconnect'));

    Event::assertDispatched(ConnectionDeleted::class, function (ConnectionDeleted $event) use ($connection) {
        return $event->connection->id === $connection->id;
    });
});

it('returns 404 when deleting an unknown connection', function () {
    $response = connectionController()->destroy(Request::create('/x', 'DELETE'), 999);

    expect($response->getStatusCode())->toBe(404);
});

it('does not attempt to disconnect a BYOK connection on delete', function () {
    $connection = Connection::create([
        'type' => Connection::TYPE_BYOK,
        'endpoint' => 'https://example.test',
        'api_key' => 'sk-xyz',
    ]);

    connectionController()->destroy(Request::create('/x', 'DELETE'), $connection->id);

    Http::assertNothingSent();
});
