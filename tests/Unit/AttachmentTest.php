<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Http\Controllers\AttachmentController;
use Tetrix\AiBridge\Models\Conversation;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Attachments
|--------------------------------------------------------------------------
|
| The package stores nothing itself — where the bytes live is the app's
| business, registered through two resolvers. What is tested here is the part
| the package DOES own: that nothing is served without a resolver, that the
| lookup is scoped to the bridge's own user, and that the references handed to
| the bridge describe the file the server would actually serve.
|
*/

/** A request that has already passed the bridge-token middleware. */
function bridgeRequest(string $method = 'GET', string $uri = '/ai-bridge/attachments/att_1', array $params = [], string $userId = 'key-1'): Request
{
    $request = Request::create($uri, $method, $params);
    $request->attributes->set('bridge_user_id', $userId);

    return $request;
}

function tempAttachment(string $contents = 'the file contents', string $name = 'invoice.pdf'): string
{
    $dir = sys_get_temp_dir().'/ai-bridge-attach-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $path = $dir.'/'.$name;
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function () {
    app(AiBridgeManager::class)->resolveAttachmentsUsing(fn () => null);
});

describe('serving an attachment to the bridge', function () {
    it('serves nothing when the app registered no resolver', function () {
        // Secure by default: the package will not invent a storage location
        // and read from it.
        $manager = app(AiBridgeManager::class);
        $reflection = new ReflectionProperty($manager, 'attachmentResolver');
        $reflection->setValue($manager, null);

        $response = app(AttachmentController::class)->show(bridgeRequest(), 'att_1');

        expect($response->getStatusCode())->toBe(404);
    });

    it('serves the file the resolver returns', function () {
        $path = tempAttachment();
        app(AiBridgeManager::class)->resolveAttachmentsUsing(
            fn (string $id, $userId) => $id === 'att_1' ? new SplFileInfo($path) : null
        );

        $response = app(AttachmentController::class)->show(bridgeRequest(), 'att_1');

        expect($response->getStatusCode())->toBe(200)
            ->and($response->headers->get('content-disposition'))->toContain('attachment');
    });

    it('hands the resolver the user the token was issued for, never the request', function () {
        $seen = [];
        app(AiBridgeManager::class)->resolveAttachmentsUsing(function (string $id, $userId) use (&$seen) {
            $seen[] = [$id, $userId];

            return null;
        });

        app(AttachmentController::class)->show(bridgeRequest(userId: 'key-9'), 'att_5');

        expect($seen)->toBe([['att_5', 'key-9']]);
    });

    it('answers 404 identically for missing and for not-yours', function () {
        // Telling the two apart would make this route an oracle for which
        // attachment ids exist.
        app(AiBridgeManager::class)->resolveAttachmentsUsing(fn () => null);

        $missing = app(AttachmentController::class)->show(bridgeRequest(), 'att_does_not_exist');
        $notMine = app(AttachmentController::class)->show(bridgeRequest(), 'att_someone_elses');

        expect($missing->getStatusCode())->toBe(404)
            ->and($notMine->getStatusCode())->toBe(404)
            ->and($missing->getContent())->toBe($notMine->getContent());
    });

    it('404s when the resolver returns a path that is not a readable file', function () {
        app(AiBridgeManager::class)->resolveAttachmentsUsing(
            fn () => new SplFileInfo('/tmp/definitely-not-here-'.bin2hex(random_bytes(4)))
        );

        expect(app(AttachmentController::class)->show(bridgeRequest(), 'att_1')->getStatusCode())->toBe(404);
    });
});

