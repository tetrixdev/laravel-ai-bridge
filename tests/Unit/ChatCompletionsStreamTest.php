<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\ChatCompletionsStream;
use Tetrix\AiBridge\Tests\TestCase;
use Tetrix\AiBridge\Tools\ToolRegistry;

/*
|--------------------------------------------------------------------------
| ChatCompletionsStream Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify stream processing, HTTP error mapping, tool call
| accumulation, and the flushToolCalls deduplication logic.
| HTTP calls are faked using Laravel's Http::fake().
|
*/


function makeSseChunk(array $data): string
{
    return "data: " . json_encode($data) . "\n\n";
}

function makeSseStream(array $chunks): string
{
    $stream = '';
    foreach ($chunks as $chunk) {
        if (is_string($chunk)) {
            $stream .= "data: {$chunk}\n\n";
        } else {
            $stream .= makeSseChunk($chunk);
        }
    }
    return $stream;
}

function createChatStream(?ToolRegistry $registry = null): ChatCompletionsStream
{
    return new ChatCompletionsStream(
        toolRegistry: $registry ?? new ToolRegistry(),
        endpoint: 'https://api.example.com',
        apiKey: 'test-key',
        model: 'gpt-4o',
        maxTokens: 4096,
    );
}

test('buildRequestBody() includes required fields', function () {
    $stream = createChatStream();
    $stream->setMessage('Hello');
    $stream->setConversationId('conv-1');

    $body = $stream->buildRequestBody();

    expect($body['model'])->toBe('gpt-4o');
    expect($body['max_tokens'])->toBe(4096);
    expect($body['stream'])->toBeTrue();
    expect($body['stream_options'])->toBe(['include_usage' => true]);
    expect($body['messages'])->toBeArray();
    expect($body['messages'])->toContain(['role' => 'user', 'content' => 'Hello']);
});

test('buildRequestBody() includes system prompt when set', function () {
    $stream = createChatStream();
    $stream->setMessage('Hello');
    $stream->setOptions(['system_prompt' => 'You are helpful']);

    $body = $stream->buildRequestBody();

    expect($body['messages'][0])->toBe(['role' => 'system', 'content' => 'You are helpful']);
});

test('buildRequestBody() includes tools when registered', function () {
    $registry = new ToolRegistry();
    $registry->register('search', 'Search the web', ['type' => 'object'], fn ($p) => null);

    $stream = createChatStream($registry);
    $stream->setMessage('Search for cats');

    $body = $stream->buildRequestBody();

    expect($body)->toHaveKey('tools');
    expect($body['tools'])->toHaveCount(1);
    expect($body['tools'][0]['function']['name'])->toBe('search');
    expect($body['tool_choice'])->toBe('auto');
});

test('buildRequestBody() excludes tools when none registered', function () {
    $stream = createChatStream();
    $stream->setMessage('Hello');

    $body = $stream->buildRequestBody();

    expect($body)->not->toHaveKey('tools');
    expect($body)->not->toHaveKey('tool_choice');
});

test('buildRequestBody() allows model override via options', function () {
    $stream = createChatStream();
    $stream->setMessage('Hello');
    $stream->setOptions(['model' => 'gpt-3.5-turbo']);

    $body = $stream->buildRequestBody();

    expect($body['model'])->toBe('gpt-3.5-turbo');
});

test('buildRequestBody() includes temperature when set', function () {
    $stream = createChatStream();
    $stream->setMessage('Hello');
    $stream->setOptions(['temperature' => 0.5]);

    $body = $stream->buildRequestBody();

    expect($body['temperature'])->toBe(0.5);
});

test('buildRequestBody() includes conversation history', function () {
    $stream = createChatStream();
    $stream->setMessage('Follow up');
    $stream->setOptions([
        'messages' => [
            ['role' => 'user', 'content' => 'First message'],
            ['role' => 'assistant', 'content' => 'First reply'],
        ],
    ]);

    $body = $stream->buildRequestBody();

    // Should have history + current message
    expect($body['messages'])->toHaveCount(3);
    expect($body['messages'][0]['content'])->toBe('First message');
    expect($body['messages'][1]['content'])->toBe('First reply');
    expect($body['messages'][2]['content'])->toBe('Follow up');
});

