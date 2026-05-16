<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tests\TestCase;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| MessageHandler Unit Tests
|--------------------------------------------------------------------------
|
| Tests for ownership checks (SEC-003, SEC-004), protocol message routing,
| and stream event dispatch. Also covers CONS-006 (tool_call_id key).
|
*/


function makeMessageHandler(?BridgeConnectionManager $manager = null, ?ToolRegistry $registry = null): MessageHandler
{
    return new MessageHandler(
        connectionManager: $manager ?? new BridgeConnectionManager(),
        tokenManager: app(TokenManager::class),
        toolRegistry: $registry ?? new ToolRegistry(),
    );
}

function makeHandler(BridgeConnectionManager $manager): StreamHandler
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
    $this->messageHandler = makeMessageHandler($this->manager);
});

// --- SEC-003: top-level tool_call ownership ---

test('tool_call message from correct user is processed (SEC-003)', function () {
    $handler = makeHandler($this->manager);

    $toolCallFired = false;
    $handler->onToolCall(function () use (&$toolCallFired) {
        $toolCallFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    // Register a tool so we get a resolve response back
    $registry = new ToolRegistry();
    $registry->register('echo', 'Echo test', ['type' => 'object'], fn ($p) => ['echoed' => $p]);
    $mh = makeMessageHandler($this->manager, $registry);

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'echo',
        'parameters' => ['val' => 'hi'],
        'call_id' => 'call-123',
    ]);

    $response = $mh->handleMessage('conn-1', null, $rawMsg);

    // Should return a tool_resolve (the tool ran successfully)
    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
});

test('tool_call message from wrong user is discarded (SEC-003)', function () {
    $handler = makeHandler($this->manager);

    $toolCallFired = false;
    $handler->onToolCall(function () use (&$toolCallFired) {
        $toolCallFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2'); // different user, different connection
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'any_tool',
        'parameters' => [],
        'call_id' => 'call-999',
    ]);

    // conn-2 belongs to user-2, but the request belongs to user-1
    $response = $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($response)->toBeNull(); // Discarded — ownership mismatch
    expect($toolCallFired)->toBeFalse(); // Callback must not fire
});

test('tool_call from unregistered connection is discarded (SEC-003 fail-closed)', function () {
    $handler = makeHandler($this->manager);
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => 'any_tool',
        'parameters' => [],
        'call_id' => 'call-999',
    ]);

    // 'conn-unknown' is not registered in the manager — senderUserId will be null
    $response = $this->messageHandler->handleMessage('conn-unknown', null, $rawMsg);

    expect($response)->toBeNull(); // Discarded — fail-closed on null senderUserId
});

// --- SEC-004: stream envelope ownership ---

test('stream block_delta from correct user is dispatched (SEC-004)', function () {
    $handler = makeHandler($this->manager);

    $receivedContent = null;
    $handler->onBlockDelta(function (StreamEvent $event) use (&$receivedContent) {
        $receivedContent = $event->data['content'] ?? null;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'hello'],
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeNull();
    expect($receivedContent)->toBe('hello');
});

test('stream block_delta from wrong user is discarded (SEC-004)', function () {
    $handler = makeHandler($this->manager);

    $receivedContent = null;
    $handler->onBlockDelta(function (StreamEvent $event) use (&$receivedContent) {
        $receivedContent = $event->data['content'] ?? null;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'injected'],
    ]);

    // conn-2 belongs to user-2, but req-1 belongs to user-1
    $response = $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($response)->toBeNull();
    expect($receivedContent)->toBeNull(); // Must not dispatch injected event
});

test('stream event from unregistered connection is discarded (SEC-004 fail-closed)', function () {
    $handler = makeHandler($this->manager);
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $receivedContent = null;
    $handler->onBlockDelta(function (StreamEvent $event) use (&$receivedContent) {
        $receivedContent = $event->data['content'] ?? null;
    });

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::BLOCK_DELTA,
        'data' => ['block_type' => 'text', 'block_index' => 0, 'content' => 'injected'],
    ]);

    // conn-unknown has no registered userId — fail-closed means null userId => reject
    $response = $this->messageHandler->handleMessage('conn-unknown', null, $rawMsg);

    expect($response)->toBeNull();
    expect($receivedContent)->toBeNull();
});

// --- done/error ownership checks (BL-007) ---

test('stream done from correct user is dispatched (BL-007)', function () {
    $handler = makeHandler($this->manager);

    $doneFired = false;
    $handler->onDone(function () use (&$doneFired) {
        $doneFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::DONE,
        'data' => [],
    ]);

    $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($doneFired)->toBeTrue();
    expect($this->manager->getPendingRequest('req-1'))->toBeNull();
});

