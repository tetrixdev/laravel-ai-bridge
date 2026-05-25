<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;
use Tetrix\AiBridge\Streaming\ConversationRecorder;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;

uses(RefreshDatabase::class);

/** Minimal no-op provider for driving a StreamHandler in tests. */
function fakeProvider(): StreamableProvider
{
    return new class implements StreamableProvider {
        public function setConversationId(string $c): static { return $this; }
        public function setMessage(string $m): static { return $this; }
        public function setOptions(array $o): static { return $this; }
        public function start(): void {}
        public function cancel(): void {}
        public function getStreamHandler(): StreamHandler { throw new RuntimeException('n/a'); }
        public function markCompleted(): void {}
    };
}

it('persists conversations and appends messages', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);

    expect($conversation->id)->toBeGreaterThan(0);

    $conversation->appendMessage(Message::ROLE_USER, 'hello');

    expect($conversation->messages()->count())->toBe(1)
        ->and($conversation->messages()->first()->role)->toBe('user');
});

it('appendMessage bumps the conversation updated_at', function () {
    $conversation = Conversation::create(['mode' => 'bridge']);
    $conversation->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
    $stale = $conversation->fresh()->updated_at;

    $conversation->appendMessage(Message::ROLE_USER, 'hi');

    expect($conversation->fresh()->updated_at->gt($stale))->toBeTrue();
});

it('historyFor includes tool calls and results but excludes thinking blocks', function () {
    $conversation = Conversation::create(['mode' => 'bridge']);
    $conversation->appendMessage(Message::ROLE_USER, 'roll a die');
    $conversation->appendMessage(Message::ROLE_ASSISTANT, 'Rolled a 4.', [
        'blocks' => [
            ['type' => 'thinking', 'text' => 'secret chain of reasoning'],
            ['type' => 'tool_call', 'tool_name' => 'roll_dice', 'parameters' => ['sides' => 6], 'tool_call_id' => 't1'],
            ['type' => 'tool_result', 'tool_call_id' => 't1', 'result' => '4'],
            ['type' => 'text', 'text' => 'Rolled a 4.'],
        ],
    ]);

    $conversation->load('messages');
    $history = $conversation->historyFor();

    expect($history)->toHaveCount(2);

    $assistant = $history[1]['content'];
    expect($assistant)->toContain('roll_dice')      // tool call retained
        ->and($assistant)->toContain('4')            // tool result retained
        ->and($assistant)->toContain('Rolled a 4.')  // text retained
        ->and($assistant)->not->toContain('secret chain of reasoning'); // thinking excluded
});

it('ConversationRecorder writes the assistant turn on done', function () {
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude', 'model' => 'sonnet']);
    $conversation->appendMessage(Message::ROLE_USER, 'hello');

    $handler = new StreamHandler(fakeProvider());
    $handler->setMode(ProviderMode::Bridge);
    ConversationRecorder::attach($handler, $conversation);

    $handler->dispatchBlockStart(BlockType::Thinking, 0);
    $handler->dispatchBlockDelta(BlockType::Thinking, 0, 'hmm');
    $handler->dispatchBlockStop(BlockType::Thinking, 0);
    $handler->dispatchBlockStart(BlockType::Text, 1);
    $handler->dispatchBlockDelta(BlockType::Text, 1, 'Hello ');
    $handler->dispatchBlockDelta(BlockType::Text, 1, 'world');
    $handler->dispatchBlockStop(BlockType::Text, 1);
    $handler->dispatchDone(['input_tokens' => 3, 'output_tokens' => 5]);

    $assistant = $conversation->messages()->where('role', 'assistant')->first();

    expect($assistant)->not->toBeNull()
        ->and($assistant->content)->toBe('Hello world')   // flat content = text only
        ->and($assistant->usage)->toBe(['input_tokens' => 3, 'output_tokens' => 5])
        ->and($assistant->incomplete)->toBeFalse()
        ->and($assistant->blocks)->toHaveCount(2);          // thinking block kept for replay
});

