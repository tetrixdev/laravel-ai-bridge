<?php

declare(strict_types=1);

use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| StreamHandler Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify that StreamHandler dispatches events to callbacks
| correctly, including terminal event guards and cancellation semantics.
|
*/


function createStreamHandler(): StreamHandler
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

test('onBlockStart callback fires with correct event', function () {
    $handler = createStreamHandler();
    $received = null;

    $handler->onBlockStart(function (StreamEvent $event) use (&$received) {
        $received = $event;
    });

    $handler->dispatchBlockStart(BlockType::Text, 0);

    expect($received)->not->toBeNull();
    expect($received->event)->toBe(MessageTypes::BLOCK_START);
    expect($received->data['block_type'])->toBe('text');
    expect($received->data['block_index'])->toBe(0);
});

test('onBlockDelta callback fires with content', function () {
    $handler = createStreamHandler();
    $received = null;

    $handler->onBlockDelta(function (StreamEvent $event) use (&$received) {
        $received = $event;
    });

    $handler->dispatchBlockDelta(BlockType::Text, 0, 'Hello world');

    expect($received)->not->toBeNull();
    expect($received->data['content'])->toBe('Hello world');
    expect($received->data['block_type'])->toBe('text');
    expect($received->data['block_index'])->toBe(0);
});

test('onBlockStop callback fires correctly', function () {
    $handler = createStreamHandler();
    $received = null;

    $handler->onBlockStop(function (StreamEvent $event) use (&$received) {
        $received = $event;
    });

    $handler->dispatchBlockStop(BlockType::Text, 0);

    expect($received)->not->toBeNull();
    expect($received->event)->toBe(MessageTypes::BLOCK_STOP);
    expect($received->data['block_type'])->toBe('text');
});

test('onDone callback fires with usage data', function () {
    $handler = createStreamHandler();
    $receivedUsage = null;

    $handler->onDone(function (?array $usage) use (&$receivedUsage) {
        $receivedUsage = $usage;
    });

    $usage = ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30];
    $handler->dispatchDone($usage);

    expect($receivedUsage)->toBe($usage);
});

test('onDone callback fires with null usage', function () {
    $handler = createStreamHandler();
    $fired = false;

    $handler->onDone(function (?array $usage) use (&$fired) {
        $fired = true;
        expect($usage)->toBeNull();
    });

    $handler->dispatchDone(null);

    expect($fired)->toBeTrue();
});

test('onError callback fires with code and message', function () {
    $handler = createStreamHandler();
    $receivedCode = null;
    $receivedMessage = null;

    $handler->onError(function (string $code, string $message) use (&$receivedCode, &$receivedMessage) {
        $receivedCode = $code;
        $receivedMessage = $message;
    });

    $handler->dispatchError('rate_limited', 'Too many requests');

    expect($receivedCode)->toBe('rate_limited');
    expect($receivedMessage)->toBe('Too many requests');
});

test('onToolCall callback fires with name, params, and callId', function () {
    $handler = createStreamHandler();
    $receivedName = null;
    $receivedParams = null;
    $receivedCallId = null;

    $handler->onToolCall(function (string $name, array $params, string $callId) use (&$receivedName, &$receivedParams, &$receivedCallId) {
        $receivedName = $name;
        $receivedParams = $params;
        $receivedCallId = $callId;
    });

    $handler->dispatchToolCall('search', ['query' => 'test'], 'call-123');

    expect($receivedName)->toBe('search');
    expect($receivedParams)->toBe(['query' => 'test']);
    expect($receivedCallId)->toBe('call-123');
});

test('onCancelled callback fires on cancellation', function () {
    $handler = createStreamHandler();
    $receivedReason = null;

    $handler->onCancelled(function (string $reason) use (&$receivedReason) {
        $receivedReason = $reason;
    });

    $handler->dispatchCancelled('User cancelled');

    expect($receivedReason)->toBe('User cancelled');
});

test('dispatchDone does NOT fire when cancelled (BL-002)', function () {
    $handler = createStreamHandler();
    $doneFired = false;

    $handler->onDone(function () use (&$doneFired) {
        $doneFired = true;
    });

    // Simulate cancellation
    $handler->start();
    $handler->cancel();

    // Attempt to dispatch done after cancellation
    $handler->dispatchDone(null);

    expect($doneFired)->toBeFalse();
});

