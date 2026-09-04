<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The attachment routes, over real HTTP
|--------------------------------------------------------------------------
|
| The controller tests hand-set `bridge_user_id` and call the controller
| directly, so they would pass with the middleware removed from the route
| entirely. These go through the router, and are about the guard rather than
| the behaviour behind it.
|
*/

function attachmentFile(string $contents = 'x'): string
{
    $dir = sys_get_temp_dir().'/ai-bridge-route-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $path = $dir.'/doc.pdf';
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function () {
    app(AiBridgeManager::class)->resolveAttachmentsUsing(fn () => new SplFileInfo(attachmentFile()));
    app(AiBridgeManager::class)->storeAttachmentUsing(fn () => ['id' => 'att_new']);
});

it('refuses a download with no token at all', function () {
    $this->getJson('/ai-bridge/attachments/att_1')->assertStatus(401);
});

it('refuses a download with a garbage token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-jwt')
        ->getJson('/ai-bridge/attachments/att_1')
        ->assertStatus(401);
});

it('refuses an internal_relay token, which is not a bridge credential', function () {
    // Relay tokens are minted per request for the server's own use and are
    // short-lived; they must not be able to read a user's files.
    $relay = app(TokenManager::class)->generate(
        'user-1',
        ['scope' => TokenManager::INTERNAL_RELAY_SCOPE],
        60,
    );

    $this->withHeader('Authorization', "Bearer {$relay}")
        ->getJson('/ai-bridge/attachments/att_1')
        ->assertStatus(401);
});

it('serves a download to a valid bridge token', function () {
    $token = app(TokenManager::class)->generate('user-1');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->get('/ai-bridge/attachments/att_1')
        ->assertStatus(200);
});

it('scopes the download to the token subject', function () {
    $seen = [];
    app(AiBridgeManager::class)->resolveAttachmentsUsing(function (string $id, $userId) use (&$seen) {
        $seen = [$id, $userId];

        return null;
    });

    $token = app(TokenManager::class)->generate('key-77');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/ai-bridge/attachments/att_5')
        ->assertStatus(404);

    // Never from the request — always the validated token's subject.
    expect($seen)->toBe(['att_5', 'key-77']);
});

it('refuses an upload with no token', function () {
    $this->postJson('/ai-bridge/attachments', [])->assertStatus(401);
});

it('accepts an upload from a valid bridge token', function () {
    $token = app(TokenManager::class)->generate('user-1');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/ai-bridge/attachments', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('r.md', '# r'),
        ])
        ->assertStatus(201)
        ->assertJson(['id' => 'att_new']);
});

it('does not accept an attachment id containing a path separator', function () {
    // The route constraint is the outer guard on what reaches the app's
    // resolver, which is where a naive storage_path("attachments/$id") lives.
    $token = app(TokenManager::class)->generate('user-1');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/ai-bridge/attachments/..%2F..%2Fetc%2Fpasswd')
        ->assertStatus(404);
});
