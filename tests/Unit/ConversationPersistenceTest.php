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