test('dispatchDone does NOT fire after terminated (idempotent)', function () {
    $handler = createStreamHandler();
    $doneCount = 0;

    $handler->onDone(function () use (&$doneCount) {
        $doneCount++;
    });

    // First done should fire
    $handler->dispatchDone(null);

    // Second done should be suppressed by terminal guard
    $handler->dispatchDone(null);

    expect($doneCount)->toBe(1);
});

test('double done dispatch prevention (terminal event guard)', function () {
    $handler = createStreamHandler();
    $doneCount = 0;
    $errorCount = 0;

    $handler->onDone(function () use (&$doneCount) { $doneCount++; });
    $handler->onError(function () use (&$errorCount) { $errorCount++; });

    // Done fires first
    $handler->dispatchDone(null);

    // Error should be suppressed since done already terminated
    $handler->dispatchError('some_error', 'Should not fire');

    // Another done also suppressed
    $handler->dispatchDone(null);

    expect($doneCount)->toBe(1);
    expect($errorCount)->toBe(0);
});

test('error then done: error fires, done is suppressed', function () {
    $handler = createStreamHandler();
    $errorFired = false;
    $doneFired = false;

    $handler->onError(function () use (&$errorFired) { $errorFired = true; });
    $handler->onDone(function () use (&$doneFired) { $doneFired = true; });

    $handler->dispatchError('test_error', 'Test');
    $handler->dispatchDone(null);

    expect($errorFired)->toBeTrue();
    expect($doneFired)->toBeFalse();
});

test('dispatchEvent routes block_start correctly', function () {
    $handler = createStreamHandler();
    $received = null;

    $handler->onBlockStart(function (StreamEvent $event) use (&$received) {
        $received = $event;
    });

    $event = StreamEvent::blockStart('req-1', BlockType::Text, 0);
    $handler->dispatchEvent($event);

    expect($received)->not->toBeNull();
    expect($received->data['block_type'])->toBe('text');
});

test('dispatchEvent routes block_delta correctly', function () {
    $handler = createStreamHandler();
    $received = null;

    $handler->onBlockDelta(function (StreamEvent $event) use (&$received) {
        $received = $event;
    });

    $event = StreamEvent::blockDelta('req-1', BlockType::Text, 0, 'content');
    $handler->dispatchEvent($event);

    expect($received)->not->toBeNull();
    expect($received->data['content'])->toBe('content');
});

test('dispatchEvent routes bridge-style block_delta with no block_type', function () {
    // The bridge protocol sends block_type only on block_start; block_delta
    // and block_stop carry block_index alone. The handler must still route
    // these (regression: deltas were silently dropped when block_type missing).
    $handler = createStreamHandler();
    $deltas = [];
    $stops = [];

    $handler->onBlockDelta(function (StreamEvent $event) use (&$deltas) {
        $deltas[] = $event->data['content'];
    });
    $handler->onBlockStop(function (StreamEvent $event) use (&$stops) {
        $stops[] = $event->data['block_index'];
    });

    $handler->dispatchEvent(new StreamEvent('req-1', 'block_start', ['block_index' => 0, 'block_type' => 'text']));
    $handler->dispatchEvent(new StreamEvent('req-1', 'block_delta', ['block_index' => 0, 'content' => 'Hello']));
    $handler->dispatchEvent(new StreamEvent('req-1', 'block_delta', ['block_index' => 0, 'content' => ' world']));
    $handler->dispatchEvent(new StreamEvent('req-1', 'block_stop', ['block_index' => 0]));

    expect($deltas)->toBe(['Hello', ' world']);
    expect($stops)->toBe([0]);
});

test('dispatchEvent routes done correctly', function () {
    $handler = createStreamHandler();
    $usage = null;

    $handler->onDone(function (?array $u) use (&$usage) {
        $usage = $u;
    });

    $event = StreamEvent::done('req-1', ['total_tokens' => 100]);
    $handler->dispatchEvent($event);

    expect($usage)->toBe(['total_tokens' => 100]);
});

test('dispatchEvent routes error correctly', function () {
    $handler = createStreamHandler();
    $code = null;

    $handler->onError(function (string $c, string $m) use (&$code) {
        $code = $c;
    });

    $event = StreamEvent::error('req-1', 'api_error', 'Something went wrong');
    $handler->dispatchEvent($event);

    expect($code)->toBe('api_error');
});

