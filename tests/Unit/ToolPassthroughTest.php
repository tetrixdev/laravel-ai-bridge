<?php

declare(strict_types=1);

use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\WebSocket\MessageHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\Auth\TokenManager;

/*
|--------------------------------------------------------------------------
| What survives the trip from the bridge to a consumer
|--------------------------------------------------------------------------
|
| The bridge sends a tool's name, its id, its result and whether that result
| was an error. StreamHandler used to rebuild block_start from only the block
| type and index, so the name and id were discarded one layer after arriving —
| which is why a chat could say "4 tool calls" and never what any of them were.
|
| These assert on what reaches a callback, which is the only thing a consuming
| app can actually see.
|
*/

function passthroughHandler(): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);
    $handler->setConversationId('conv-passthrough');

    return $handler;
}

/** A raw wire event, as the bridge sends it. */
function wireEvent(string $event, array $data): StreamEvent
{
    return StreamEvent::fromArray([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-1',
        'event' => $event,
        'data' => $data,
    ]);
}

test('block_start carries the tool name through to the callback', function () {
    $handler = passthroughHandler();
    $seen = null;
    $handler->onBlockStart(function (StreamEvent $e) use (&$seen) {
        $seen = $e->data;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::BLOCK_START, [
        'block_index' => 1,
        'block_type' => 'tool_call',
        'tool_name' => 'Bash',
        'tool_call_id' => 'toolu_01ABC',
    ]));

    expect($seen['tool_name'])->toBe('Bash')
        ->and($seen['tool_call_id'])->toBe('toolu_01ABC')
        ->and($seen['block_index'])->toBe(1);
});

test('the tool name is passed through verbatim, not prettified', function () {
    // The consumer does its own display formatting; a title-cased name has
    // bitten them once already.
    $handler = passthroughHandler();
    $seen = null;
    $handler->onBlockStart(function (StreamEvent $e) use (&$seen) {
        $seen = $e->data['tool_name'] ?? null;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::BLOCK_START, [
        'block_index' => 0,
        'block_type' => 'tool_call',
        'tool_name' => 'mcp__bridge__company_directory',
    ]));

    expect($seen)->toBe('mcp__bridge__company_directory');
});

test('a block_start with no tool name omits the key rather than sending null', function () {
    $handler = passthroughHandler();
    $seen = null;
    $handler->onBlockStart(function (StreamEvent $e) use (&$seen) {
        $seen = $e->data;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::BLOCK_START, [
        'block_index' => 0,
        'block_type' => 'text',
    ]));

    expect($seen)->not->toHaveKey('tool_name')
        ->and($seen)->not->toHaveKey('tool_call_id');
});

test('an empty tool name is treated as absent', function () {
    $handler = passthroughHandler();
    $seen = ['tool_name' => 'sentinel'];
    $handler->onBlockStart(function (StreamEvent $e) use (&$seen) {
        $seen = $e->data;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::BLOCK_START, [
        'block_index' => 0,
        'block_type' => 'tool_call',
        'tool_name' => '',
    ]));

    expect($seen)->not->toHaveKey('tool_name');
});

test('tool_result reports whether the tool failed', function () {
    $handler = passthroughHandler();
    $seen = [];
    $handler->onToolResult(function (string $id, mixed $result, ?bool $isError = null) use (&$seen) {
        $seen = ['id' => $id, 'result' => $result, 'is_error' => $isError];
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::TOOL_RESULT, [
        'tool_call_id' => 'toolu_1',
        'result' => 'boom',
        'is_error' => true,
    ]));

    expect($seen)->toBe(['id' => 'toolu_1', 'result' => 'boom', 'is_error' => true]);
});

test('an unreported error status arrives as null, not as false', function () {
    // Absent must not read as "succeeded".
    $handler = passthroughHandler();
    $seen = 'unset';
    $handler->onToolResult(function (string $id, mixed $result, ?bool $isError = null) use (&$seen) {
        $seen = $isError;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::TOOL_RESULT, [
        'tool_call_id' => 't1',
        'result' => 'fine',
    ]));

    expect($seen)->toBeNull();
});

test('a non-boolean is_error is ignored rather than coerced', function () {
    $handler = passthroughHandler();
    $seen = 'unset';
    $handler->onToolResult(function (string $id, mixed $result, ?bool $isError = null) use (&$seen) {
        $seen = $isError;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::TOOL_RESULT, [
        'tool_call_id' => 't1',
        'result' => 'fine',
        'is_error' => 'yes',
    ]));

    expect($seen)->toBeNull();
});

test('rate_limit reaches a callback and does not end the turn', function () {
    $handler = passthroughHandler();
    $seen = null;
    $done = false;
    $handler->onRateLimit(function (string $provider, array $info) use (&$seen) {
        $seen = [$provider, $info];
    });
    $handler->onDone(function () use (&$done) {
        $done = true;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::RATE_LIMIT, [
        'provider' => 'claude',
        'info' => ['status' => 'allowed', 'rateLimitType' => 'five_hour'],
    ]));

    expect($seen[0])->toBe('claude')
        ->and($seen[1]['status'])->toBe('allowed')
        ->and($done)->toBeFalse();
});