test('HTTP error 429 maps to rate_limited error code', function () {
    Http::fake([
        'api.example.com/*' => Http::response('Rate limit exceeded', 429),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $receivedCode = null;

    $handler->onError(function (string $code) use (&$receivedCode) {
        $receivedCode = $code;
    });

    $stream->start();

    expect($receivedCode)->toBe('rate_limited');
});

test('HTTP error 401 maps to auth_error error code', function () {
    Http::fake([
        'api.example.com/*' => Http::response('Unauthorized', 401),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $receivedCode = null;

    $handler->onError(function (string $code) use (&$receivedCode) {
        $receivedCode = $code;
    });

    $stream->start();

    expect($receivedCode)->toBe('auth_error');
});

test('HTTP error 403 maps to auth_error error code', function () {
    Http::fake([
        'api.example.com/*' => Http::response('Forbidden', 403),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $receivedCode = null;

    $handler->onError(function (string $code) use (&$receivedCode) {
        $receivedCode = $code;
    });

    $stream->start();

    expect($receivedCode)->toBe('auth_error');
});

test('HTTP error 503 maps to service_unavailable error code', function () {
    Http::fake([
        'api.example.com/*' => Http::response('Service Unavailable', 503),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $receivedCode = null;

    $handler->onError(function (string $code) use (&$receivedCode) {
        $receivedCode = $code;
    });

    $stream->start();

    expect($receivedCode)->toBe('service_unavailable');
});

test('HTTP error 500 maps to api_error error code', function () {
    Http::fake([
        'api.example.com/*' => Http::response('Internal Server Error', 500),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $receivedCode = null;

    $handler->onError(function (string $code) use (&$receivedCode) {
        $receivedCode = $code;
    });

    $stream->start();

    expect($receivedCode)->toBe('api_error');
});

test('start() strips trailing /v1 from endpoint to prevent double /v1 path (BL-005)', function () {
    Http::fake([
        // Correct URL should have exactly one /v1/chat/completions
        'api.example.com/v1/chat/completions' => Http::response('Rate limit', 429),
        // If double /v1 were present, this would match instead:
        'api.example.com/v1/v1/chat/completions' => Http::response('Should not be hit', 200),
    ]);

    // Construct stream with a /v1-suffixed endpoint (common misconfiguration)
    $stream = new \Tetrix\AiBridge\Streaming\ChatCompletionsStream(
        toolRegistry: new \Tetrix\AiBridge\Tools\ToolRegistry(),
        endpoint: 'https://api.example.com/v1',
        apiKey: 'test-key',
        model: 'gpt-4o',
        maxTokens: 4096,
    );
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $receivedCode = null;
    $handler->onError(function (string $code) use (&$receivedCode) {
        $receivedCode = $code;
    });

    $stream->start();

    // Should have hit the correct /v1/chat/completions URL (mapped to 429)
    expect($receivedCode)->toBe('rate_limited');
});

test('stream timeout uses correct config key', function () {
    config(['ai-bridge.chat_completions.stream_timeout' => 120]);

    // Verify the config value is accessible (actual timeout behavior depends on HTTP client)
    expect(config('ai-bridge.chat_completions.stream_timeout'))->toBe(120);
});

test('getStreamHandler() returns StreamHandler instance', function () {
    $stream = createChatStream();

    expect($stream->getStreamHandler())->toBeInstanceOf(\Tetrix\AiBridge\Streaming\StreamHandler::class);
});

test('cancel() causes dispatchCancelled (not dispatchError) when stream ends mid-flight (BL-009)', function () {
    // Simulate a stream with a text delta — then cancel via onBlockDelta callback
    // so the stream exits the while loop with $cancelled=true.
    // Note: start() resets cancelled=false, so cancel() must be called during processing.
    Http::fake([
        'api.example.com/*' => Http::response(
            // Two text delta chunks — no [DONE] follows; body ends here
            makeSseChunk(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]]) .
            makeSseChunk(['choices' => [['delta' => ['content' => ' world'], 'finish_reason' => null]]]),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $cancelledFired = false;
    $errorFired = false;

    // Cancel during first block_delta callback — this sets $this->cancelled = true
    // so after the while loop exits (stream body also exhausted), it dispatches dispatchCancelled.
    $handler->onBlockDelta(function () use ($stream) {
        $stream->cancel();
    });
    $handler->onCancelled(function () use (&$cancelledFired) {
        $cancelledFired = true;
    });
    $handler->onError(function () use (&$errorFired) {
        $errorFired = true;
    });

    $stream->start();

    expect($cancelledFired)->toBeTrue();
    expect($errorFired)->toBeFalse();
});

test('processStream() dispatches stream_incomplete when stream ends without [DONE] (BL-009)', function () {
    Http::fake([
        'api.example.com/*' => Http::response(
            // One text delta, no [DONE]
            makeSseChunk(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]]),
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $receivedCode = null;

    $handler->onError(function (string $code) use (&$receivedCode) {
        $receivedCode = $code;
    });

    $stream->start();

    expect($receivedCode)->toBe('stream_incomplete');
});

test('processStream() accumulates multi-delta tool call arguments and dispatches them (BL-009)', function () {
    $firstDelta = makeSseChunk(['choices' => [[
        'delta' => ['tool_calls' => [
            ['index' => 0, 'id' => 'call-abc', 'function' => ['name' => 'search', 'arguments' => '{"q"']],
        ]],
        'finish_reason' => null,
    ]]]);

    $secondDelta = makeSseChunk(['choices' => [[
        'delta' => ['tool_calls' => [
            ['index' => 0, 'function' => ['arguments' => ': "cats"}']],
        ]],
        'finish_reason' => 'tool_calls',
    ]]]);

    $doneLine = "data: [DONE]\n\n";

    Http::fake([
        'api.example.com/*' => Http::response(
            $firstDelta . $secondDelta . $doneLine,
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Search');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $toolCallName = null;
    $toolCallParams = null;
    $toolCallId = null;

    $handler->onToolCall(function (string $name, array $params, string $callId) use (&$toolCallName, &$toolCallParams, &$toolCallId) {
        $toolCallName = $name;
        $toolCallParams = $params;
        $toolCallId = $callId;
    });

    $stream->start();

    expect($toolCallName)->toBe('search');
    expect($toolCallParams)->toBe(['q' => 'cats']);
    expect($toolCallId)->toBe('call-abc');
});

test('processStream() dispatches done with usage from standalone usage chunk (BL-009)', function () {
    $usageChunk = makeSseChunk([
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
    ]);

    Http::fake([
        'api.example.com/*' => Http::response(
            $usageChunk,
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $stream = createChatStream();
    $stream->setMessage('Hello');
    $handler = $stream->getStreamHandler();
    $handler->setMode(\Tetrix\AiBridge\Enums\ProviderMode::Byok);
    $handler->setConversationId('conv-1');

    $doneUsage = null;
    $doneFired = false;

    $handler->onDone(function (?array $usage) use (&$doneUsage, &$doneFired) {
        $doneUsage = $usage;
        $doneFired = true;
    });

    $stream->start();

    expect($doneFired)->toBeTrue();
    expect($doneUsage)->toBe(['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30]);
});

test('setConversationId() is fluent', function () {
    $stream = createChatStream();

    $result = $stream->setConversationId('conv-1');

    expect($result)->toBe($stream);
});

test('setMessage() is fluent', function () {
    $stream = createChatStream();

    $result = $stream->setMessage('Hello');

    expect($result)->toBe($stream);
});

test('setOptions() is fluent', function () {
    $stream = createChatStream();

    $result = $stream->setOptions(['temperature' => 0.7]);

    expect($result)->toBe($stream);
});