test('dispatchEvent routes tool_call correctly', function () {
    $handler = createStreamHandler();
    $toolName = null;

    $handler->onToolCall(function (string $name, array $params, string $callId) use (&$toolName) {
        $toolName = $name;
    });

    $event = StreamEvent::toolCall('req-1', 'search', ['q' => 'test'], 'call-1');
    $handler->dispatchEvent($event);

    expect($toolName)->toBe('search');
});

test('tool result callback support', function () {
    $handler = createStreamHandler();
    $receivedCallId = null;
    $receivedResult = null;

    $handler->onToolResult(function (string $callId, mixed $result) use (&$receivedCallId, &$receivedResult) {
        $receivedCallId = $callId;
        $receivedResult = $result;
    });

    $handler->dispatchToolResult('call-abc', ['data' => 'test']);

    expect($receivedCallId)->toBe('call-abc');
    expect($receivedResult)->toBe(['data' => 'test']);
});

test('dispatchToolResult is suppressed when cancelled', function () {
    $handler = createStreamHandler();
    $fired = false;

    $handler->onToolResult(function () use (&$fired) {
        $fired = true;
    });

    $handler->start();
    $handler->cancel();

    $handler->dispatchToolResult('call-1', 'result');

    expect($fired)->toBeFalse();
});

test('dispatchToolResult is suppressed when terminated', function () {
    $handler = createStreamHandler();
    $fired = false;

    $handler->onToolResult(function () use (&$fired) {
        $fired = true;
    });

    $handler->dispatchDone(null);
    $handler->dispatchToolResult('call-1', 'result');

    expect($fired)->toBeFalse();
});

test('block events are suppressed when cancelled', function () {
    $handler = createStreamHandler();
    $startFired = false;
    $deltaFired = false;
    $stopFired = false;

    $handler->onBlockStart(function () use (&$startFired) { $startFired = true; });
    $handler->onBlockDelta(function () use (&$deltaFired) { $deltaFired = true; });
    $handler->onBlockStop(function () use (&$stopFired) { $stopFired = true; });

    $handler->start();
    $handler->cancel();

    $handler->dispatchBlockStart(BlockType::Text, 0);
    $handler->dispatchBlockDelta(BlockType::Text, 0, 'content');
    $handler->dispatchBlockStop(BlockType::Text, 0);

    expect($startFired)->toBeFalse();
    expect($deltaFired)->toBeFalse();
    expect($stopFired)->toBeFalse();
});

test('multiple callbacks for the same event all fire', function () {
    $handler = createStreamHandler();
    $count = 0;

    $handler->onBlockDelta(function () use (&$count) { $count++; });
    $handler->onBlockDelta(function () use (&$count) { $count++; });
    $handler->onBlockDelta(function () use (&$count) { $count++; });

    $handler->dispatchBlockDelta(BlockType::Text, 0, 'Hello');

    expect($count)->toBe(3);
});

test('requestId is a UUID string', function () {
    $handler = createStreamHandler();

    expect($handler->requestId)->toBeString();
    // UUID format: 8-4-4-4-12 hex characters
    expect($handler->requestId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

test('constructor honors an explicit requestId', function () {
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider, 'req-explicit-123');

    expect($handler->requestId)->toBe('req-explicit-123');
});

test('callback exceptions are caught and do not crash dispatch', function () {
    $handler = createStreamHandler();
    $secondFired = false;

    $handler->onBlockDelta(function () {
        throw new RuntimeException('Callback boom');
    });

    $handler->onBlockDelta(function () use (&$secondFired) {
        $secondFired = true;
    });

    // Should not throw, and second callback should still fire
    $handler->dispatchBlockDelta(BlockType::Text, 0, 'test');

    expect($secondFired)->toBeTrue();
});

test('dispatchCancelled sets terminated flag', function () {
    $handler = createStreamHandler();
    $doneFired = false;

    $handler->onDone(function () use (&$doneFired) { $doneFired = true; });

    $handler->dispatchCancelled('user request');

    // After cancelled (which sets terminated), done should not fire
    $handler->dispatchDone(null);

    expect($doneFired)->toBeFalse();
});