it('ConversationRecorder drops shadow stream-event tool_call blocks in bridge mode', function () {
    // In bridge/MCP mode the CLI emits a block_start(tool_call) + block_delta
    // (args text) + block_stop AND the bridge separately dispatches a
    // structured WS tool_call frame. Persisting both produced two tool_call
    // blocks per invocation: a "shadow" `{type:'tool_call', text:'<args json>'}`
    // with no tool_name, plus the canonical `{tool_name, parameters,
    // tool_call_id}`. The recorder must drop the shadow and keep only the
    // WS-frame block; the tool_result block keeps the dispatcher's id (the
    // chat UI renders tool_result standalone, so no id-pairing is needed).
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'gemini']);
    $conversation->appendMessage(Message::ROLE_USER, 'roll 1d20 and 1d6');

    $handler = new StreamHandler(fakeProvider());
    $handler->setMode(ProviderMode::Bridge);
    ConversationRecorder::attach($handler, $conversation);

    // First tool call: stream-event shadow (block_start/delta/stop) + WS frame.
    $handler->dispatchBlockStart(BlockType::ToolCall, 0);
    $handler->dispatchBlockDelta(BlockType::ToolCall, 0, '{"notation":"1d20"}');
    $handler->dispatchBlockStop(BlockType::ToolCall, 0);
    $handler->dispatchToolCall('roll_dice', ['notation' => '1d20'], 'mcp-rid-12');

    // Second tool call: same shape (Gemini parallel-tool-call pattern).
    $handler->dispatchBlockStart(BlockType::ToolCall, 1);
    $handler->dispatchBlockDelta(BlockType::ToolCall, 1, '{"notation":"1d6"}');
    $handler->dispatchBlockStop(BlockType::ToolCall, 1);
    $handler->dispatchToolCall('roll_dice', ['notation' => '1d6'], 'mcp-rid-13');

    // Tool results come back from the CLI's stream with the CLI's own ids.
    $handler->dispatchToolResult('cli-1779-0', '{"total":11}');
    $handler->dispatchToolResult('cli-1779-1', '{"total":6}');

    // Final text block from the model.
    $handler->dispatchBlockStart(BlockType::Text, 2);
    $handler->dispatchBlockDelta(BlockType::Text, 2, 'Rolled 11 and 6.');
    $handler->dispatchBlockStop(BlockType::Text, 2);
    $handler->dispatchDone(null);

    $assistant = $conversation->messages()->where('role', 'assistant')->first();
    $blocks = $assistant->blocks;

    // 5 blocks where the pre-fix recorder would have produced 7 (two extra
    // shadow tool_call entries with `{text:'…json…'}` and no tool_name).
    expect($blocks)->toHaveCount(5);
    expect($blocks[0])->toBe([
        'type' => 'tool_call',
        'tool_name' => 'roll_dice',
        'parameters' => ['notation' => '1d20'],
        'tool_call_id' => 'mcp-rid-12',
    ]);
    expect($blocks[1])->toBe([
        'type' => 'tool_call',
        'tool_name' => 'roll_dice',
        'parameters' => ['notation' => '1d6'],
        'tool_call_id' => 'mcp-rid-13',
    ]);
    // tool_result keeps the CLI's id. The chat UI renders them standalone, so
    // the mismatch with the WS tool_call id is harmless.
    expect($blocks[2])->toBe([
        'type' => 'tool_result',
        'tool_call_id' => 'cli-1779-0',
        'result' => '{"total":11}',
    ]);
    expect($blocks[3])->toBe([
        'type' => 'tool_result',
        'tool_call_id' => 'cli-1779-1',
        'result' => '{"total":6}',
    ]);
    expect($blocks[4])->toBe(['type' => 'text', 'text' => 'Rolled 11 and 6.']);
});

it('ConversationRecorder recovers from a truncated shadow tool_call (no block_stop)', function () {
    // If the stream errors / cancels mid-tool-call, the `inStreamToolCall`
    // flag is left set without a matching block_stop. A naïve implementation
    // would silently swallow any later block_delta. Verify the recorder
    // resets state on terminal events.
    $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $conversation->appendMessage(Message::ROLE_USER, 'roll dice and tell me');

    $handler = new StreamHandler(fakeProvider());
    $handler->setMode(ProviderMode::Bridge);
    ConversationRecorder::attach($handler, $conversation);

    // Shadow tool_call opens, no block_stop ever arrives.
    $handler->dispatchBlockStart(BlockType::ToolCall, 0);
    $handler->dispatchBlockDelta(BlockType::ToolCall, 0, '{"notation":"1d20"}');

    // Stream errors before block_stop.
    $handler->dispatchError('provider_error', 'connection dropped');

    $assistant = $conversation->messages()->where('role', 'assistant')->first();

    // Partial persistence path is exercised. Whether the assistant row exists
    // depends on hasContent() — with no real content yet, no row should be
    // written. The key assertion is that no exception escaped.
    expect($assistant)->toBeNull();
});

it('ConversationRecorder flags a partial turn on error', function () {
    config()->set('ai-bridge.persistence.persist_partial_on_error', true);

    $conversation = Conversation::create(['mode' => 'bridge']);

    $handler = new StreamHandler(fakeProvider());
    $handler->setMode(ProviderMode::Bridge);
    ConversationRecorder::attach($handler, $conversation);

    $handler->dispatchBlockStart(BlockType::Text, 0);
    $handler->dispatchBlockDelta(BlockType::Text, 0, 'partial...');
    $handler->dispatchError('provider_error', 'boom');

    $assistant = $conversation->messages()->where('role', 'assistant')->first();

    expect($assistant)->not->toBeNull()
        ->and($assistant->incomplete)->toBeTrue()
        ->and($assistant->content)->toBe('partial...');
});

it('ToolRegistry hash is stable and order-independent', function () {
    $a = new ToolRegistry();
    $base = $a->hash();

    $a->register('roll_dice', 'Roll dice', ['type' => 'object', 'properties' => []], fn () => null);
    $a->register('get_time', 'Get time', ['type' => 'object', 'properties' => []], fn () => null);

    $b = new ToolRegistry();
    $b->register('get_time', 'Get time', ['type' => 'object', 'properties' => []], fn () => null);
    $b->register('roll_dice', 'Roll dice', ['type' => 'object', 'properties' => []], fn () => null);

    expect($a->hash())->not->toBe($base)        // changes with the tool set
        ->and($a->hash())->toBe($b->hash());     // independent of registration order
});
