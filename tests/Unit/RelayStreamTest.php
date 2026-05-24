<?php

declare(strict_types=1);

use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\RelayStream;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| RelayStream Unit Tests
|--------------------------------------------------------------------------
|
| RelayStream is the serve-side counterpart of BridgeStream. It pipes relayed
| (PHP-FPM) request events into the stream-event buffer (via BufferingSink)
| so the browser can tail them over SSE, and persists the assistant message
| once the turn terminates.
|
*/

beforeEach(function () {
    // Bind a fresh in-process ArrayStreamStore so each test starts empty and
    // can assert directly against its state.
    $this->store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $this->store);
});

test('RelayStream binds the supplied request ID to its StreamHandler', function () {
    $relay = new RelayStream('req-relay-1', '42');

    expect($relay->getStreamHandler())->toBeInstanceOf(StreamHandler::class);
    expect($relay->getStreamHandler()->requestId)->toBe('req-relay-1');
});

test('block events land in the stream buffer with monotonic indexes', function () {
    $relay = new RelayStream('req-relay-2', '99');
    $relay->getStreamHandler()->dispatchBlockStart(BlockType::Text, 0);
    $relay->getStreamHandler()->dispatchBlockDelta(BlockType::Text, 0, 'hello');
    $relay->getStreamHandler()->dispatchBlockDelta(BlockType::Text, 0, ' world');
    $relay->getStreamHandler()->dispatchBlockStop(BlockType::Text, 0);

    $events = $this->store->range('req-relay-2');

    expect($events)->toHaveCount(4);
    expect($events[0]['event'])->toBe('block_start');
    expect($events[1]['event'])->toBe('block_delta');
    expect($events[1]['data']['content'])->toBe('hello');
    expect($events[2]['data']['content'])->toBe(' world');
    expect($events[3]['event'])->toBe('block_stop');
    expect(array_column($events, 'index'))->toBe([0, 1, 2, 3]);
});

test('tool_call is buffered with tool_name, parameters and tool_call_id', function () {
    $relay = new RelayStream('req-relay-3', '99');
    $relay->getStreamHandler()->dispatchToolCall('search', ['q' => 'cats'], 'call-1');

    $events = $this->store->range('req-relay-3');
    expect($events)->toHaveCount(1);
    expect($events[0]['event'])->toBe('tool_call');
    expect($events[0]['data'])->toBe([
        'tool_name' => 'search',
        'parameters' => ['q' => 'cats'],
        'tool_call_id' => 'call-1',
    ]);
});

test('done event flips buffer status to completed', function () {
    $relay = new RelayStream('req-relay-4', '99');
    $relay->getStreamHandler()->dispatchDone(['total_tokens' => 12]);

    $status = $this->store->status('req-relay-4');
    expect($status['status'])->toBe('completed');
    // The done event itself is also buffered so SSE consumers see a terminal.
    $events = $this->store->range('req-relay-4');
    expect(end($events))->toMatchArray([
        'event' => 'done',
        'data' => ['usage' => ['total_tokens' => 12]],
    ]);
});

test('error event flips buffer status to failed', function () {
    $relay = new RelayStream('req-relay-5', '99');
    $relay->getStreamHandler()->dispatchError('rate_limited', 'Too many requests');

    expect($this->store->status('req-relay-5')['status'])->toBe('failed');
});

test('cancelled event flips buffer status to cancelled', function () {
    $relay = new RelayStream('req-relay-6', '99');
    $relay->getStreamHandler()->dispatchCancelled('User cancelled');

    expect($this->store->status('req-relay-6')['status'])->toBe('cancelled');
});

test('a buffer failure does not bubble up out of dispatch', function () {
    // Replace the store binding with one that throws on every operation. The
    // sink must log and swallow — a broken buffer must never break the stream
    // itself (the recorder still finalises the message in the DB).
    $boom = new class implements StreamStoreContract {
        public function start(string $r, array $m = []): void { throw new RuntimeException('boom'); }
        public function appendEvent(string $r, string $e, array $d): int { throw new RuntimeException('boom'); }
        public function range(string $r, int $f = -1): array { throw new RuntimeException('boom'); }
        public function status(string $r): array { throw new RuntimeException('boom'); }
        public function setAbort(string $r): void { throw new RuntimeException('boom'); }
        public function isAborted(string $r): bool { throw new RuntimeException('boom'); }
        public function complete(string $r, string $s): void { throw new RuntimeException('boom'); }
        public function cleanup(string $r): void { throw new RuntimeException('boom'); }
    };
    app()->instance(StreamStoreContract::class, $boom);

    $relay = new RelayStream('req-relay-7', '99');

    // None of these may throw — buffer failures must be swallowed.
    $relay->getStreamHandler()->dispatchBlockDelta(BlockType::Text, 0, 'x');
    $relay->getStreamHandler()->dispatchToolCall('t', [], 'c');
    $relay->getStreamHandler()->dispatchDone(null);

    expect(true)->toBeTrue();
});

test('start, cancel and markCompleted are no-ops and do not throw', function () {
    $relay = new RelayStream('req-relay-8', '1');

    $relay->start();
    $relay->cancel();
    $relay->markCompleted();

    expect($relay->setMessage('hi'))->toBe($relay);
    expect($relay->setOptions(['a' => 1]))->toBe($relay);
    expect($relay->setConversationId('2'))->toBe($relay);
});
