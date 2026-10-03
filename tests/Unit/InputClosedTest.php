<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Events\TurnInputClosed;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\BufferingSink;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| input_closed: the bridge closes a running turn's input (ai-bridge 0.25+)
|--------------------------------------------------------------------------
|
| The ack opened the turn to input (`input_open` in the stream metadata);
| `input_closed` must close it again while the turn runs on, so inputOpen()
| stops saying true and the app holds the next message for a new turn
| instead of having it answered `turn_ending`.
|
*/

/**
 * A serve process with user-1's bridge running req-a and req-b, both opened to input
 * by their acks, with a BufferingSink on req-a as a relayed turn has.
 */
function inputClosedRig(): object
{
    Event::fake([TurnInputClosed::class]);

    $rig = new stdClass();
    $rig->store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $rig->store);

    $rig->manager = new BridgeConnectionManager();
    $rig->messageHandler = new MessageHandler($rig->manager, app(TokenManager::class), new ToolRegistry());
    $rig->manager->addConnection('user-1', 'conn-1');
    $rig->manager->addConnection('user-2', 'conn-2');

    $rig->handlers = [];
    foreach (['req-a', 'req-b'] as $rid) {
        $provider = Mockery::mock(StreamableProvider::class);
        $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();
        $handler = new StreamHandler($provider, $rid);
        $handler->setMode(ProviderMode::Bridge);
        $rig->manager->registerPendingRequest($rid, $handler, 'user-1');
        $rig->store->start($rid, ['conversation_id' => 'conv-1']);
        $rig->handlers[$rid] = $handler;

        $rig->messageHandler->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::AI_REQUEST_ACK,
            'request_id' => $rid,
            'cli_session_id' => null,
            'input_open' => true,
        ]));
    }

    BufferingSink::attach($rig->handlers['req-a'], $rig->store);

    return $rig;
}

function inputClosedFrame(object $rig, string $connectionId, string $requestId, mixed $data = ['reason' => 'idle']): void
{
    $rig->messageHandler->handleMessage($connectionId, null, json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => $requestId,
        'event' => MessageTypes::INPUT_CLOSED,
        'data' => $data,
    ]));
}

it('clears input_open in the stream metadata, and inputOpen() turns false while the turn runs on', function () {
    $rig = inputClosedRig();
    expect(app(AiBridgeManager::class)->inputOpen('req-a'))->toBeTrue();

    inputClosedFrame($rig, 'conn-1', 'req-a');

    expect($rig->store->status('req-a'))->toMatchArray(['status' => 'streaming'])
        ->and($rig->store->status('req-a')['metadata'])->toBe([
            'conversation_id' => 'conv-1',
            'input_open' => false,
            'input_closed_reason' => 'idle',
        ])
        ->and(app(AiBridgeManager::class)->inputOpen('req-a'))->toBeFalse();
});

it('accepts a reason it does not know, and a missing one', function (mixed $data, ?string $recorded) {
    $rig = inputClosedRig();

    inputClosedFrame($rig, 'conn-1', 'req-a', $data);

    expect($rig->store->status('req-a')['metadata']['input_open'])->toBeFalse()
        ->and($rig->store->status('req-a')['metadata']['input_closed_reason'])->toBe($recorded)
        ->and(app(AiBridgeManager::class)->inputOpen('req-a'))->toBeFalse();
    Event::assertDispatched(TurnInputClosed::class, fn (TurnInputClosed $e) => $e->reason === $recorded);
})->with([
    'a future reason' => [['reason' => 'operator_paused'], 'operator_paused'],
    'no reason' => [[], null],
    'a non-string reason' => [['reason' => ['idle']], null],
    'no data at all' => [null, null],
]);

it('leaves another request of the same bridge open', function () {
    $rig = inputClosedRig();

    inputClosedFrame($rig, 'conn-1', 'req-a');

    expect($rig->store->status('req-b')['metadata'])->toBe(['conversation_id' => 'conv-1', 'input_open' => true])
        ->and(app(AiBridgeManager::class)->inputOpen('req-b'))->toBeTrue();
});

it('relays the event down the turn\'s stream and buffers it, and fires TurnInputClosed', function () {
    $rig = inputClosedRig();
    $seen = [];
    $rig->handlers['req-a']->onInputClosed(function (array $data) use (&$seen) {
        $seen[] = $data;
    });

    inputClosedFrame($rig, 'conn-1', 'req-a');

    expect($seen)->toBe([['reason' => 'idle']])
        ->and(array_map(fn (array $e) => [$e['event'], $e['data']], $rig->store->range('req-a')))
        ->toBe([[MessageTypes::INPUT_CLOSED, ['reason' => 'idle']]]);
    Event::assertDispatched(TurnInputClosed::class, fn (TurnInputClosed $e) => $e->userId === 'user-1'
        && $e->requestId === 'req-a'
        && $e->reason === 'idle');
    Event::assertDispatchedTimes(TurnInputClosed::class, 1);
});

it('reaches a streamToResponse / SSE sink as input_closed', function () {
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();
    $stream = new StreamHandler($provider, 'req-s');
    $sent = [];

    (new ReflectionMethod(AiBridgeManager::class, 'wireCallbacks'))
        ->invoke(app(AiBridgeManager::class), $stream, function (array $payload) use (&$sent) {
            $sent[] = $payload;
        });
    $stream->dispatchEvent(new \Tetrix\AiBridge\Protocol\StreamEvent('req-s', MessageTypes::INPUT_CLOSED, ['reason' => 'idle']));

    expect($sent)->toBe([['event' => MessageTypes::INPUT_CLOSED, 'data' => ['reason' => 'idle']]]);
});

it('ignores an input_closed for an unknown or finished request, without error', function () {
    $rig = inputClosedRig();
    $rig->manager->removePendingRequest('req-b');
    $rig->store->complete('req-b', 'completed');

    inputClosedFrame($rig, 'conn-1', 'req-unknown');
    inputClosedFrame($rig, 'conn-1', 'req-b');

    expect($rig->store->status('req-unknown')['status'] ?? null)->not->toBe('streaming')
        ->and($rig->store->status('req-b')['metadata'])->toBe(['conversation_id' => 'conv-1', 'input_open' => true])
        ->and(app(AiBridgeManager::class)->inputOpen('req-b'))->toBeFalse();
    Event::assertNotDispatched(TurnInputClosed::class);
});

it('does not let another user\'s bridge close a turn\'s input', function () {
    $rig = inputClosedRig();

    inputClosedFrame($rig, 'conn-2', 'req-a');

    expect($rig->store->status('req-a')['metadata']['input_open'])->toBeTrue()
        ->and($rig->store->range('req-a'))->toBe([]);
    Event::assertNotDispatched(TurnInputClosed::class);
});

it('records hello input_closed as a capability', function () {
    $manager = new BridgeConnectionManager();
    $manager->addConnection('user-1', 'conn-1');
    $handler = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());

    $handler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'bridge_version' => '0.25.0',
        'providers' => [],
        'turn_input' => true,
        'input_closed' => true,
    ]));

    expect($manager->bridgeSupports('user-1', 'input_closed'))->toBeTrue()
        ->and($manager->getBridgeInfo('user-1')['capabilities'])->toBe(['turn_input', 'input_closed'])
        ->and(MessageHandler::bridgeInfoFromHello(['type' => 'hello', 'turn_input' => true])['capabilities'])
        ->toBe(['turn_input']);
});
