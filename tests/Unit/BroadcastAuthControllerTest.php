<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Http\Controllers\BroadcastAuthController;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;

/*
|--------------------------------------------------------------------------
| BroadcastAuthController Tests
|--------------------------------------------------------------------------
|
| The package-owned channel-authorization endpoint. It must:
|  - sign auth only for channels the request's resolvers can see;
|  - 403 a channel the request cannot see, and any non-package channel;
|  - 422 a malformed request, 500 when broadcasting is not configured.
|
| Invoked directly (like the other controller tests) so route middleware
| does not interfere.
|
*/

uses(RefreshDatabase::class);

const TEST_BROADCAST_SECRET = 'reverb-test-secret';
const TEST_BROADCAST_KEY = 'reverb-test-key';

function authController(): BroadcastAuthController
{
    return app(BroadcastAuthController::class);
}

/** POST request carrying the Pusher-protocol auth fields. */
function authRequest(string $channelName, string $socketId = '12345.67890'): Request
{
    return Request::create('/ai-bridge/broadcasting/auth', 'POST', [
        'channel_name' => $channelName,
        'socket_id' => $socketId,
    ]);
}

beforeEach(function () {
    // Configure a reverb broadcaster connection so signing has credentials.
    config()->set('ai-bridge.broadcasting.connection', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => TEST_BROADCAST_KEY,
        'secret' => TEST_BROADCAST_SECRET,
    ]);

    // Default: every conversation / connection is visible to the request.
    app(AiBridgeManager::class)->resolveConversationsUsing(fn ($request) => Conversation::query());
    app(AiBridgeManager::class)->resolveConnectionsUsing(fn ($request) => Connection::query());
});

test('signs auth for a conversation channel the request can see', function () {
    $conversation = Conversation::create(['mode' => 'bridge']);
    $channel = 'private-ai-bridge.conversation.'.$conversation->id;
    $socketId = '12345.67890';

    $response = authController()->authenticate(authRequest($channel, $socketId));

    expect($response->getStatusCode())->toBe(200);

    $expected = TEST_BROADCAST_KEY.':'.hash_hmac(
        'sha256', $socketId.':'.$channel, TEST_BROADCAST_SECRET,
    );
    expect(json_decode($response->getContent(), true)['auth'])->toBe($expected);
});

test('signs auth for a connection channel the request can see', function () {
    $connection = Connection::create([
        'type' => 'bridge',
        'name' => 'My Bridge',
        'connection_key' => 'key-abc',
    ]);
    $channel = 'private-ai-bridge.connection.'.$connection->id;

    $response = authController()->authenticate(authRequest($channel));

    expect($response->getStatusCode())->toBe(200);
    expect(json_decode($response->getContent(), true))->toHaveKey('auth');
});

test('rejects a conversation channel the request cannot see', function () {
    $conversation = Conversation::create(['mode' => 'bridge']);

    // Resolver that excludes everything — the row exists but is not visible.
    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn ($request) => Conversation::query()->whereRaw('1 = 0'),
    );

    $response = authController()->authenticate(
        authRequest('private-ai-bridge.conversation.'.$conversation->id),
    );

    expect($response->getStatusCode())->toBe(403);
});

test('rejects a channel that does not exist', function () {
    $response = authController()->authenticate(
        authRequest('private-ai-bridge.conversation.999999'),
    );

    expect($response->getStatusCode())->toBe(403);
});

test('rejects a channel that is not an AI Bridge channel', function () {
    $response = authController()->authenticate(
        authRequest('private-some-other-app.channel.1'),
    );

    expect($response->getStatusCode())->toBe(403);
});

test('rejects a request missing channel_name or socket_id', function () {
    expect(authController()->authenticate(authRequest('', '12345.1'))->getStatusCode())->toBe(422);
    expect(authController()->authenticate(
        authRequest('private-ai-bridge.conversation.1', ''),
    )->getStatusCode())->toBe(422);
});

test('returns 500 when the broadcaster is not configured', function () {
    $conversation = Conversation::create(['mode' => 'bridge']);
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb', 'key' => '', 'secret' => '',
    ]);

    $response = authController()->authenticate(
        authRequest('private-ai-bridge.conversation.'.$conversation->id),
    );

    expect($response->getStatusCode())->toBe(500);
});