test('done carries what the turn cost alongside the token counts', function () {
    $handler = passthroughHandler();
    $usage = null;
    $meta = null;
    $handler->onDone(function (?array $u, array $m = []) use (&$usage, &$meta) {
        $usage = $u;
        $meta = $m;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::DONE, [
        'usage' => [
            'input_tokens' => 6,
            'output_tokens' => 183,
            'cache_read_input_tokens' => 66013,
        ],
        'model' => 'claude-sonnet-5',
        'cost_usd' => 0.0377,
        'stop_reason' => 'end_turn',
        'permission_denials' => [['tool_name' => 'Bash']],
    ]));

    // Cache reads dominate a resumed conversation; a consumer shown only
    // input/output understates the turn by orders of magnitude.
    expect($usage['cache_read_input_tokens'])->toBe(66013)
        ->and($meta['model'])->toBe('claude-sonnet-5')
        ->and($meta['cost_usd'])->toBe(0.0377)
        ->and($meta['stop_reason'])->toBe('end_turn')
        ->and($meta['permission_denials'])->toHaveCount(1)
        ->and($meta)->not->toHaveKey('usage');
});

test('a done callback written for the old single-argument form still works', function () {
    // PHP passes extra arguments to a userland closure harmlessly, which is
    // what keeps existing consuming apps working untouched.
    $handler = passthroughHandler();
    $seen = 'unset';
    $handler->onDone(function (?array $usage) use (&$seen) {
        $seen = $usage;
    });

    $handler->dispatchEvent(wireEvent(MessageTypes::DONE, [
        'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
        'model' => 'claude-sonnet-5',
    ]));

    expect($seen)->toBe(['input_tokens' => 1, 'output_tokens' => 2]);
});

test('lastDoneMeta exposes the metadata after the turn', function () {
    $handler = passthroughHandler();
    $handler->dispatchEvent(wireEvent(MessageTypes::DONE, [
        'usage' => null,
        'model' => 'claude-opus-5',
    ]));

    expect($handler->lastDoneMeta()['model'])->toBe('claude-opus-5');
});

beforeEach(function () {
    Event::fake();
    $this->manager = new BridgeConnectionManager();
    $this->messageHandler = new MessageHandler(
        connectionManager: $this->manager,
        tokenManager: app(TokenManager::class),
        toolRegistry: new ToolRegistry(),
    );
});

/*
|--------------------------------------------------------------------------
| The bridge-mode path, which is the one that actually runs
|--------------------------------------------------------------------------
|
| A `done` frame from a bridge does not reach dispatchEvent — MessageHandler
| special-cases it so it can clean up the pending request. That gave a second
| place where the event was rebuilt from a subset of what arrived, which is
| exactly how tool_name was lost on block_start.
|
*/

test('a done frame from a real bridge keeps its metadata', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $this->messageHandler->registerRelayedRequest('req-meta', 'user-1', 'conv-1');

    $handler = $this->manager->getPendingRequest('req-meta');
    $usage = null;
    $meta = [];
    $handler->onDone(function (?array $u, array $m = []) use (&$usage, &$meta) {
        $usage = $u;
        $meta = $m;
    });

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-meta',
        'event' => MessageTypes::DONE,
        'data' => [
            'usage' => ['input_tokens' => 6, 'cache_read_input_tokens' => 66013],
            'model' => 'claude-sonnet-5',
            'cost_usd' => 0.0377,
            'permission_denials' => [['tool_name' => 'Bash']],
            'cli_session_id' => 'sess-1',
        ],
    ]));

    expect($usage['cache_read_input_tokens'])->toBe(66013)
        ->and($meta['model'])->toBe('claude-sonnet-5')
        ->and($meta['cost_usd'])->toBe(0.0377)
        ->and($meta['permission_denials'])->toHaveCount(1);
});

test('a rate_limit frame from a real bridge reaches its callback', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $this->messageHandler->registerRelayedRequest('req-rl', 'user-1', 'conv-1');

    $handler = $this->manager->getPendingRequest('req-rl');
    $seen = null;
    $handler->onRateLimit(function (string $provider, array $info) use (&$seen) {
        $seen = [$provider, $info];
    });

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-rl',
        'event' => MessageTypes::RATE_LIMIT,
        'data' => ['provider' => 'claude', 'info' => ['status' => 'allowed']],
    ]));

    expect($seen[0])->toBe('claude')->and($seen[1]['status'])->toBe('allowed');
});

test('a tool_call block_start from a real bridge keeps its tool name', function () {
    $this->manager->addConnection('user-1', 'conn-1');
    $this->messageHandler->registerRelayedRequest('req-tn', 'user-1', 'conv-1');

    $handler = $this->manager->getPendingRequest('req-tn');
    $seen = null;
    $handler->onBlockStart(function (StreamEvent $e) use (&$seen) {
        $seen = $e->data;
    });

    $this->messageHandler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-tn',
        'event' => MessageTypes::BLOCK_START,
        'data' => ['block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Bash', 'tool_call_id' => 'toolu_1'],
    ]));

    expect($seen['tool_name'])->toBe('Bash')->and($seen['tool_call_id'])->toBe('toolu_1');
});
