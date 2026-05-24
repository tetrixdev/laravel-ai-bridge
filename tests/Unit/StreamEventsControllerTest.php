<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Http\Controllers\StreamEventsController;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore;

/*
|--------------------------------------------------------------------------
| StreamEventsController tests
|--------------------------------------------------------------------------
|
| Authorization (via the conversations resolver), status/abort response
| shape, and 404/403 paths. The SSE long-poll endpoint itself is exercised
| live in the dev stack — pulling it apart here would only test the loop
| scaffold, not the contract.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = new ArrayStreamStore();
    app()->instance(StreamStoreContract::class, $this->store);

    $this->controller = new StreamEventsController(
        app(AiBridgeManager::class),
        $this->store,
    );

    $this->conversation = Conversation::create([
        'mode' => 'bridge',
        'provider' => 'claude',
    ]);

    // By default the resolver scopes to the test conversation. Tests that
    // need 403 paths override this to return an empty query.
    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn () => Conversation::query()->whereKey($this->conversation->id)
    );
});

test('status() returns 404 for unknown request_id', function () {
    $res = $this->controller->status(Request::create('/'), 'unknown-rid');

    expect($res->getStatusCode())->toBe(404);
});

test('status() returns 403 when the conversation is out of scope', function () {
    $this->store->start('rid-1', ['conversation_id' => (string) $this->conversation->id]);
    // Resolver returns no conversations -> authorize fails.
    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn () => Conversation::query()->whereRaw('1=0')
    );

    $res = $this->controller->status(Request::create('/'), 'rid-1');

    expect($res->getStatusCode())->toBe(403);
});

test('status() returns the buffer snapshot', function () {
    $this->store->start('rid-2', ['conversation_id' => (string) $this->conversation->id]);
    $this->store->appendEvent('rid-2', 'block_delta', ['content' => 'hi']);

    $res = $this->controller->status(Request::create('/'), 'rid-2');

    expect($res->getStatusCode())->toBe(200);
    $body = json_decode($res->getContent(), true);
    expect($body['status'])->toBe('streaming');
    expect($body['event_count'])->toBe(1);
    expect($body['last_event_index'])->toBe(0);
    expect($body['metadata']['conversation_id'])->toBe((string) $this->conversation->id);
});

test('abort() sets the buffer flag and returns 200', function () {
    $this->store->start('rid-3', ['conversation_id' => (string) $this->conversation->id]);

    $res = $this->controller->abort(Request::create('/'), 'rid-3');

    expect($res->getStatusCode())->toBe(200);
    expect($this->store->isAborted('rid-3'))->toBeTrue();
});

test('abort() returns 404 for unknown request_id', function () {
    $res = $this->controller->abort(Request::create('/'), 'nope');
    expect($res->getStatusCode())->toBe(404);
});

test('abort() returns 403 when the conversation is out of scope', function () {
    $this->store->start('rid-4', ['conversation_id' => (string) $this->conversation->id]);
    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn () => Conversation::query()->whereRaw('1=0')
    );

    $res = $this->controller->abort(Request::create('/'), 'rid-4');

    expect($res->getStatusCode())->toBe(403);
    expect($this->store->isAborted('rid-4'))->toBeFalse();
});

test('endpoints reject a turn with no conversation_id in metadata', function () {
    // Server-only turns (no conversation linkage) must never be reachable
    // from the browser endpoints.
    $this->store->start('rid-orphan', []);
    $res = $this->controller->status(Request::create('/'), 'rid-orphan');
    expect($res->getStatusCode())->toBe(403);
});

test('events() returns 404 for unknown request_id', function () {
    $res = $this->controller->events(Request::create('/'), 'nope');
    expect($res->getStatusCode())->toBe(404);
});

test('events() returns 403 when the conversation is out of scope', function () {
    $this->store->start('rid-5', ['conversation_id' => (string) $this->conversation->id]);
    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn () => Conversation::query()->whereRaw('1=0')
    );

    $res = $this->controller->events(Request::create('/'), 'rid-5');

    expect($res->getStatusCode())->toBe(403);
});

/*
| SSE resume-index resolution
|
| The full SSE long-poll is verified live in the dev stack (curl with
| `-H "Last-Event-ID: N"` and `?from_index=N`). These unit tests pin the
| controller's resume-index resolution rule directly, which is the piece
| most likely to regress.
*/

test('Last-Event-ID header wins over from_index query', function () {
    $req = Request::create('/', 'GET', ['from_index' => '0']);
    $req->headers->set('Last-Event-ID', '5');

    expect(StreamEventsController::resolveFromIndex($req))->toBe(5);
});

test('from_index query is honoured when no Last-Event-ID header', function () {
    $req = Request::create('/', 'GET', ['from_index' => '3']);

    expect(StreamEventsController::resolveFromIndex($req))->toBe(3);
});

test('no header and no query yields -1 (replay from start)', function () {
    expect(StreamEventsController::resolveFromIndex(Request::create('/')))->toBe(-1);
});

test('empty from_index does not silently become 0 (would skip event 0)', function () {
    // Regression guard: (int) '' === 0 would mean "give me events with
    // index > 0", skipping event 0 entirely. Must fall back to -1.
    $req = Request::create('/', 'GET', ['from_index' => '']);

    expect(StreamEventsController::resolveFromIndex($req))->toBe(-1);
});

test('non-numeric from_index falls back to -1', function () {
    $req = Request::create('/', 'GET', ['from_index' => 'garbage']);

    expect(StreamEventsController::resolveFromIndex($req))->toBe(-1);
});

test('non-numeric Last-Event-ID falls back to from_index query', function () {
    $req = Request::create('/', 'GET', ['from_index' => '2']);
    $req->headers->set('Last-Event-ID', 'not-a-number');

    expect(StreamEventsController::resolveFromIndex($req))->toBe(2);
});

test('Last-Event-ID = 0 is treated as a real index (resume from event 1)', function () {
    // The client has seen event 0 and wants what's after it.
    $req = Request::create('/');
    $req->headers->set('Last-Event-ID', '0');

    expect(StreamEventsController::resolveFromIndex($req))->toBe(0);
});
