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
| Readable thinking: `options.thinking_display`
|--------------------------------------------------------------------------
|
| A turn's `thinking_display` option goes to the bridge as
| `ai_request.options.thinking_display`, on both send paths and on a
| `session_lost` re-issue. Absent, nothing is sent and the payload is what it
| was. Which values are valid is the bridge's to say (`hello.thinking_displays`).
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
});

function thinkingDisplayStream(array $options, ?BridgeConnectionManager $manager = null): BridgeStream
{
    $stream = new BridgeStream($manager ?? new BridgeConnectionManager(), new ToolRegistry(), app(TokenManager::class), 'user-1');
    $stream->setConversationId('conv-1');
    $stream->setProvider('claude');
    $stream->setMessage('hello');
    $stream->setOptions($options);

    return $stream;
}

function thinkingDisplayRelayed(array $options): array
{
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);
    thinkingDisplayStream($options)->start();
    $body = [];
    Http::assertSent(function ($request) use (&$body) {
        $body = $request->data();

        return true;
    });

    return AiRequestPayload::fromRelayBody($body, $body['request_id'] ?? '');
}

test('thinking_display is sent as options.thinking_display beside model and effort', function () {
    $payload = thinkingDisplayStream(['model' => 'opus', 'effort' => 'high', 'thinking_display' => 'summarized'])->buildRequestBody();

    expect($payload['options'])->toBe(['model' => 'opus', 'effort' => 'high', 'thinking_display' => 'summarized']);
});

test('thinking_display survives the PHP-FPM relay unchanged', function () {
    expect(thinkingDisplayRelayed(['model' => 'opus', 'thinking_display' => 'summarized'])['options'])
        ->toBe(['model' => 'opus', 'thinking_display' => 'summarized']);
});

test('without thinking_display the payload is what it was', function () {
    expect(thinkingDisplayStream(['model' => 'opus'])->buildRequestBody()['options'])->toBe(['model' => 'opus']);
});

test('an empty, wrong-typed or oversized thinking_display is not sent', function (mixed $value) {
    $payload = thinkingDisplayStream(['model' => 'opus', 'thinking_display' => $value])->buildRequestBody();

    expect($payload['options'])->toBe(['model' => 'opus']);
})->with([[''], [['summarized']], [true], [7], [str_repeat('s', 33)]]);

test('a conversation turn forwards thinking_display through streamConversation', function () {
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);
    $connection = Connection::create(['type' => Connection::TYPE_BRIDGE, 'name' => 'box', 'connection_key' => 'key-1']);
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'opus', 'connection_id' => $connection->id]);

    app(AiBridgeManager::class)->streamConversation($conversation, 'hi', ['thinking_display' => 'summarized'])->start();

    Http::assertSent(fn ($request) => ($request->data()['options'] ?? null) === ['model' => 'opus', 'thinking_display' => 'summarized']);
});

test('a turn re-issued after session_lost keeps its thinking_display', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'opus', 'cli_session_id' => 'dead']);
    $conversation->appendMessage(Message::ROLE_USER, 'I enter the cave');
    $sent = [];
    $manager = new BridgeConnectionManager();
    $manager->setSendCallback(function ($connection, $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    });
    $manager->addConnection('user-1', 'conn-1');

    $original = AiRequestPayload::build([
        'request_id' => 'req-1', 'message' => 'I enter the cave',
        'options' => ['model' => 'opus', 'thinking_display' => 'summarized'],
    ]);
    expect(AiRequestPayload::reissueCarry($original))->toBe(['model' => 'opus', 'thinking_display' => 'summarized']);

    $messages = new MessageHandler($manager, app(TokenManager::class), new ToolRegistry());
    $messages->registerRelayedRequest('req-1', 'user-1', (string) $conversation->id, AiRequestPayload::reissueCarry($original));
    $messages->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM, 'request_id' => 'req-1', 'event' => MessageTypes::ERROR,
        'data' => ['code' => 'session_lost', 'message' => 'cannot resume'],
    ]));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['options'])->toBe(['model' => 'opus', 'thinking_display' => 'summarized']);
});
