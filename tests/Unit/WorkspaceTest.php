<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Http\Controllers\ConversationController;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Workspaces
|--------------------------------------------------------------------------
|
| The server's half of "the assistant works in a real checkout": relaying the
| bridge's allow-list to the app so it can show a picker, and remembering the
| choice on the conversation so every turn sends the same one. The bridge is
| the authority on what is permitted; this side is about not asking it
| something it will have to refuse.
|
*/

function workspaceMessageHandler(BridgeConnectionManager $manager): MessageHandler
{
    return new MessageHandler(
        connectionManager: $manager,
        tokenManager: app(TokenManager::class),
        toolRegistry: new ToolRegistry(),
    );
}

beforeEach(function () {
    app(AiBridgeManager::class)->resolveConversationsUsing(fn ($request) => Conversation::query());
    app(AiBridgeManager::class)->resolveConnectionsUsing(fn ($request) => Connection::query());
});

describe('the hello handshake', function () {
    it('records the workspaces a bridge advertises', function () {
        $manager = new BridgeConnectionManager();
        $token = app(TokenManager::class)->generate('user-1');

        workspaceMessageHandler($manager)->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::HELLO,
            'version' => '0.1',
            'token' => $token,
            'providers' => [['name' => 'claude', 'available' => true]],
            'workspaces' => [['path' => '/repos/studio', 'label' => 'Studio']],
        ]));

        expect($manager->getWorkspaces('user-1'))
            ->toBe([['path' => '/repos/studio', 'label' => 'Studio']]);
    });

    it('records an empty list when the bridge advertises none', function () {
        // Which is also what an older bridge looks like — and means the same
        // thing: naming a working directory gets refused.
        $manager = new BridgeConnectionManager();
        $token = app(TokenManager::class)->generate('user-1');

        workspaceMessageHandler($manager)->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::HELLO,
            'version' => '0.1',
            'token' => $token,
            'providers' => [],
        ]));

        expect($manager->getWorkspaces('user-1'))->toBe([]);
    });
});

describe('the welcome message', function () {
    it('forwards workspace isolation when the app configured it', function () {
        config()->set('ai-bridge.cli.isolation', 'workspace');
        $manager = new BridgeConnectionManager();
        $token = app(TokenManager::class)->generate('user-1');

        $welcome = workspaceMessageHandler($manager)->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::HELLO,
            'version' => '0.1',
            'token' => $token,
            'providers' => [],
        ]));

        expect($welcome['cli_isolation'])->toBe('workspace');
    });

    it('still normalises an unrecognised posture back to isolated', function () {
        // Default-safe rather than default-leaky: only an exact opt-in counts.
        config()->set('ai-bridge.cli.isolation', 'workspac');
        $manager = new BridgeConnectionManager();
        $token = app(TokenManager::class)->generate('user-1');

        $welcome = workspaceMessageHandler($manager)->handleMessage('conn-1', null, json_encode([
            'type' => MessageTypes::HELLO,
            'version' => '0.1',
            'token' => $token,
            'providers' => [],
        ]));

        expect($welcome['cli_isolation'])->toBe('isolated');
    });
});

