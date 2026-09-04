<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/*
|--------------------------------------------------------------------------
| The relay round trip
|--------------------------------------------------------------------------
|
| The direct WebSocket path and the PHP-FPM relay path now share a builder, so
| comparing the builder against itself proves very little. The drift that can
| still happen is in the hand-written hops either side of it:
| BridgeStream::relayViaHttpApi() copies the payload into an HTTP body, and
| BridgeWebSocketServer::apiRequest() maps that body back through the builder.
| A field added to the builder and missed in those two works under Octane and
| silently does nothing under PHP-FPM — the deployment most apps run.
|
| So this drives the real relay, captures the real body, and replays the
| server's real mapping.
|
*/

/**
 * The mapping BridgeWebSocketServer::apiRequest() applies to a relay body.
 *
 * Kept in step with that method deliberately: if someone adds a field to the
 * builder and to relayViaHttpApi() but forgets apiRequest(), this list still
 * reflects the server and the assertion below fails.
 */
function replayRelayBody(array $body): array
{
    return AiRequestPayload::build([
        'request_id' => $body['request_id'] ?? '',
        'conversation_id' => $body['conversation_id'] ?? '',
        'provider' => $body['provider'] ?? '',
        'message' => $body['message'] ?? '',
        'system_prompt' => $body['system_prompt'] ?? null,
        'options' => $body['options'] ?? [],
        'cli_session_id' => $body['cli_session_id'] ?? null,
        'history' => $body['history'] ?? null,
        'tools' => $body['tools'] ?? null,
        'working_dir' => $body['working_dir'] ?? null,
        'attachments' => $body['attachments'] ?? null,
    ]);
}

function relayedBodyFor(array $options): array
{
    Http::fake(['*/api/request' => Http::response(['ok' => true], 200)]);

    $stream = new BridgeStream(
        // Empty on purpose: with no in-memory connection, start() takes the
        // PHP-FPM relay branch, which is the one under test.
        new BridgeConnectionManager(),
        new ToolRegistry(),
        app(TokenManager::class),
        'user-1',
    );
    $stream->setConversationId('conv-1');
    $stream->setProvider('claude');
    $stream->setMessage('hello');
    $stream->setOptions($options);

    $direct = $stream->buildRequestBody();
    $stream->start();

    $sent = [];
    Http::assertSent(function ($request) use (&$sent) {
        $sent = $request->data();

        return true;
    });

    return ['direct' => $direct, 'relayed' => $sent];
}

test('a fully-populated turn survives the relay unchanged', function () {
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor([
        'system_prompt' => 'be useful',
        'model' => 'sonnet',
        'temperature' => 0.5,
        'max_tokens' => 1000,
        'cli_session_id' => null,
        'messages' => [['role' => 'user', 'content' => 'earlier']],
        'working_dir' => '/repos/studio',
        'attachments' => [[
            'id' => 'att_1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'sha256' => 'abc', 'url' => 'https://x.test/a',
        ]],
    ]);

    expect(replayRelayBody($relayBody))->toBe($direct);
});

test('working_dir reaches the relay body', function () {
    // Named explicitly: this is the field whose loss would look like the
    // workspace feature simply not working under PHP-FPM.
    ['relayed' => $relayBody] = relayedBodyFor([
        'cli_session_id' => null,
        'working_dir' => '/repos/studio',
    ]);

    expect($relayBody['working_dir'])->toBe('/repos/studio');
});

test('attachments reach the relay body', function () {
    ['relayed' => $relayBody] = relayedBodyFor([
        'cli_session_id' => null,
        'attachments' => [[
            'id' => 'att_1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'sha256' => 'abc', 'url' => 'https://x.test/a',
        ]],
    ]);

    expect($relayBody['attachments'][0]['id'])->toBe('att_1');
});

test('a resumed turn survives the relay unchanged', function () {
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor([
        'cli_session_id' => 'sess-1',
        'messages' => [['role' => 'user', 'content' => 'earlier']],
        'working_dir' => '/repos/studio',
    ]);

    expect(replayRelayBody($relayBody))->toBe($direct)
        // History is dropped on both sides, not just one.
        ->and($direct)->not->toHaveKey('history');
});

test('a minimal turn survives the relay unchanged', function () {
    ['direct' => $direct, 'relayed' => $relayBody] = relayedBodyFor(['cli_session_id' => null]);

    expect(replayRelayBody($relayBody))->toBe($direct);
});

test('a malformed options field is rejected rather than thrown past the loop', function () {
    // apiRequest() runs inside the ReactPHP data callback, which has no
    // try/catch: a TypeError there does not fail one request, it exits the
    // serve process and drops every connected bridge.
    expect(AiRequestPayload::build([
        'request_id' => 'r', 'message' => 'm', 'options' => 'not-an-array',
    ]))->not->toHaveKey('options');

    expect(AiRequestPayload::build([
        'request_id' => 'r', 'message' => 'm', 'tools' => 'nope', 'history' => 'nope',
    ]))->not->toHaveKey('tools');

    expect(fn () => AiRequestPayload::build([
        'request_id' => 'r', 'message' => 'm', 'attachments' => 'nope',
    ]))->toThrow(InvalidArgumentException::class);
});
