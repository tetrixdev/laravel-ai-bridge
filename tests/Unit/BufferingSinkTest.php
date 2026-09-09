<?php

declare(strict_types=1);

use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Streaming\BufferingSink;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| BufferingSink tests
|--------------------------------------------------------------------------
|
| Verifies the contract between StreamHandler events and the stream-event
| buffer the SSE tail reads. Thinking-block suppression matches the
| broadcast/SSE wiring so what the buffer carries matches what a UI replay
| expects to see.
|
*/

function fakeBufferProvider(): StreamableProvider
{
    return new class implements StreamableProvider {
        public function setConversationId(string $c): static { return $this; }
        public function setMessage(string $m): static { return $this; }
        public function setOptions(array $o): static { return $this; }
        public function start(): void {}
        public function cancel(): void {}
        public function markCompleted(): void {}
        public function getStreamHandler(): StreamHandler { throw new RuntimeException('n/a'); }
    };
}

test('attach() routes every block event into the buffer in order', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-1');
    BufferingSink::attach($handler, $store);

    $handler->dispatchBlockStart(BlockType::Text, 0);
    $handler->dispatchBlockDelta(BlockType::Text, 0, 'hi');
    $handler->dispatchBlockStop(BlockType::Text, 0);

    $events = $store->range('rid-1');
    expect(array_column($events, 'event'))->toBe(['block_start', 'block_delta', 'block_stop']);
    expect($events[1]['data']['content'])->toBe('hi');
});

test('attach() suppresses thinking blocks when configured (default)', function () {
    config()->set('ai-bridge.streaming.suppress_thinking_blocks', true);

    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-think');
    BufferingSink::attach($handler, $store);

    $handler->dispatchBlockStart(BlockType::Thinking, 0);
    $handler->dispatchBlockDelta(BlockType::Thinking, 0, 'reasoning');
    $handler->dispatchBlockStop(BlockType::Thinking, 0);
    $handler->dispatchBlockStart(BlockType::Text, 1);
    $handler->dispatchBlockDelta(BlockType::Text, 1, 'visible');
    $handler->dispatchBlockStop(BlockType::Text, 1);

    $events = $store->range('rid-think');
    expect(array_column($events, 'event'))->toBe(['block_start', 'block_delta', 'block_stop']);
    expect($events[1]['data']['content'])->toBe('visible');
});

test('attach() forwards thinking blocks when suppression is off', function () {
    config()->set('ai-bridge.streaming.suppress_thinking_blocks', false);

    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-think-2');
    BufferingSink::attach($handler, $store);

    $handler->dispatchBlockStart(BlockType::Thinking, 0);
    $handler->dispatchBlockDelta(BlockType::Thinking, 0, 'r');
    $handler->dispatchBlockStop(BlockType::Thinking, 0);

    $events = $store->range('rid-think-2');
    expect($events)->toHaveCount(3);
});

test('attach() flips status to completed on done', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-done');
    BufferingSink::attach($handler, $store);

    $handler->dispatchDone(['total_tokens' => 5]);

    expect($store->status('rid-done')['status'])->toBe('completed');
    $events = $store->range('rid-done');
    expect(end($events)['data']['usage'])->toBe(['total_tokens' => 5]);
});

test('attach() flips status to failed on error', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-fail');
    BufferingSink::attach($handler, $store);

    $handler->dispatchError('boom', 'something exploded');

    expect($store->status('rid-fail')['status'])->toBe('failed');
});

test('attach() flips status to cancelled on cancelled', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-cancel');
    BufferingSink::attach($handler, $store);

    $handler->dispatchCancelled('user');

    expect($store->status('rid-cancel')['status'])->toBe('cancelled');
});

test('attach() buffers tool_call as a single event', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-tool');
    BufferingSink::attach($handler, $store);

    $handler->dispatchToolCall('search', ['q' => 'cats'], 'c-1');

    $events = $store->range('rid-tool');
    expect($events)->toHaveCount(1);
    expect($events[0]['event'])->toBe('tool_call');
    expect($events[0]['data'])->toBe([
        'tool_name' => 'search',
        'parameters' => ['q' => 'cats'],
        'tool_call_id' => 'c-1',
    ]);
});

test('the buffer carries a tool result, including whether it failed', function () {
    // Without a tool_result handler here, a result could never reach a browser
    // even once the bridge started sending them.
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-tr');
    BufferingSink::attach($handler, $store);

    $handler->dispatchToolResult('toolu_1', 'boom', true);

    $events = $store->range('rid-tr');

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('tool_result')
        ->and($events[0]['data']['result'])->toBe('boom')
        ->and($events[0]['data']['is_error'])->toBeTrue();
});

test('a null tool result keeps its key, so the call does not look forever-running', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-null');
    BufferingSink::attach($handler, $store);

    $handler->dispatchToolResult('toolu_1', null, true);

    expect($store->range('rid-null')[0]['data'])->toHaveKey('result');
});

test('the buffer carries rate limit status without ending the turn', function () {
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-rl');
    BufferingSink::attach($handler, $store);

    $handler->dispatchRateLimit('claude', ['status' => 'allowed']);

    $events = $store->range('rid-rl');

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('rate_limit')
        ->and($events[0]['data']['provider'])->toBe('claude');
});

test('a browser sees the documented turn metadata and not the session handle', function () {
    // An allowlist, not a denylist: `done` carries whatever the provider chose
    // to report, and a denylist forwards every future field by default —
    // including one nobody has evaluated yet.
    $store = new ArrayStreamStore();
    $handler = new StreamHandler(fakeBufferProvider(), 'rid-meta');
    BufferingSink::attach($handler, $store);

    $handler->dispatchDone(['input_tokens' => 1], [
        'model' => 'claude-sonnet-5',
        'cost_usd' => 0.01,
        'cli_session_id' => 'sess-secret',
        'some_future_field' => 'not yet evaluated',
    ]);

    $done = collect($store->range('rid-meta'))->firstWhere('event', 'done');

    expect($done['data']['model'])->toBe('claude-sonnet-5')
        ->and($done['data']['cost_usd'])->toBe(0.01)
        ->and($done['data'])->not->toHaveKey('cli_session_id')
        ->and($done['data'])->not->toHaveKey('some_future_field');
});
