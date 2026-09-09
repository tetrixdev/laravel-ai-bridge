<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\StreamHandler;

/*
|--------------------------------------------------------------------------
| What a recorded turn remembers about its tool calls
|--------------------------------------------------------------------------
|
| A tool that ran on the operator's own machine — Bash, Read, an editor — has
| no WebSocket tool_call frame; the stream block is the only record of it that
| will ever exist. The recorder used to drop those blocks unconditionally, so a
| recorded turn showed the assistant's prose and no sign that anything ran.
|
*/

uses(RefreshDatabase::class);

function recordingHandler(): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start')->byDefault();
    $provider->shouldReceive('cancel')->byDefault();
    $provider->shouldReceive('markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);

    return $handler;
}

function wire(string $event, array $data): StreamEvent
{
    return StreamEvent::fromArray([
        'type' => MessageTypes::STREAM,
        'request_id' => 'req-rec',
        'event' => $event,
        'data' => $data,
    ]);
}

/** Drive a whole turn and return the blocks it persisted. */
function recordTurn(callable $drive): array
{
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $handler = recordingHandler();
    $handler->setConversationId((string) $conversation->id);
    ConversationRecorder::attach($handler, $conversation);

    $drive($handler);

    $message = $conversation->messages()->where('role', 'assistant')->latest('id')->first();

    return $message?->blocks ?? [];
}

test('a locally-run tool is recorded with its name and arguments', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'Bash', 'tool_call_id' => 'toolu_1',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, [
            'block_index' => 0, 'content' => '{"command":"echo hello"}',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool)->not->toBeNull()
        ->and($tool['tool_name'])->toBe('Bash')
        ->and($tool['tool_call_id'])->toBe('toolu_1')
        ->and($tool['parameters'])->toBe(['command' => 'echo hello']);
});

test('a tool the server resolves is recorded once, not twice', function () {
    // It arrives both as a stream block and as the canonical tool_call frame.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call',
            'tool_name' => 'mcp__bridge__company_directory', 'tool_call_id' => 'toolu_2',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"name":"Jasper"}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchToolCall('company_directory', ['name' => 'Jasper'], 'mcp-req-1');
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tools = collect($blocks)->where('type', 'tool_call')->values();

    expect($tools)->toHaveCount(1)
        ->and($tools[0]['tool_name'])->toBe('company_directory');
});

test('a tool result is recorded with its failure status', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, [
            'tool_call_id' => 'toolu_1', 'result' => 'boom', 'is_error' => true,
        ]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $result = collect($blocks)->firstWhere('type', 'tool_result');

    expect($result['result'])->toBe('boom')
        ->and($result['is_error'])->toBeTrue();
});

test('a result with no reported status records no status at all', function () {
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::TOOL_RESULT, ['tool_call_id' => 't', 'result' => 'ok']));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->firstWhere('type', 'tool_result'))->not->toHaveKey('is_error');
});

test('arguments cut off mid-stream are kept raw rather than reported as none', function () {
    // "the arguments were truncated" and "the tool was called with none" are
    // different things and a reader should be able to tell them apart.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
            'block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'Read',
        ]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{"file_pa']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    $tool = collect($blocks)->firstWhere('type', 'tool_call');

    expect($tool['parameters'])->toBe(['_raw' => '{"file_pa']);
});

test('a nameless tool block is still dropped', function () {
    // Nothing worth showing, and it would render as an empty wrench.
    $blocks = recordTurn(function (StreamHandler $h) {
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 0, 'block_type' => 'tool_call']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 0, 'content' => '{}']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 0]));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_START, ['block_index' => 1, 'block_type' => 'text']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => 1, 'content' => 'the answer']));
        $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => 1]));
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->where('type', 'tool_call'))->toHaveCount(0)
        ->and(collect($blocks)->firstWhere('type', 'text')['text'])->toBe('the answer');
});

test('a run of local tools is recorded so it can be counted, not lumped', function () {
    // The whole point of the change: "3 commands" instead of "3 tool calls".
    $blocks = recordTurn(function (StreamHandler $h) {
        foreach ([['Bash', '{"command":"a"}'], ['Bash', '{"command":"b"}'], ['Read', '{"file_path":"/x"}']] as $i => [$name, $args]) {
            $h->dispatchEvent(wire(MessageTypes::BLOCK_START, [
                'block_index' => $i, 'block_type' => 'tool_call', 'tool_name' => $name,
            ]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_DELTA, ['block_index' => $i, 'content' => $args]));
            $h->dispatchEvent(wire(MessageTypes::BLOCK_STOP, ['block_index' => $i]));
        }
        $h->dispatchEvent(wire(MessageTypes::DONE, ['usage' => null]));
    });

    expect(collect($blocks)->where('type', 'tool_call')->pluck('tool_name')->all())
        ->toBe(['Bash', 'Bash', 'Read']);
});
