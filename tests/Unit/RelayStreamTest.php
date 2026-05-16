<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Broadcasting\AiStreamEvent;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Streaming\RelayStream;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| RelayStream Unit Tests
|--------------------------------------------------------------------------
|
| RelayStream is the serve-side counterpart of BridgeStream. It re-broadcasts
| relayed (PHP-FPM) request events via AiStreamEvent so the browser receives
| stream events and tool calls can verify+execute against a registered owner.
|
*/

test('RelayStream binds the supplied request ID to its StreamHandler', function () {
    $relay = new RelayStream('req-relay-1', 'user.1.conversation.42', '42');

    expect($relay->getStreamHandler())->toBeInstanceOf(StreamHandler::class);
    expect($relay->getStreamHandler()->requestId)->toBe('req-relay-1');
});

test('block events are broadcast as AiStreamEvent on the expected channel', function () {
    Event::fake([AiStreamEvent::class]);

    $relay = new RelayStream('req-relay-2', 'user.7.conversation.99', '99');
    $relay->getStreamHandler()->dispatchBlockDelta(BlockType::Text, 0, 'hello');

    Event::assertDispatched(AiStreamEvent::class, function (AiStreamEvent $event) {
        return $event->requestId === 'req-relay-2'
            && $event->event === 'block_delta'
            && $event->data['content'] === 'hello'
            && $event->broadcastOn()->name === 'private-user.7.conversation.99';
    });
});

test('tool_call is broadcast with tool_name, parameters and tool_call_id', function () {
    Event::fake([AiStreamEvent::class]);

    $relay = new RelayStream('req-relay-3', 'user.7.conversation.99', '99');
    $relay->getStreamHandler()->dispatchToolCall('search', ['q' => 'cats'], 'call-1');

    Event::assertDispatched(AiStreamEvent::class, function (AiStreamEvent $event) {
        return $event->event === 'tool_call'
            && $event->data === [
                'tool_name' => 'search',
                'parameters' => ['q' => 'cats'],
                'tool_call_id' => 'call-1',
            ];
    });
});

test('done event is broadcast with usage data', function () {
    Event::fake([AiStreamEvent::class]);

    $relay = new RelayStream('req-relay-4', 'user.7.conversation.99', '99');
    $relay->getStreamHandler()->dispatchDone(['total_tokens' => 12]);

    Event::assertDispatched(AiStreamEvent::class, function (AiStreamEvent $event) {
        return $event->event === 'done'
            && $event->data === ['usage' => ['total_tokens' => 12]];
    });
});

test('error event is broadcast with code and message', function () {
    Event::fake([AiStreamEvent::class]);

    $relay = new RelayStream('req-relay-5', 'user.7.conversation.99', '99');
    $relay->getStreamHandler()->dispatchError('rate_limited', 'Too many requests');

    Event::assertDispatched(AiStreamEvent::class, function (AiStreamEvent $event) {
        return $event->event === 'error'
            && $event->data === ['code' => 'rate_limited', 'message' => 'Too many requests'];
    });
});

test('cancelled event is broadcast with reason', function () {
    Event::fake([AiStreamEvent::class]);

    $relay = new RelayStream('req-relay-6', 'user.7.conversation.99', '99');
    $relay->getStreamHandler()->dispatchCancelled('User cancelled');

    Event::assertDispatched(AiStreamEvent::class, function (AiStreamEvent $event) {
        return $event->event === 'cancelled'
            && $event->data === ['reason' => 'User cancelled'];
    });
});

test('a broadcast failure does not bubble up out of dispatch', function () {
    // Replace the broadcast factory so dispatching an AiStreamEvent (a
    // ShouldBroadcastNow event) throws — simulating a missing/misconfigured
    // Reverb. RelayStream must swallow the failure, not propagate it.
    app()->bind(\Illuminate\Contracts\Broadcasting\Factory::class, function () {
        return new class implements \Illuminate\Contracts\Broadcasting\Factory
        {
            public function connection($name = null)
            {
                throw new RuntimeException('broadcast driver exploded');
            }
        };
    });

    $relay = new RelayStream('req-relay-7', 'user.7.conversation.99', '99');

    // None of these may throw — broadcasting failures must be swallowed.
    $relay->getStreamHandler()->dispatchBlockDelta(BlockType::Text, 0, 'x');
    $relay->getStreamHandler()->dispatchToolCall('t', [], 'c');
    $relay->getStreamHandler()->dispatchDone(null);

    expect(true)->toBeTrue();
});

test('start, cancel and markCompleted are no-ops and do not throw', function () {
    $relay = new RelayStream('req-relay-8', 'user.1.conversation.1', '1');

    $relay->start();
    $relay->cancel();
    $relay->markCompleted();

    expect($relay->setMessage('hi'))->toBe($relay);
    expect($relay->setOptions(['a' => 1]))->toBe($relay);
    expect($relay->setConversationId('2'))->toBe($relay);
});
