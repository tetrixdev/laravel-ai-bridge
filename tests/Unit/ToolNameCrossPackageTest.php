<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| Frames captured from the real bridge binary
|--------------------------------------------------------------------------
|
| tests/Unit/fixtures/tool-calls-from-bridge.json is the output of a real turn
| run through @tetrixdev/ai-bridge against Claude Code, recorded byte for byte
| as it came off the WebSocket. It is the handover's own acceptance scenario:
| three shell commands and a file read, which should read as "3 commands, 1
| file read" rather than "4 tool calls".
|
| A fixture written by hand only ever agrees with its author, which is exactly
| how this bug survived: both packages "supported" tool_name, and neither of
| them was tested against what the other actually sends.
|
*/

uses(RefreshDatabase::class);

/** Frames captured off the wire from a real bridge turn, replayed verbatim. */
function bridgeFrames(): array
{
    $path = __DIR__.'/fixtures/tool-calls-from-bridge.json';
    expect(file_exists($path))->toBeTrue();

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/** A handler recording into a conversation, with the provider stubbed out. */
function crossPackageHandler(): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);

    return $handler;
}

test('the bridge really does send a tool name', function () {
    // Guards the fixture itself. If a future bridge stops sending it, this is
    // the test that says so rather than the chat quietly going generic again.
    $starts = array_values(array_filter(
        bridgeFrames(),
        fn ($f) => $f['event'] === 'block_start' && ($f['data']['block_type'] ?? '') === 'tool_call',
    ));

    expect($starts)->not->toBeEmpty();
    foreach ($starts as $frame) {
        expect($frame['data'])->toHaveKey('tool_name')
            ->and($frame['data']['tool_name'])->toBeString()->not->toBe('')
            ->and($frame['data'])->toHaveKey('tool_call_id');
    }
});

test('a real turn is recorded with its tool names, arguments and results', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $handler = crossPackageHandler();
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    foreach (bridgeFrames() as $frame) {
        $handler->dispatchEvent(StreamEvent::fromArray($frame));
    }

    $blocks = $conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];

    $names = collect($blocks)->where('type', 'tool_call')->pluck('tool_name')->all();
    // Results are attached to the call they belong to, matching what the chat
    // component draws — a standalone block here meant the same turn showed the
    // result under its call live and floating loose after a reload.
    $results = collect($blocks)->where('type', 'tool_call')->pluck('result')->all();

    // Enough to be counted AND grouped by kind, which is the whole point:
    // "3 commands, 1 file read" rather than "4 tool calls".
    expect($names)->toBe(['Bash', 'Bash', 'Bash', 'Read'])
        ->and($results)->toHaveCount(4)
        ->and($results[0])->toContain('one')
        ->and($results[1])->toContain('two')
        ->and($results[2])->toContain('three')
        ->and($results[3])->toContain('TN-7781');

    // And the grouping the handover asked for is now expressible.
    $counts = array_count_values($names);
    expect($counts)->toBe(['Bash' => 3, 'Read' => 1]);
});

test('each result can be paired to the call that produced it', function () {
    $handler = crossPackageHandler();
    $handler->setConversationId('pairing');

    $callIds = [];
    $resultIds = [];
    $handler->onBlockStart(function (StreamEvent $e) use (&$callIds) {
        if (($e->data['block_type'] ?? '') === 'tool_call') {
            $callIds[] = $e->data['tool_call_id'] ?? null;
        }
    });
    $handler->onToolResult(function (string $id) use (&$resultIds) {
        $resultIds[] = $id;
    });

    foreach (bridgeFrames() as $frame) {
        $handler->dispatchEvent(StreamEvent::fromArray($frame));
    }

    expect($callIds)->toHaveCount(4)
        ->and($callIds)->not->toContain(null)
        ->and($resultIds)->toBe($callIds);
});

test('the arguments of each locally-run call survive', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $handler = crossPackageHandler();
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    foreach (bridgeFrames() as $frame) {
        $handler->dispatchEvent(StreamEvent::fromArray($frame));
    }

    $blocks = $conversation->messages()->where('role', 'assistant')->latest('id')->first()?->blocks ?? [];
    $commands = collect($blocks)->where('type', 'tool_call')
        ->pluck('parameters.command')->filter()->values()->all();

    expect($commands)->toBe(['echo one', 'echo two', 'echo three']);
});

test('the real done frame carries cache tokens and cost', function () {
    $handler = crossPackageHandler();
    $handler->setConversationId('meta');

    $usage = null;
    $meta = [];
    $handler->onDone(function (?array $u, array $m = []) use (&$usage, &$meta) {
        $usage = $u;
        $meta = $m;
    });

    foreach (bridgeFrames() as $frame) {
        $handler->dispatchEvent(StreamEvent::fromArray($frame));
    }

    expect($usage)->toHaveKey('cache_read_input_tokens')
        ->and($usage['cache_read_input_tokens'])->toBeGreaterThan(0)
        ->and($meta['model'])->toBeString()
        ->and($meta['cost_usd'])->toBeGreaterThan(0);
});
