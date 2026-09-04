<?php

declare(strict_types=1);

use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\AiRequestPayload;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\BridgeStream;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/*
|--------------------------------------------------------------------------
| The ai_request payload, and the two builders that must agree on it
|--------------------------------------------------------------------------
|
| There are two paths that send an ai_request — the direct WebSocket send and
| the PHP-FPM HTTP relay — and they used to be two hand-maintained copies. A
| field added to one and forgotten in the other does not fail: it works under
| Octane and silently does nothing under PHP-FPM, which is the deployment most
| apps actually run. Hence the shared builder, and hence this file.
|
*/

/** A payload as BridgeStream would build it, for the given options. */
function bridgeStreamPayload(array $options, string $message = 'hello', string $provider = 'claude'): array
{
    $stream = new BridgeStream(
        new BridgeConnectionManager(),
        new ToolRegistry(),
        app(TokenManager::class),
        'user-1',
    );
    $stream->setConversationId('conv-1');
    $stream->setProvider($provider);
    $stream->setMessage($message);
    $stream->setOptions($options);

    return $stream->buildRequestBody();
}

test('the two builders produce the same payload from equivalent inputs', function () {
    $direct = bridgeStreamPayload([
        'system_prompt' => 'be useful',
        'model' => 'sonnet',
        'temperature' => 0.5,
        'max_tokens' => 1000,
        'cli_session_id' => null,
        'messages' => [['role' => 'user', 'content' => 'earlier']],
        'working_dir' => '/repos/studio',
        'attachments' => [[
            'id' => 'att_1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'sha256' => 'ABC', 'url' => 'https://x.test/a',
        ]],
    ]);

    // What the relay path receives as an HTTP body, and rebuilds.
    $relayed = AiRequestPayload::build([
        'request_id' => $direct['request_id'],
        'conversation_id' => 'conv-1',
        'provider' => 'claude',
        'message' => 'hello',
        'system_prompt' => 'be useful',
        'options' => ['temperature' => 0.5, 'max_tokens' => 1000, 'model' => 'sonnet'],
        'cli_session_id' => null,
        'history' => [['role' => 'user', 'content' => 'earlier']],
        'tools' => [],
        'working_dir' => '/repos/studio',
        'attachments' => [[
            'id' => 'att_1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'sha256' => 'ABC', 'url' => 'https://x.test/a',
        ]],
    ]);

    expect($relayed)->toBe($direct);
});

test('the two builders agree on a minimal payload too', function () {
    $direct = bridgeStreamPayload(['cli_session_id' => null]);

    $relayed = AiRequestPayload::build([
        'request_id' => $direct['request_id'],
        'conversation_id' => 'conv-1',
        'provider' => 'claude',
        'message' => 'hello',
        'cli_session_id' => null,
    ]);

    expect($relayed)->toBe($direct);
});

test('every optional field is absent rather than empty', function () {
    // One convention, so the two builders can be compared at all — and so the
    // bridge reads "absent means default" everywhere.
    $payload = AiRequestPayload::build([
        'request_id' => 'req_1',
        'message' => 'hi',
        'options' => [],
        'tools' => [],
        'history' => [],
        'attachments' => [],
        'working_dir' => '',
    ]);

    expect($payload)->not->toHaveKey('options')
        ->and($payload)->not->toHaveKey('tools')
        ->and($payload)->not->toHaveKey('history')
        ->and($payload)->not->toHaveKey('attachments')
        ->and($payload)->not->toHaveKey('working_dir')
        ->and($payload)->not->toHaveKey('system_prompt');
});

test('cli_session_id is always present, including as null', function () {
    // The server owns the conversation → session mapping, so the bridge never
    // has to infer whether a conversation is new.
    $payload = AiRequestPayload::build(['request_id' => 'req_1', 'message' => 'hi']);

    expect($payload)->toHaveKey('cli_session_id')
        ->and($payload['cli_session_id'])->toBeNull();
});

test('history is dropped when resuming a session', function () {
    // A resumed CLI session already holds its context; re-sending it is bytes
    // the bridge discards.
    $payload = AiRequestPayload::build([
        'request_id' => 'req_1',
        'message' => 'hi',
        'cli_session_id' => 'sess-1',
        'history' => [['role' => 'user', 'content' => 'earlier']],
    ]);

    expect($payload)->not->toHaveKey('history');
});

test('null options are stripped rather than sent as nulls', function () {
    $payload = AiRequestPayload::build([
        'request_id' => 'req_1',
        'message' => 'hi',
        'options' => ['model' => 'sonnet', 'temperature' => null, 'max_tokens' => null],
    ]);

    expect($payload['options'])->toBe(['model' => 'sonnet']);
});

test('working_dir is carried through', function () {
    $payload = AiRequestPayload::build([
        'request_id' => 'req_1',
        'message' => 'hi',
        'working_dir' => '/Users/jasper/zp-studio/studio',
    ]);

    expect($payload['working_dir'])->toBe('/Users/jasper/zp-studio/studio');
});

test('attachments are reduced to exactly the six protocol fields', function () {
    $payload = AiRequestPayload::build([
        'request_id' => 'req_1',
        'message' => 'hi',
        'attachments' => [[
            'id' => 'att_1',
            'name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'size' => '482113',
            'sha256' => '9F3C',
            'url' => 'https://studio.test/ai-bridge/attachments/att_1',
            'internal_note' => 'should not travel',
        ]],
    ]);

    expect($payload['attachments'][0])->toBe([
        'id' => 'att_1',
        'name' => 'invoice.pdf',
        'mime_type' => 'application/pdf',
        // Cast, because the bridge compares it against the bytes it counted.
        'size' => 482113,
        // Lowercased, because the bridge compares it against a hex digest.
        'sha256' => '9f3c',
        'url' => 'https://studio.test/ai-bridge/attachments/att_1',
    ]);
});

test('an attachment missing a field the bridge needs is refused, not dropped', function () {
    // Dropping it silently produces a turn where the assistant is never told
    // about a file the user watched themselves attach, and nothing says why.
    expect(fn () => AiRequestPayload::build([
        'request_id' => 'req_1',
        'message' => 'hi',
        'attachments' => [['id' => 'att_1', 'name' => 'a.pdf']],
    ]))->toThrow(InvalidArgumentException::class, 'missing "mime_type"');
});

test('the payload is an ai_request', function () {
    $payload = AiRequestPayload::build(['request_id' => 'req_1', 'message' => 'hi']);

    expect($payload['type'])->toBe(MessageTypes::AI_REQUEST);
});