describe('accepting a file from the assistant', function () {
    it('answers 501 when the app never opted into the return direction', function () {
        // Nothing is broken — the app simply has no store. The bridge surfaces
        // the message to the model, which can then say so rather than retry.
        $manager = app(AiBridgeManager::class);
        (new ReflectionProperty($manager, 'attachmentStore'))->setValue($manager, null);

        $response = app(AttachmentController::class)->store(bridgeRequest('POST', '/ai-bridge/attachments'));

        expect($response->getStatusCode())->toBe(501);
    });

    it('rejects a request with no file', function () {
        app(AiBridgeManager::class)->storeAttachmentUsing(fn () => ['id' => 'att_new']);

        $response = app(AttachmentController::class)->store(bridgeRequest('POST', '/ai-bridge/attachments'));

        expect($response->getStatusCode())->toBe(422);
    });

    it('stores the file and returns what the app said about it', function () {
        $seen = [];
        app(AiBridgeManager::class)->storeAttachmentUsing(function (UploadedFile $file, $userId) use (&$seen) {
            $seen = [$file->getClientOriginalName(), $userId];

            return ['id' => 'att_new', 'url' => 'https://studio.test/a/att_new'];
        });

        $request = bridgeRequest('POST', '/ai-bridge/attachments', [], 'key-3');
        $request->files->set('file', UploadedFile::fake()->createWithContent('report.md', '# report'));

        $response = app(AttachmentController::class)->store($request);

        expect($response->getStatusCode())->toBe(201)
            ->and(json_decode($response->getContent(), true)['id'])->toBe('att_new')
            ->and($seen)->toBe(['report.md', 'key-3']);
    });

    it('reports a failure in the app store as a 500, not as a success', function () {
        app(AiBridgeManager::class)->storeAttachmentUsing(function () {
            throw new RuntimeException('disk full');
        });

        $request = bridgeRequest('POST', '/ai-bridge/attachments');
        $request->files->set('file', UploadedFile::fake()->createWithContent('a.txt', 'x'));

        expect(app(AttachmentController::class)->store($request)->getStatusCode())->toBe(500);
    });

    it('refuses a store that does not return an id', function () {
        app(AiBridgeManager::class)->storeAttachmentUsing(fn () => ['url' => 'https://x.test/a']);

        $request = bridgeRequest('POST', '/ai-bridge/attachments');
        $request->files->set('file', UploadedFile::fake()->createWithContent('a.txt', 'x'));

        // Surfaces as a 500: the bridge needs an id to put on its stream event,
        // and without one the file would arrive nowhere.
        expect(app(AttachmentController::class)->store($request)->getStatusCode())->toBe(500);
    });
});

describe('building the references sent to the bridge', function () {
    it('describes the file the server would actually serve', function () {
        $path = tempAttachment('the file contents', 'invoice.pdf');
        app(AiBridgeManager::class)->resolveAttachmentsUsing(fn () => new SplFileInfo($path));

        $refs = app(AiBridgeManager::class)->buildAttachmentRefs(['att_1'], 'key-1');

        expect($refs[0]['id'])->toBe('att_1')
            ->and($refs[0]['name'])->toBe('invoice.pdf')
            ->and($refs[0]['size'])->toBe(strlen('the file contents'))
            // Computed from the bytes, so the bridge's verification compares
            // against the same file rather than against a claim beside it.
            ->and($refs[0]['sha256'])->toBe(hash('sha256', 'the file contents'));
    });

    it('builds the URL itself rather than taking one from the caller', function () {
        // A URL from client input is a URL the bridge then fetches with its own
        // token attached. The server knows its own attachment route.
        $path = tempAttachment();
        app(AiBridgeManager::class)->resolveAttachmentsUsing(fn () => new SplFileInfo($path));

        $refs = app(AiBridgeManager::class)->buildAttachmentRefs(['att_1'], 'key-1');

        expect($refs[0]['url'])->toEndWith('/ai-bridge/attachments/att_1');
    });

    it('refuses an id that does not resolve', function () {
        app(AiBridgeManager::class)->resolveAttachmentsUsing(fn () => null);

        expect(fn () => app(AiBridgeManager::class)->buildAttachmentRefs(['att_nope'], 'key-1'))
            ->toThrow(InvalidArgumentException::class, 'att_nope');
    });

    it('scopes the lookup to the bridge user, so ids cannot be borrowed', function () {
        $path = tempAttachment();
        app(AiBridgeManager::class)->resolveAttachmentsUsing(
            fn (string $id, $userId) => $userId === 'key-1' ? new SplFileInfo($path) : null
        );

        expect(app(AiBridgeManager::class)->buildAttachmentRefs(['att_1'], 'key-1'))->toHaveCount(1);
        expect(fn () => app(AiBridgeManager::class)->buildAttachmentRefs(['att_1'], 'key-2'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('attaching files to a turn', function () {
    it('rejects the turn when an attachment id does not resolve', function () {
        app(AiBridgeManager::class)->resolveConversationsUsing(fn ($request) => Conversation::query());
        app(AiBridgeManager::class)->resolveAttachmentsUsing(fn () => null);

        $conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);

        $response = app(\Tetrix\AiBridge\Http\Controllers\ConversationController::class)->stream(
            Request::create("/ai-bridge/conversations/{$conversation->id}/stream", 'POST', [
                'message' => 'what does this say?',
                'attachments' => ['att_nope'],
            ]),
            $conversation->id,
        );

        // A 422 on the request that caused it, rather than a turn that starts
        // and fails somewhere the user cannot see.
        expect($response->getStatusCode())->toBe(422);
    });
});
