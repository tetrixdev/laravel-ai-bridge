<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Events\BridgeConnected;
use Tetrix\AiBridge\Events\BridgeDisconnected;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tests\TestCase;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/*
|--------------------------------------------------------------------------
| BridgeConnectionManager Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify connection lifecycle, pending request tracking,
| and error dispatch on disconnection.
|
*/


function createTestStreamHandler(): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);
    $handler->setConversationId('test-conv');

    return $handler;
}

beforeEach(function () {
    Event::fake();
    $this->manager = new BridgeConnectionManager();
});

test('addConnection() registers a connection for a user', function () {
    $this->manager->addConnection('user-1', 'conn-abc');

    expect($this->manager->hasConnection('user-1'))->toBeTrue();
    expect($this->manager->connectionCount())->toBe(1);

    Event::assertDispatched(BridgeConnected::class, function (BridgeConnected $event) {
        return $event->userId === 'user-1' && $event->connectionId === 'conn-abc';
    });
});

test('removeConnection() removes a registered connection', function () {
    $this->manager->addConnection('user-1', 'conn-abc');
    $this->manager->removeConnection('user-1');

    expect($this->manager->hasConnection('user-1'))->toBeFalse();
    expect($this->manager->connectionCount())->toBe(0);

    Event::assertDispatched(BridgeDisconnected::class, function (BridgeDisconnected $event) {
        return $event->userId === 'user-1';
    });
});

test('removeConnection() is safe for non-existent user', function () {
    // Should not throw
    $this->manager->removeConnection('nonexistent');

    expect($this->manager->connectionCount())->toBe(0);
});

test('hasConnection() returns false for unregistered user', function () {
    expect($this->manager->hasConnection('user-999'))->toBeFalse();
});

test('getConnection() returns connection metadata', function () {
    $this->manager->addConnection('user-1', 'conn-abc', 'ws-object', [['model' => 'gpt-4']]);

    $conn = $this->manager->getConnection('user-1');

    expect($conn)->not->toBeNull();
    expect($conn['connection_id'])->toBe('conn-abc');
    expect($conn['connected_at'])->toBeInt();
    expect($conn['connection'])->toBe('ws-object');
    expect($conn['providers'])->toBe([['model' => 'gpt-4']]);
});

test('getConnection() returns null for unregistered user', function () {
    expect($this->manager->getConnection('user-999'))->toBeNull();
});

test('getConnectionId() returns connection ID for registered user', function () {
    $this->manager->addConnection('user-1', 'conn-xyz');

    expect($this->manager->getConnectionId('user-1'))->toBe('conn-xyz');
});

test('getConnectionId() returns null for unregistered user', function () {
    expect($this->manager->getConnectionId('user-999'))->toBeNull();
});

test('registerPendingRequest() and getPendingRequest() lifecycle', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $retrieved = $this->manager->getPendingRequest('req-1');

    expect($retrieved)->toBe($handler);
});

test('getPendingRequest() returns null for non-existent request', function () {
    expect($this->manager->getPendingRequest('nonexistent'))->toBeNull();
});

test('removePendingRequest() removes a tracked request', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');
    $this->manager->removePendingRequest('req-1');

    expect($this->manager->getPendingRequest('req-1'))->toBeNull();
});

test('failPendingRequestsForUser() dispatches errors to all pending handlers', function () {
    $handler1 = createTestStreamHandler();
    $handler2 = createTestStreamHandler();

    $error1Code = null;
    $error2Code = null;

    $handler1->onError(function (string $code) use (&$error1Code) {
        $error1Code = $code;
    });

    $handler2->onError(function (string $code) use (&$error2Code) {
        $error2Code = $code;
    });

    $this->manager->registerPendingRequest('req-1', $handler1, 'user-1');
    $this->manager->registerPendingRequest('req-2', $handler2, 'user-1');
    $this->manager->addConnection('user-1', 'conn-1');

    // Remove connection triggers failPendingRequestsForUser
    $this->manager->removeConnection('user-1');

    expect($error1Code)->toBe('bridge_disconnected');
    expect($error2Code)->toBe('bridge_disconnected');

    // Pending requests should be cleaned up
    expect($this->manager->getPendingRequest('req-1'))->toBeNull();
    expect($this->manager->getPendingRequest('req-2'))->toBeNull();
});

test('getPendingRequestUserId() returns correct userId (SEC-009)', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, 'user-42');

    expect($this->manager->getPendingRequestUserId('req-1'))->toBe('user-42');
});

