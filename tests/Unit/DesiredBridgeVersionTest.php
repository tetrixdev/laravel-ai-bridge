<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Http\Controllers\ConnectionController;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Support\DesiredBridgeVersion;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Desired bridge version
|--------------------------------------------------------------------------
|
| The server names the bridge version it wants in `welcome`, and a bridge
| running as a managed service (0.24.0+) moves to exactly that version. Since
| the bridge acts on it, a value this side cannot vouch for is never sent —
| and the bridge's own report of what it runs comes back on the status.
|
*/

function desiredVersionHello(BridgeConnectionManager $manager, array $extra = []): array
{
    $handler = new MessageHandler(
        connectionManager: $manager,
        tokenManager: app(TokenManager::class),
        toolRegistry: new ToolRegistry(),
    );

    return $handler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'token' => app(TokenManager::class)->generate('user-1'),
        'providers' => [],
        ...$extra,
    ]));
}

function desiredVersionBridgeRow(): Connection
{
    return Connection::create([
        'type' => Connection::TYPE_BRIDGE,
        'name' => 'box',
        'connection_key' => 'key-1',
        'last_providers' => [],
    ]);
}

beforeEach(function () {
    Event::fake();
    DesiredBridgeVersion::flushState();
});

describe('the welcome message', function () {
    it('names the desired version when one is configured', function (string $version) {
        config(['ai-bridge.bridge.desired_version' => $version]);

        $welcome = desiredVersionHello(new BridgeConnectionManager());

        expect($welcome['type'])->toBe(MessageTypes::WELCOME)
            ->and($welcome['desired_bridge_version'])->toBe($version);
    })->with(['0.24.0', '0.24.1', '1.0.0-rc.2', '0.25.0-beta.1']);

    it('leaves the key out entirely when nothing is configured', function (mixed $unset) {
        config(['ai-bridge.bridge.desired_version' => $unset]);

        $welcome = desiredVersionHello(new BridgeConnectionManager());

        expect($welcome['type'])->toBe(MessageTypes::WELCOME)
            ->and($welcome)->not->toHaveKey('desired_bridge_version');
    })->with([null, '']);

    it('leaves the key out for a value that is not plain semver', function (mixed $bad) {
        // Every one of these is something npm would happily interpret. The
        // bridge must never be the one deciding what a value like this means.
        Log::spy();
        config(['ai-bridge.bridge.desired_version' => $bad]);

        $welcome = desiredVersionHello(new BridgeConnectionManager());

        expect($welcome['type'])->toBe(MessageTypes::WELCOME)
            ->and($welcome)->not->toHaveKey('desired_bridge_version');
        Log::shouldHaveReceived('error')->once();
    })->with([
        'v0.24.0',
        '0.24',
        'latest',
        '^0.24.0',
        '0.24.0+build',
        'https://registry.npmjs.org/@tetrixdev/ai-bridge/-/ai-bridge-0.24.0.tgz',
        ' 0.24.0',
        '00.24.0',
        '0.24.0-',
        'array' => [['0.24.0']],
    ]);

    it('leaves the key out for a version below the self-update floor', function (string $old) {
        // Pinning a machine to a version that cannot update itself strands it.
        Log::spy();
        config(['ai-bridge.bridge.desired_version' => $old]);

        $welcome = desiredVersionHello(new BridgeConnectionManager());

        expect($welcome)->not->toHaveKey('desired_bridge_version');
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, DesiredBridgeVersion::FLOOR))
            ->once();
    })->with(['0.23.0', '0.23.99', '0.24.0-rc.1', '0.1.0']);

    it('logs a refused value once per process, not on every handshake', function () {
        Log::spy();
        config(['ai-bridge.bridge.desired_version' => 'latest']);

        desiredVersionHello(new BridgeConnectionManager());
        desiredVersionHello(new BridgeConnectionManager());
        desiredVersionHello(new BridgeConnectionManager());

        Log::shouldHaveReceived('error')->once();
    });
});

describe('semver precedence', function () {
    it('orders versions the way semver does', function (string $lower, string $higher) {
        expect(DesiredBridgeVersion::compare($lower, $higher))->toBeLessThan(0)
            ->and(DesiredBridgeVersion::compare($higher, $lower))->toBeGreaterThan(0);
    })->with([
        ['0.23.0', '0.24.0'],
        ['0.9.0', '0.10.0'],
        ['0.24.0', '0.24.1'],
        ['0.24.9', '1.0.0'],
        ['0.24.0-rc.1', '0.24.0'],
        // The semver spec's own example chain.
        ['1.0.0-alpha', '1.0.0-alpha.1'],
        ['1.0.0-alpha.1', '1.0.0-alpha.beta'],
        ['1.0.0-alpha.beta', '1.0.0-beta'],
        ['1.0.0-beta', '1.0.0-beta.2'],
        ['1.0.0-beta.2', '1.0.0-beta.11'],
        ['1.0.0-beta.11', '1.0.0-rc.1'],
        ['1.0.0-rc.1', '1.0.0'],
        ['1.0.0', '99999999999999999999.0.0'],
    ]);

    it('treats equal versions as equal', function () {
        expect(DesiredBridgeVersion::compare('0.24.0', '0.24.0'))->toBe(0)
            ->and(DesiredBridgeVersion::compare('1.0.0-rc.2', '1.0.0-rc.2'))->toBe(0);
    });
});

