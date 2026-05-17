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