test('getPendingRequestUserId() returns null for non-existent request', function () {
    expect($this->manager->getPendingRequestUserId('nonexistent'))->toBeNull();
});

test('getPendingRequestUserId() returns null when userId is empty', function () {
    $handler = createTestStreamHandler();

    $this->manager->registerPendingRequest('req-1', $handler, '');

    expect($this->manager->getPendingRequestUserId('req-1'))->toBeNull();
});

test('reconnection with bridge_reconnecting error code (UX-009)', function () {
    $handler = createTestStreamHandler();
    $receivedCode = null;
    $receivedMessage = null;

    $handler->onError(function (string $code, string $message) use (&$receivedCode, &$receivedMessage) {
        $receivedCode = $code;
        $receivedMessage = $message;
    });

    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');
    $this->manager->addConnection('user-1', 'conn-old');

    // Adding a new connection for the same user replaces the old one
    // This should trigger 'bridge_reconnecting' error code
    $this->manager->addConnection('user-1', 'conn-new');

    expect($receivedCode)->toBe('bridge_reconnecting');
    expect($receivedMessage)->toContain('reconnecting');
});

test('connectedUserIds() returns all connected user IDs', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->addConnection('user-3', 'conn-3');

    $ids = $this->manager->connectedUserIds();

    expect($ids)->toContain('user-1', 'user-2', 'user-3');
    expect($ids)->toHaveCount(3);
});

test('getUserIdByConnectionId() returns correct user ID', function () {
    $this->manager->addConnection('user-42', 'conn-xyz');

    expect($this->manager->getUserIdByConnectionId('conn-xyz'))->toBe('user-42');
});

test('getUserIdByConnectionId() returns null for unknown connection', function () {
    expect($this->manager->getUserIdByConnectionId('conn-unknown'))->toBeNull();
});

test('removeConnectionByConnectionId() removes the correct connection', function () {
    $this->manager->addConnection('user-1', 'conn-abc');
    $this->manager->addConnection('user-2', 'conn-def');

    $this->manager->removeConnectionByConnectionId('conn-abc');

    expect($this->manager->hasConnection('user-1'))->toBeFalse();
    expect($this->manager->hasConnection('user-2'))->toBeTrue();
});

test('setProviders() updates provider capabilities', function () {
    $this->manager->addConnection('user-1', 'conn-1');

    expect($this->manager->getProviders('user-1'))->toBe([]);

    $this->manager->setProviders('user-1', [['model' => 'claude-3']]);

    expect($this->manager->getProviders('user-1'))->toBe([['model' => 'claude-3']]);
});

test('addConnection() converts integer userId to string', function () {
    $this->manager->addConnection(42, 'conn-1');

    expect($this->manager->hasConnection(42))->toBeTrue();
    expect($this->manager->hasConnection('42'))->toBeTrue();
});

test('sendToUser() returns false when no connection exists', function () {
    expect($this->manager->sendToUser('user-999', ['type' => 'test']))->toBeFalse();
});

test('sendToUser() returns false when no send callback and no BridgeConnection', function () {
    $this->manager->addConnection('user-1', 'conn-1', 'plain-object');

    expect($this->manager->sendToUser('user-1', ['type' => 'test']))->toBeFalse();
});

test('sendToUser() uses send callback when configured', function () {
    $sentPayload = null;
    $this->manager->setSendCallback(function ($connection, array $payload) use (&$sentPayload) {
        $sentPayload = $payload;
        return true;
    });

    $this->manager->addConnection('user-1', 'conn-1', 'ws-conn-obj');

    $result = $this->manager->sendToUser('user-1', ['type' => 'test', 'data' => 'hello']);

    expect($result)->toBeTrue();
    expect($sentPayload)->toBe(['type' => 'test', 'data' => 'hello']);
});

test('failPendingRequestsForUser does not affect other users pending requests', function () {
    $handler1 = createTestStreamHandler();
    $handler2 = createTestStreamHandler();

    $error1Fired = false;
    $error2Fired = false;

    $handler1->onError(function () use (&$error1Fired) { $error1Fired = true; });
    $handler2->onError(function () use (&$error2Fired) { $error2Fired = true; });

    $this->manager->registerPendingRequest('req-1', $handler1, 'user-1');
    $this->manager->registerPendingRequest('req-2', $handler2, 'user-2');
    $this->manager->addConnection('user-1', 'conn-1');

    $this->manager->removeConnection('user-1');

    expect($error1Fired)->toBeTrue();
    expect($error2Fired)->toBeFalse();

    // user-2's request should still be there
    expect($this->manager->getPendingRequest('req-2'))->toBe($handler2);
});