test('stream done from wrong user is discarded (BL-007)', function () {
    $handler = makeHandler($this->manager);

    $doneFired = false;
    $handler->onDone(function () use (&$doneFired) {
        $doneFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::DONE,
        'data' => [],
    ]);

    $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($doneFired)->toBeFalse();
    // Request should still be pending since done was discarded
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull();
});

test('stream error from wrong user is discarded (BL-007)', function () {
    $handler = makeHandler($this->manager);

    $errorFired = false;
    $handler->onError(function () use (&$errorFired) {
        $errorFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::ERROR,
        'data' => ['code' => 'fake_error', 'message' => 'spoofed'],
    ]);

    $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($errorFired)->toBeFalse();
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull();
});

// --- CONS-006: StreamEvent::toolCall() uses 'tool_call_id' key ---

test("StreamEvent::toolCall() stores 'tool_call_id' not 'call_id' (CONS-006)", function () {
    $event = StreamEvent::toolCall('req-1', 'search', ['q' => 'cats'], 'call-xyz');

    expect($event->data)->toHaveKey('tool_call_id');
    expect($event->data['tool_call_id'])->toBe('call-xyz');
    expect($event->data)->not->toHaveKey('call_id');
});

test('StreamHandler::dispatchEvent() reads tool_call_id from event data (CONS-006)', function () {
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $streamHandler = new StreamHandler($provider);
    $streamHandler->setMode(ProviderMode::Byok);
    $streamHandler->setConversationId('conv-1');

    $receivedCallId = null;
    $streamHandler->onToolCall(function (string $name, array $params, string $callId) use (&$receivedCallId) {
        $receivedCallId = $callId;
    });

    // Create an event using the canonical tool_call_id key
    $event = StreamEvent::toolCall('req-1', 'search', ['q' => 'cats'], 'call-xyz');
    $streamHandler->dispatchEvent($event);

    expect($receivedCallId)->toBe('call-xyz');
});

test('StreamHandler::dispatchEvent() falls back to call_id for legacy events (CONS-006)', function () {
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $streamHandler = new StreamHandler($provider);
    $streamHandler->setMode(ProviderMode::Byok);
    $streamHandler->setConversationId('conv-1');

    $receivedCallId = null;
    $streamHandler->onToolCall(function (string $name, array $params, string $callId) use (&$receivedCallId) {
        $receivedCallId = $callId;
    });

    // Simulate a legacy event that uses the old 'call_id' key
    $event = new StreamEvent('req-1', MessageTypes::TOOL_CALL, [
        'tool_name' => 'search',
        'parameters' => [],
        'call_id' => 'legacy-call-id',
    ]);
    $streamHandler->dispatchEvent($event);

    expect($receivedCallId)->toBe('legacy-call-id');
});

// --- BL-001: stream-envelope tool_call ownership bypass ---

test('stream-envelope tool_call from correct user is processed (BL-001)', function () {
    $registry = new ToolRegistry();
    $registry->register('ping', 'Ping tool', ['type' => 'object'], fn ($p) => ['pong' => true]);
    $mh = makeMessageHandler($this->manager, $registry);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TOOL_CALL,
        'data' => [
            'tool_name' => 'ping',
            'parameters' => [],
            'tool_call_id' => 'call-abc',
        ],
    ]);

    $response = $mh->handleMessage('conn-1', null, $rawMsg);

    // The tool ran and returned a tool_resolve
    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::TOOL_RESOLVE);
    expect($response['result'])->toBe(['pong' => true]);
});

test('stream-envelope tool_call from wrong user is discarded (BL-001)', function () {
    $registry = new ToolRegistry();
    $toolExecuted = false;
    $registry->register('ping', 'Ping tool', ['type' => 'object'], function ($p) use (&$toolExecuted) {
        $toolExecuted = true;
        return ['pong' => true];
    });
    $mh = makeMessageHandler($this->manager, $registry);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TOOL_CALL,
        'data' => [
            'tool_name' => 'ping',
            'parameters' => [],
            'tool_call_id' => 'call-evil',
        ],
    ]);

    // conn-2 belongs to user-2, but the request belongs to user-1
    $response = $mh->handleMessage('conn-2', null, $rawMsg);

    expect($response)->toBeNull(); // Discarded — ownership mismatch
    expect($toolExecuted)->toBeFalse(); // Tool must NOT execute
});

