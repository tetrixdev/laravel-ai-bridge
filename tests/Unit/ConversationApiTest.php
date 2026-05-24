<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Events\ConversationCreated;
use Tetrix\AiBridge\Http\Controllers\ConversationController;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;

uses(RefreshDatabase::class);

/**
 * The controllers are invoked directly (like StreamControllerTest) rather than
 * via HTTP, so route middleware / boot ordering does not interfere.
 */
function conversationController(): ConversationController
{
    return app(ConversationController::class);
}

function jsonOf($response): array
{
    return json_decode($response->getContent(), true);
}

beforeEach(function () {
    app(AiBridgeManager::class)->resolveConversationsUsing(fn ($request) => Conversation::query());
    app(AiBridgeManager::class)->resolveConnectionsUsing(fn ($request) => \Tetrix\AiBridge\Models\Connection::query());
});

it('lists conversations visible to the request', function () {
    Conversation::create(['mode' => 'bridge']);
    Conversation::create(['mode' => 'byok']);

    $response = conversationController()->index(Request::create('/ai-bridge/conversations', 'GET'));

    expect($response->getStatusCode())->toBe(200)
        ->and(jsonOf($response)['total'])->toBe(2);
});

it('creates a conversation and fires ConversationCreated', function () {
    Event::fake([ConversationCreated::class]);

    $response = conversationController()->store(
        Request::create('/ai-bridge/conversations', 'POST', ['mode' => 'bridge', 'provider' => 'claude'])
    );

    expect($response->getStatusCode())->toBe(201)
        ->and(jsonOf($response)['mode'])->toBe('bridge')
        ->and(jsonOf($response)['tools_hash'])->not->toBeNull();

    Event::assertDispatched(ConversationCreated::class);
});

it('rejects an invalid mode', function () {
    conversationController()->store(
        Request::create('/ai-bridge/conversations', 'POST', ['mode' => 'nonsense'])
    );
})->throws(\Illuminate\Validation\ValidationException::class);

it('shows a conversation with messages and a tools_stale flag', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'tools_hash' => 'an-old-hash']);
    $conversation->appendMessage(Message::ROLE_USER, 'hi');

    $response = conversationController()->show(Request::create('/x', 'GET'), $conversation->id);
    $data = jsonOf($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['tools_stale'])->toBeTrue()
        ->and($data['messages'])->toHaveCount(1);
});

it('scopes access through the conversations resolver', function () {
    $visible = Conversation::create(['mode' => 'bridge']);
    $hidden = Conversation::create(['mode' => 'bridge']);

    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn ($request) => Conversation::whereKey($visible->id)
    );

    expect(conversationController()->show(Request::create('/x', 'GET'), $visible->id)->getStatusCode())->toBe(200)
        ->and(conversationController()->show(Request::create('/x', 'GET'), $hidden->id)->getStatusCode())->toBe(404);
});

it('deletes a conversation', function () {
    $conversation = Conversation::create(['mode' => 'bridge']);

    $response = conversationController()->destroy(Request::create('/x', 'DELETE'), $conversation->id);

    expect($response->getStatusCode())->toBe(200)
        ->and(Conversation::find($conversation->id))->toBeNull();
});

it('returns 409 when a turn is already in flight on the conversation', function () {
    $store = new \Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore();
    app()->instance(\Tetrix\AiBridge\Contracts\StreamStoreContract::class, $store);

    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn () => Conversation::whereKey($conversation->id)
    );

    // Simulate an in-flight turn: streaming_request_id set, buffer status=streaming.
    $rid = 'rid-already-streaming';
    $store->start($rid, ['conversation_id' => (string) $conversation->id]);
    $conversation->forceFill(['streaming_request_id' => $rid])->save();

    $response = conversationController()->stream(
        Request::create('/x', 'POST', ['message' => 'second turn']),
        $conversation->id,
    );

    expect($response->getStatusCode())->toBe(409);
    $body = jsonOf($response);
    expect($body['error'])->toBe('conflict');
    expect($body['request_id'])->toBe($rid);
});

it('does not 409 when the previous streaming_request_id points to a completed buffer', function () {
    // Stale pointer: a previous turn died without clearing the column, but
    // the buffer already shows the terminal status. We should accept the new
    // turn rather than block on the corpse.
    $store = new \Tetrix\AiBridge\Streaming\Drivers\ArrayStreamStore();
    app()->instance(\Tetrix\AiBridge\Contracts\StreamStoreContract::class, $store);

    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    app(AiBridgeManager::class)->resolveConversationsUsing(
        fn () => Conversation::whereKey($conversation->id)
    );

    $rid = 'rid-stale';
    $store->start($rid, ['conversation_id' => (string) $conversation->id]);
    $store->complete($rid, 'failed');
    $conversation->forceFill(['streaming_request_id' => $rid])->save();

    // Stub out the actual stream start so we don't try to dial a bridge.
    $manager = Mockery::mock(AiBridgeManager::class, [
        app(\Tetrix\AiBridge\Tools\ToolRegistry::class),
        app(\Tetrix\AiBridge\WebSocket\BridgeConnectionManager::class),
        app(\Tetrix\AiBridge\Auth\TokenManager::class),
    ])->makePartial();
    $manager->shouldReceive('conversationsQuery')->andReturn(Conversation::whereKey($conversation->id));
    $manager->shouldReceive('startConversationStream')->once()->andReturn('rid-new');
    app()->instance(AiBridgeManager::class, $manager);

    $controller = new ConversationController(
        $manager,
        app(\Tetrix\AiBridge\Tools\ToolRegistry::class),
    );

    $response = $controller->stream(
        Request::create('/x', 'POST', ['message' => 'new turn']),
        $conversation->id,
    );

    expect($response->getStatusCode())->toBe(200);
    expect(jsonOf($response)['request_id'])->toBe('rid-new');
});
