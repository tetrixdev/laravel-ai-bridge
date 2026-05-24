<?php

declare(strict_types=1);

use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Facades\StreamStore;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;
use Tetrix\AiBridge\Streaming\StreamStoreManager;

/*
|--------------------------------------------------------------------------
| StreamStore tests
|--------------------------------------------------------------------------
|
| Driver semantics (ArrayStreamStore as the in-process baseline), plus the
| Laravel-idiomatic manager/facade wiring.
|
| The Redis driver is exercised end-to-end in the dev stack; in unit tests
| we rely on the contract conformance covered here being identical across
| drivers (same interface, same return shapes).
|
*/

test('ArrayStreamStore — start and status round-trip metadata', function () {
    $store = new ArrayStreamStore();
    $store->start('req-1', ['conversation_id' => '42', 'provider' => 'claude']);

    $status = $store->status('req-1');
    expect($status['status'])->toBe('streaming');
    expect($status['event_count'])->toBe(0);
    expect($status['last_event_index'])->toBe(-1);
    expect($status['metadata'])->toBe(['conversation_id' => '42', 'provider' => 'claude']);
});

test('ArrayStreamStore — appendEvent assigns monotonic indexes from zero', function () {
    $store = new ArrayStreamStore();
    $store->start('req-2');

    expect($store->appendEvent('req-2', 'block_start', ['block_type' => 'text']))->toBe(0);
    expect($store->appendEvent('req-2', 'block_delta', ['content' => 'a']))->toBe(1);
    expect($store->appendEvent('req-2', 'block_delta', ['content' => 'b']))->toBe(2);
    expect($store->status('req-2')['event_count'])->toBe(3);
    expect($store->status('req-2')['last_event_index'])->toBe(2);
});

test('ArrayStreamStore — appendEvent auto-creates when no start() was called', function () {
    $store = new ArrayStreamStore();
    expect($store->appendEvent('req-auto', 'block_start', []))->toBe(0);
    expect($store->status('req-auto')['status'])->toBe('streaming');
});

test('ArrayStreamStore — range(-1) returns the full log', function () {
    $store = new ArrayStreamStore();
    $store->appendEvent('r', 'a', ['i' => 0]);
    $store->appendEvent('r', 'b', ['i' => 1]);
    $store->appendEvent('r', 'c', ['i' => 2]);

    expect($store->range('r'))->toHaveCount(3);
    expect($store->range('r')[0]['event'])->toBe('a');
    expect($store->range('r')[2]['event'])->toBe('c');
});

test('ArrayStreamStore — range(fromIndex) returns events with index > fromIndex', function () {
    $store = new ArrayStreamStore();
    $store->appendEvent('r', 'a', []);
    $store->appendEvent('r', 'b', []);
    $store->appendEvent('r', 'c', []);

    $tail = $store->range('r', 0);
    expect($tail)->toHaveCount(2);
    expect($tail[0]['event'])->toBe('b');
    expect($tail[1]['event'])->toBe('c');

    expect($store->range('r', 1))->toHaveCount(1);
    expect($store->range('r', 2))->toBe([]);
    expect($store->range('r', 99))->toBe([]);
});

test('ArrayStreamStore — complete() flips status and is observable via status()', function () {
    $store = new ArrayStreamStore();
    $store->start('r');
    $store->complete('r', 'completed');
    expect($store->status('r')['status'])->toBe('completed');
});

test('ArrayStreamStore — setAbort and isAborted', function () {
    $store = new ArrayStreamStore();
    $store->start('r');
    expect($store->isAborted('r'))->toBeFalse();
    $store->setAbort('r');
    expect($store->isAborted('r'))->toBeTrue();
});

test('ArrayStreamStore — setAbort works before start() (race-aborting caller)', function () {
    $store = new ArrayStreamStore();
    $store->setAbort('r-early');
    expect($store->isAborted('r-early'))->toBeTrue();
});

test('ArrayStreamStore — not_found status for unknown turn', function () {
    $store = new ArrayStreamStore();
    $status = $store->status('nope');
    expect($status['status'])->toBe('not_found');
    expect($status['event_count'])->toBe(0);
    expect($status['last_event_index'])->toBe(-1);
});

test('ArrayStreamStore — cleanup removes the entry', function () {
    $store = new ArrayStreamStore();
    $store->appendEvent('r', 'a', []);
    $store->cleanup('r');
    expect($store->status('r')['status'])->toBe('not_found');
});

test('StreamStoreManager — resolves the default driver from config', function () {
    config()->set('ai-bridge.stream_store.default', 'array');
    $manager = new StreamStoreManager(app());

    expect($manager->driver())->toBeInstanceOf(ArrayStreamStore::class);
});

test('StreamStoreManager — apps can register their own driver', function () {
    $manager = new StreamStoreManager(app());
    $custom = new ArrayStreamStore();
    $manager->extend('custom', fn () => $custom);

    expect($manager->driver('custom'))->toBe($custom);
});

test('StreamStore facade — round-trips through the default driver', function () {
    // The facade resolves to the contract binding; the test harness pins the
    // contract to the array driver, so writes here are visible via the facade.
    StreamStore::start('facade-rid', ['conversation_id' => '7']);
    StreamStore::appendEvent('facade-rid', 'block_delta', ['content' => 'hi']);

    expect(StreamStore::status('facade-rid')['event_count'])->toBe(1);
    expect(StreamStore::range('facade-rid')[0]['event'])->toBe('block_delta');
});

test('StreamStoreContract binding resolves to a driver instance', function () {
    expect(app(StreamStoreContract::class))->toBeInstanceOf(StreamStoreContract::class);
});
