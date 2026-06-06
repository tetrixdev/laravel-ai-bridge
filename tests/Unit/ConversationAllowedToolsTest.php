<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| Per-conversation allowed-tools enforcement
|--------------------------------------------------------------------------
|
| Advertising a filtered tool set is not enough: executeToolCall() must also
| refuse to run a tool the conversation never exposed, so a forged bridge
| tool_call can't bypass the allowlist.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    $this->manager = new BridgeConnectionManager();

    $this->registry = new ToolRegistry();
    $this->registry->register('echo', 'Echo', ['type' => 'object'], fn ($p) => ['echoed' => $p]);
    $this->registry->register('secret', 'Secret', ['type' => 'object'], fn ($p) => ['ran' => true]);

    $this->messageHandler = new MessageHandler(
        connectionManager: $this->manager,
        tokenManager: app(\Tetrix\AiBridge\Auth\TokenManager::class),
        toolRegistry: $this->registry,
    );
});

function handlerForConversation(BridgeConnectionManager $manager, string $conversationId): StreamHandler
{
    $provider = Mockery::mock(StreamableProvider::class);
    $provider->shouldReceive('start', 'cancel', 'markCompleted')->byDefault();

    $handler = new StreamHandler($provider);
    $handler->setMode(ProviderMode::Byok);
    $handler->setConversationId($conversationId);

    return $handler;
}

function toolCall(MessageHandler $mh, string $tool): ?array
{
    return $mh->handleMessage('conn-1', null, (string) json_encode([
        'type' => MessageTypes::TOOL_CALL,
        'request_id' => 'req-1',
        'tool_name' => $tool,
        'parameters' => [],
        'call_id' => 'call-1',
    ]));
}

test('a conversation only runs the tools on its allowlist', function () {
    $conversation = Conversation::create(['mode' => 'byok', 'allowed_tools' => ['echo']]);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', handlerForConversation($this->manager, (string) $conversation->id), 'user-1');

    // The allowed tool runs.
    expect(toolCall($this->messageHandler, 'echo')['type'])->toBe(MessageTypes::TOOL_RESOLVE);

    // The disallowed tool is refused at execution time, even though it is registered globally.
    $denied = toolCall($this->messageHandler, 'secret');
    expect($denied['type'])->toBe(MessageTypes::TOOL_ERROR);
    expect($denied['error'])->toContain('not allowed');
});

test('a conversation with null allowed_tools runs any registered tool', function () {
    $conversation = Conversation::create(['mode' => 'byok']); // allowed_tools defaults to null

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', handlerForConversation($this->manager, (string) $conversation->id), 'user-1');

    expect(toolCall($this->messageHandler, 'secret')['type'])->toBe(MessageTypes::TOOL_RESOLVE);
});

test('a conversation with an empty allowed_tools list runs no tool', function () {
    // [] is distinct from null: an explicit "this conversation exposes no tools".
    $conversation = Conversation::create(['mode' => 'byok', 'allowed_tools' => []]);

    $this->manager->addConnection('user-1', 'conn-1');
    $this->manager->registerPendingRequest('req-1', handlerForConversation($this->manager, (string) $conversation->id), 'user-1');

    $denied = toolCall($this->messageHandler, 'echo');
    expect($denied['type'])->toBe(MessageTypes::TOOL_ERROR);
    expect($denied['error'])->toContain('not allowed');
});