test('stream-envelope tool_call from unregistered connection is discarded (BL-001 fail-closed)', function () {
    $registry = new ToolRegistry();
    $toolExecuted = false;
    $registry->register('ping', 'Ping tool', ['type' => 'object'], function ($p) use (&$toolExecuted) {
        $toolExecuted = true;
        return [];
    });
    $mh = makeMessageHandler($this->manager, $registry);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', makeHandler($this->manager), 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => MessageTypes::TOOL_CALL,
        'data' => [
            'tool_name' => 'ping',
            'parameters' => [],
            'tool_call_id' => 'call-ghost',
        ],
    ]);

    // 'conn-ghost' is not registered — verifySenderOwnsRequest must reject it
    $response = $mh->handleMessage('conn-ghost', null, $rawMsg);

    expect($response)->toBeNull();
    expect($toolExecuted)->toBeFalse();
});

// --- SEC-001: CANCELLED handler ownership check ---

test('cancelled message from correct user dispatches cancellation (SEC-001)', function () {
    $handler = makeHandler($this->manager);

    $cancelledFired = false;
    $handler->onCancelled(function () use (&$cancelledFired) {
        $cancelledFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-1',
    ]);

    $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($cancelledFired)->toBeTrue();
    expect($this->manager->getPendingRequest('req-1'))->toBeNull(); // cleaned up
});

test('cancelled message from wrong user is discarded (SEC-001)', function () {
    $handler = makeHandler($this->manager);

    $cancelledFired = false;
    $handler->onCancelled(function () use (&$cancelledFired) {
        $cancelledFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->addConnection('user-2', 'conn-2');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-1',
    ]);

    // conn-2 belongs to user-2, but request belongs to user-1
    $this->messageHandler->handleMessage('conn-2', null, $rawMsg);

    expect($cancelledFired)->toBeFalse();
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull(); // NOT cleaned up
});

test('cancelled message from unregistered connection is discarded (SEC-001 fail-closed)', function () {
    $handler = makeHandler($this->manager);

    $cancelledFired = false;
    $handler->onCancelled(function () use (&$cancelledFired) {
        $cancelledFired = true;
    });

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', $handler, 'user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::CANCELLED,
        'request_id' => 'req-1',
    ]);

    // 'conn-unknown' is not in the manager — fail-closed means null userId => reject
    $this->messageHandler->handleMessage('conn-unknown', null, $rawMsg);

    expect($cancelledFired)->toBeFalse();
    expect($this->manager->getPendingRequest('req-1'))->not->toBeNull();
});

// --- Protocol version mismatch (ARCH-005) ---

test('hello with incompatible major protocol version is rejected (ARCH-005)', function () {
    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '1.0',
        'providers' => [],
        'token' => 'ignored',
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::CONNECTION_ERROR);
    expect($response['error'])->toBe('protocol_version_mismatch');
});

test('hello with compatible minor version difference is accepted (ARCH-005)', function () {
    // Generate a valid token for the hello message
    $token = app(TokenManager::class)->generate('user-1');

    $rawMsg = json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.5',  // Major=0 matches, minor difference is fine
        'providers' => [],
        'token' => $token,
    ]);

    $response = $this->messageHandler->handleMessage('conn-1', null, $rawMsg);

    expect($response)->toBeArray();
    expect($response['type'])->toBe(MessageTypes::WELCOME);
});

// --- EFF-006: MessageTypes::all() caching ---

test('MessageTypes::all() returns same array reference on second call (EFF-006)', function () {
    // First call populates the cache
    $first = \Tetrix\AiBridge\Protocol\MessageTypes::all();
    // Second call must hit the cache and return identical result
    $second = \Tetrix\AiBridge\Protocol\MessageTypes::all();

    expect($first)->toBe($second); // Same array, not just equal
});

test('MessageTypes::all() contains all expected message type constants (EFF-006)', function () {
    $all = \Tetrix\AiBridge\Protocol\MessageTypes::all();

    expect($all)->toContain(MessageTypes::HELLO);
    expect($all)->toContain(MessageTypes::WELCOME);
    expect($all)->toContain(MessageTypes::PING);
    expect($all)->toContain(MessageTypes::PONG);
    expect($all)->toContain(MessageTypes::STREAM);
    expect($all)->toContain(MessageTypes::DONE);
    expect($all)->toContain(MessageTypes::TOOL_CALL);
    expect($all)->toContain(MessageTypes::CANCELLED);
    expect($all)->toHaveCount(20);
});

test('MessageTypes::isValid() accepts known types and rejects unknown (EFF-006)', function () {
    expect(MessageTypes::isValid(MessageTypes::HELLO))->toBeTrue();
    expect(MessageTypes::isValid(MessageTypes::STREAM))->toBeTrue();
    expect(MessageTypes::isValid('not_a_real_type'))->toBeFalse();
    expect(MessageTypes::isValid(''))->toBeFalse();
});