describe('choosing a workspace for a conversation', function () {
    it('stores the working directory on the conversation', function () {
        $connection = Connection::create([
            'type' => 'bridge',
            'name' => 'laptop',
            'connection_key' => 'key-1',
            'last_workspaces' => [['path' => '/repos', 'label' => 'Repos']],
        ]);

        $response = app(ConversationController::class)->store(
            Request::create('/ai-bridge/conversations', 'POST', [
                'mode' => 'bridge',
                'connection_id' => $connection->id,
                'working_dir' => '/repos/studio',
            ])
        );

        expect($response->getStatusCode())->toBe(201)
            ->and(json_decode($response->getContent(), true)['working_dir'])->toBe('/repos/studio');
    });

    it('refuses a directory the connected bridge does not advertise', function () {
        // A courtesy, not the control — the bridge refuses it too. But a 422 on
        // the request that caused it beats a stream error mid-chat.
        $connection = Connection::create([
            'type' => 'bridge',
            'name' => 'laptop',
            'connection_key' => 'key-1',
            'last_workspaces' => [['path' => '/repos', 'label' => 'Repos']],
        ]);

        $response = app(ConversationController::class)->store(
            Request::create('/ai-bridge/conversations', 'POST', [
                'mode' => 'bridge',
                'connection_id' => $connection->id,
                'working_dir' => '/etc',
            ])
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('does not mistake a sibling for a child of an allowed root', function () {
        $connection = Connection::create([
            'type' => 'bridge',
            'name' => 'laptop',
            'connection_key' => 'key-1',
            'last_workspaces' => [['path' => '/repos', 'label' => 'Repos']],
        ]);

        $response = app(ConversationController::class)->store(
            Request::create('/ai-bridge/conversations', 'POST', [
                'mode' => 'bridge',
                'connection_id' => $connection->id,
                'working_dir' => '/repos-other/thing',
            ])
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('lets a directory through when the bridge has advertised nothing yet', function () {
        // It may simply be between reconnects. The bridge gives the
        // authoritative answer either way, so refusing here would only ever be
        // wrong in one direction.
        $connection = Connection::create([
            'type' => 'bridge',
            'name' => 'laptop',
            'connection_key' => 'key-1',
        ]);

        $response = app(ConversationController::class)->store(
            Request::create('/ai-bridge/conversations', 'POST', [
                'mode' => 'bridge',
                'connection_id' => $connection->id,
                'working_dir' => '/repos/studio',
            ])
        );

        expect($response->getStatusCode())->toBe(201);
    });
});

describe('the shape of a working directory', function () {
    it('refuses a traversal segment rather than leaving it to the bridge', function () {
        // The bridge realpaths and would catch it — but the answer would arrive
        // as a stream error mid-chat instead of a 422 on the request that
        // caused it, which is the whole reason this check exists.
        $connection = Connection::create([
            'type' => 'bridge', 'name' => 'laptop', 'connection_key' => 'key-1',
            'last_workspaces' => [['path' => '/repos', 'label' => 'Repos']],
        ]);

        foreach (['/repos/../../root/.ssh', '/repos//../etc', '/repos/./../../etc/shadow'] as $bad) {
            $response = app(ConversationController::class)->store(
                Request::create('/ai-bridge/conversations', 'POST', [
                    'mode' => 'bridge',
                    'connection_id' => $connection->id,
                    'working_dir' => $bad,
                ])
            );
            expect($response->getStatusCode())->toBe(422);
        }
    });

    it('refuses a relative path', function () {
        $response = app(ConversationController::class)->store(
            Request::create('/ai-bridge/conversations', 'POST', [
                'mode' => 'bridge',
                'working_dir' => 'repos/studio',
            ])
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('matches an advertised root that carries a trailing slash', function () {
        // "/repos/" and "/repos" are the same directory; rejecting the exact
        // choice the picker offered would be absurd.
        $connection = Connection::create([
            'type' => 'bridge', 'name' => 'laptop', 'connection_key' => 'key-1',
            'last_workspaces' => [['path' => '/repos/', 'label' => 'Repos']],
        ]);

        $response = app(ConversationController::class)->store(
            Request::create('/ai-bridge/conversations', 'POST', [
                'mode' => 'bridge',
                'connection_id' => $connection->id,
                'working_dir' => '/repos',
            ])
        );

        expect($response->getStatusCode())->toBe(201);
    });

    it('re-checks a directory chosen before the connection was known', function () {
        // store() can be called with no connection_id, so there is no
        // advertised list to check against yet. stream() is where the
        // connection IS known, and the check must happen somewhere.
        $connection = Connection::create([
            'type' => 'bridge', 'name' => 'laptop', 'connection_key' => 'key-1',
            'last_workspaces' => [['path' => '/repos', 'label' => 'Repos']],
        ]);

        $created = app(ConversationController::class)->store(
            Request::create('/ai-bridge/conversations', 'POST', [
                'mode' => 'bridge', 'provider' => 'claude', 'working_dir' => '/etc',
            ])
        );
        expect($created->getStatusCode())->toBe(201);

        $conversation = Conversation::find(json_decode($created->getContent(), true)['id']);
        $conversation->fill(['connection_id' => $connection->id])->save();

        $response = app(ConversationController::class)->stream(
            Request::create("/ai-bridge/conversations/{$conversation->id}/stream", 'POST', ['message' => 'hi']),
            $conversation->id,
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('refuses an over-long working directory on the stream path too', function () {
        $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);

        $response = app(ConversationController::class)->stream(
            Request::create("/ai-bridge/conversations/{$conversation->id}/stream", 'POST', [
                'message' => 'hi',
                'working_dir' => '/'.str_repeat('a', 2000),
            ]),
            $conversation->id,
        );

        // Otherwise it reaches a varchar(1024) column and 500s on MySQL.
        expect($response->getStatusCode())->toBe(422);
    });
});

describe('choosing a workspace after the session exists', function () {
    it('starts a fresh CLI session rather than wedging the conversation', function () {
        // The bridge ties a directory to a session for its life. Turn 1 ran
        // with no directory, so the session is bound to the scratch dir;
        // sending a workspace on turn 2 with that session id would be refused
        // as working_dir_changed — and so would every turn after it, while the
        // directory could no longer be changed back. Dropping the session is
        // exactly what the bridge asks the server to do.
        $conversation = Conversation::create([
            'mode' => 'bridge',
            'provider' => 'claude',
            'cli_session_id' => 'sess-1',
        ]);

        app(ConversationController::class)->stream(
            Request::create("/ai-bridge/conversations/{$conversation->id}/stream", 'POST', [
                'message' => 'now work in the repo',
                'working_dir' => '/repos/studio',
            ]),
            $conversation->id,
        );

        $conversation->refresh();
        expect($conversation->working_dir)->toBe('/repos/studio')
            ->and($conversation->cli_session_id)->toBeNull();
    });
});

describe('a conversation keeps its working directory', function () {
    it('refuses a later turn that names a different one', function () {
        // The bridge fixes the directory for a CLI session's life and answers
        // `working_dir_changed`. Catching it here says so in a place the caller
        // can act on, rather than as a stream error halfway through a chat.
        $conversation = Conversation::create([
            'mode' => 'bridge',
            'provider' => 'claude',
            'working_dir' => '/repos/studio',
        ]);

        $response = app(ConversationController::class)->stream(
            Request::create("/ai-bridge/conversations/{$conversation->id}/stream", 'POST', [
                'message' => 'hi',
                'working_dir' => '/repos/other',
            ]),
            $conversation->id,
        );

        expect($response->getStatusCode())->toBe(422)
            ->and(json_decode($response->getContent(), true)['message'])
            ->toContain('cannot change working directory');
    });
});
