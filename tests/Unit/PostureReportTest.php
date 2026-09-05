<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The posture a bridge reports it adopted
|--------------------------------------------------------------------------
|
| The server asks for a CLI isolation in `welcome`; the bridge may decline it,
| because `workspace` and `native` are gated on flags its operator passes. That
| refusal used to be visible only in a log on the operator's own machine, so an
| operator who forgot `--allow-native` got a connection that looked healthy in
| every screen while the assistant silently had no tools.
|
*/

function postureHandler(BridgeConnectionManager $manager): MessageHandler
{
    return new MessageHandler(
        connectionManager: $manager,
        tokenManager: app(TokenManager::class),
        toolRegistry: new ToolRegistry(),
    );
}

/** A connected bridge, past the handshake. */
function connectedManager(): BridgeConnectionManager
{
    $manager = new BridgeConnectionManager();
    $token = app(TokenManager::class)->generate('user-1');

    postureHandler($manager)->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'token' => $token,
        'providers' => [],
    ]));

    return $manager;
}

it('records a posture the bridge adopted as asked', function () {
    $manager = connectedManager();

    postureHandler($manager)->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::POSTURE,
        'cli_isolation' => 'workspace',
        'requested' => 'workspace',
    ]));

    expect($manager->getPosture('user-1'))
        ->toMatchArray(['cli_isolation' => 'workspace', 'requested' => 'workspace', 'reason' => null]);
});

it('records the reason and the message when the bridge declined', function () {
    $manager = connectedManager();

    postureHandler($manager)->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::POSTURE,
        'cli_isolation' => 'isolated',
        'requested' => 'native',
        'reason' => 'requires_allow_native',
        'message' => 'Pass --allow-native if the server is one you would give a shell to.',
    ]));

    $posture = $manager->getPosture('user-1');

    expect($posture['cli_isolation'])->toBe('isolated')
        ->and($posture['requested'])->toBe('native')
        ->and($posture['reason'])->toBe('requires_allow_native')
        // The message is the whole point: it names the operator action.
        ->and($posture['message'])->toContain('--allow-native');
});

it('is empty for a bridge that never reported one', function () {
    // An older bridge. Unknown, which is not the same as agreement.
    expect(connectedManager()->getPosture('user-1'))->toBe([]);
});

it('ignores a posture frame with no cli_isolation rather than storing junk', function () {
    $manager = connectedManager();

    postureHandler($manager)->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::POSTURE,
        'requested' => 'native',
    ]));

    expect($manager->getPosture('user-1'))->toBe([]);
});

it('does not take the serve process down on a malformed posture frame', function () {
    // handleMessage runs in the WebSocket server's message callback.
    $manager = connectedManager();

    foreach ([['cli_isolation' => ['array']], ['cli_isolation' => 42], []] as $bad) {
        $result = postureHandler($manager)->handleMessage('conn-1', null, json_encode(
            array_merge(['type' => MessageTypes::POSTURE], $bad)
        ));
        expect($result)->toBeNull();
    }
});

it('ignores a posture frame from an unauthenticated connection', function () {
    $manager = new BridgeConnectionManager();

    $result = postureHandler($manager)->handleMessage('conn-unknown', null, json_encode([
        'type' => MessageTypes::POSTURE,
        'cli_isolation' => 'native',
    ]));

    expect($result)->toBeNull()
        ->and($manager->getPosture('user-1'))->toBe([]);
});

it('surfaces the posture on the connection status, and caches it', function () {
    $connection = Connection::create([
        'type' => 'bridge', 'name' => 'laptop', 'connection_key' => 'key-1',
    ]);

    Http::fake(['*/api/status' => Http::response([
        'connected' => true,
        'providers' => [],
        'posture' => [
            'cli_isolation' => 'isolated',
            'requested' => 'native',
            'reason' => 'requires_allow_native',
        ],
    ], 200)]);

    $status = app(ConnectionStatus::class)->for($connection);

    expect($status['posture']['reason'])->toBe('requires_allow_native')
        ->and($connection->fresh()->last_posture['reason'])->toBe('requires_allow_native');
});

it('keeps the cached posture when a serve process does not report one', function () {
    // The rolling-deploy case, same rule as providers and workspaces: absent
    // is not empty.
    $connection = Connection::create([
        'type' => 'bridge', 'name' => 'laptop', 'connection_key' => 'key-1',
        'last_posture' => ['cli_isolation' => 'workspace', 'requested' => 'workspace'],
    ]);

    Http::fake(['*/api/status' => Http::response(['connected' => true, 'providers' => []], 200)]);

    expect(app(ConnectionStatus::class)->for($connection)['posture']['cli_isolation'])->toBe('workspace');
});

it('reports no posture for a BYOK connection, which has no machine', function () {
    $connection = Connection::create(['type' => Connection::TYPE_BYOK, 'name' => 'openai']);

    expect(app(ConnectionStatus::class)->posture($connection))->toBe([]);
});
