<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| Web search on a turn: `options.web_search`
|--------------------------------------------------------------------------
|
| `'web_search' => true` goes to the bridge as `ai_request.options.web_search: true`
| on both send paths and on a `session_lost` re-issue; ai-bridge 0.40.0+ then lets
| an isolated Claude turn use the built-in WebSearch tool and nothing else built in.
| Only `true` is ever sent: absent, false or a stray truthy value leaves the payload
| as it was. A bridge that honours it says so with `hello.web_search: true`.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
});

function webSearchStream(array $options): BridgeStream
{
    $stream = new BridgeStream(new BridgeConnectionManager(), new ToolRegistry(), app(TokenManager::class), 'user-1');
    $stream->setConversationId('conv-1');
    $stream->setProvider('claude');
    $stream->setMessage('hello');
    $stream->setOptions($options);

    return $stream;
}

test('web_search true is sent as options.web_search beside the other options', function () {
    expect(webSearchStream(['model' => 'opus', 'thinking_display' => 'summarized', 'web_search' => true])->buildRequestBody()['options'])
        ->toBe(['model' => 'opus', 'thinking_display' => 'summarized', 'web_search' => true]);
});

test('without web_search, or with anything but true, the payload is what it was', function (mixed $value) {
    $options = $value === 'absent' ? ['model' => 'opus'] : ['model' => 'opus', 'web_search' => $value];

    expect(webSearchStream($options)->buildRequestBody()['options'])->toBe(['model' => 'opus']);
})->with([['absent'], [false], [null], ['1'], ['true'], [1], [[true]]]);

test('a relay body cannot turn it on with a truthy string', function () {
    $payload = AiRequestPayload::build(['request_id' => 'r', 'message' => 'm', 'options' => ['web_search' => 'yes']]);

    expect($payload)->not->toHaveKey('options');
});

test('web_search survives the PHP-FPM relay unchanged', function () {
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);
    webSearchStream(['model' => 'opus', 'web_search' => true])->start();
    $body = [];
    Http::assertSent(function ($request) use (&$body) {
        $body = $request->data();

        return true;
    });

    expect(AiRequestPayload::fromRelayBody($body, $body['request_id'] ?? '')['options'])
        ->toBe(['model' => 'opus', 'web_search' => true]);
});

test('a conversation turn forwards web_search through streamConversation', function () {
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);
    $connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'name' => 'box', 'connection_key' => 'key-1']);
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'opus', 'connection_id' => $connection->id]);

    app(AiBridgeManager::class)->streamConversation($conversation, 'hi', ['web_search' => true])->start();

    Http::assertSent(fn ($request) => ($request->data()['options'] ?? null) === ['model' => 'opus', 'web_search' => true]);
});

test('a turn re-issued after session_lost keeps its web_search', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'opus', 'cli_session_id' => 'dead']);
    $conversation->appendMessage(Message::ROLE_USER, 'Who wrote this module?');
    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $manager->addConnection('user-1', 'conn-1');

    $original = AiRequestPayload::build([
        'request_id' => 'req-1', 'message' => 'Who wrote this module?',
        'options' => ['model' => 'opus', 'web_search' => true],
    ]);
    expect(AiRequestPayload::reissueCarry($original))->toBe(['model' => 'opus', 'web_search' => true]);

    $messages = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());
    $messages->registerRelayedRequest('req-1', 'user-1', (string) $conversation->id, AiRequestPayload::reissueCarry($original));
    $messages->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::ERROR,
        'data' => ['code' => 'session_lost', 'message' => 'cannot resume'],
    ]));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['options'])->toBe(['model' => 'opus', 'web_search' => true]);
});

test('hello.web_search true is recorded as the web_search capability, absent or non-true is not', function () {
    expect(MessageHandler::bridgeInfoFromHello(['web_search' => true])['capabilities'])->toContain('web_search')
        ->and(MessageHandler::bridgeInfoFromHello([])['capabilities'])->not->toContain('web_search')
        ->and(MessageHandler::bridgeInfoFromHello(['web_search' => 1])['capabilities'])->not->toContain('web_search');
});