describe('what the bridge says about itself on hello', function () {
    it('records the version it runs and that it will follow the desired version', function () {
        $manager = new BridgeConnectionManager();

        desiredVersionHello($manager, ['bridge_version' => '0.24.0', 'self_update' => true]);

        expect($manager->getBridgeVersion('user-1'))->toBe('0.24.0')
            ->and($manager->getSelfUpdate('user-1'))->toBeTrue();
    });

    it('records the same on a connection authenticated before its hello', function () {
        $manager = new BridgeConnectionManager();
        $manager->addConnection('user-1', 'conn-1');

        $handler = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());
        $handler->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::HELLO,
            'version' => '0.1',
            'providers' => [],
            'bridge_version' => '0.24.2',
            'self_update' => true,
        ]));

        expect($manager->getBridgeVersion('user-1'))->toBe('0.24.2')
            ->and($manager->getSelfUpdate('user-1'))->toBeTrue();
    });

    it('reads an absent self_update as false, which is what every older bridge sends', function () {
        $manager = new BridgeConnectionManager();

        desiredVersionHello($manager, ['bridge_version' => '0.23.0']);

        expect($manager->getBridgeVersion('user-1'))->toBe('0.23.0')
            ->and($manager->getSelfUpdate('user-1'))->toBeFalse();
    });

    it('does not trust junk in either field, nor take the serve process down over it', function () {
        $manager = new BridgeConnectionManager();

        $welcome = desiredVersionHello($manager, ['bridge_version' => ['0.24.0'], 'self_update' => 'yes']);

        expect($welcome['type'])->toBe(MessageTypes::WELCOME)
            ->and($manager->getBridgeVersion('user-1'))->toBeNull()
            ->and($manager->getSelfUpdate('user-1'))->toBeFalse();
    });

    it('forgets both when the bridge disconnects', function () {
        $manager = new BridgeConnectionManager();
        desiredVersionHello($manager, ['bridge_version' => '0.24.0', 'self_update' => true]);

        $manager->removeConnection('user-1');

        expect($manager->getBridgeVersion('user-1'))->toBeNull()
            ->and($manager->getSelfUpdate('user-1'))->toBeFalse();
    });
});

describe('the connection status', function () {
    it('reports what a connected bridge runs and whether it self-updates', function () {
        Http::fake(['*/api/status' => Http::response([
            'connected' => true,
            'providers' => [],
            'bridge_version' => '0.24.0',
            'self_update' => true,
        ])]);

        $status = app(ConnectionStatus::class)->for(desiredVersionBridgeRow());

        expect($status['bridge_version'])->toBe('0.24.0')
            ->and($status['self_update'])->toBeTrue();
    });

    it('reports self_update false when the bridge says it will not follow', function () {
        Http::fake(['*/api/status' => Http::response([
            'connected' => true,
            'providers' => [],
            'bridge_version' => '0.23.0',
            'self_update' => false,
        ])]);

        $status = app(ConnectionStatus::class)->for(desiredVersionBridgeRow());

        expect($status['bridge_version'])->toBe('0.23.0')
            ->and($status['self_update'])->toBeFalse();
    });

    it('reports unknown when a serve process predates the fields', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => true, 'providers' => []])]);

        $status = app(ConnectionStatus::class)->for(desiredVersionBridgeRow());

        expect($status['bridge_version'])->toBeNull()
            ->and($status['self_update'])->toBeFalse();
    });

    it('reports unknown for a bridge that is not connected', function () {
        Http::fake(['*/api/status' => Http::response(['connected' => false])]);

        $status = app(ConnectionStatus::class)->for(desiredVersionBridgeRow());

        expect($status['bridge_version'])->toBeNull()
            ->and($status['self_update'])->toBeFalse();
    });

    it('carries both through to the connections API', function () {
        app(AiBridgeManager::class)->resolveConnectionsUsing(fn ($request) => Connection::query());
        Http::fake(['*/api/status' => Http::response([
            'connected' => true,
            'providers' => [],
            'bridge_version' => '0.24.1',
            'self_update' => true,
        ])]);
        desiredVersionBridgeRow();

        $response = app(ConnectionController::class)->index(Request::create('/ai-bridge/connections', 'GET'));
        $row = json_decode($response->getContent(), true)['connections'][0];

        expect($row['bridge_version'])->toBe('0.24.1')
            ->and($row['self_update'])->toBeTrue();
    });

    it('reports nothing for a BYOK connection, which has no bridge', function () {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $connection = Connection::create([
            'type' => Connection::TYPE_BYOK,
            'name' => 'key',
            'api_key' => 'sk-test',
        ]);

        $status = app(ConnectionStatus::class)->for($connection);

        expect($status['bridge_version'])->toBeNull()
            ->and($status['self_update'])->toBeFalse();
    });
});
