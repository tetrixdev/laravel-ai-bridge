<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Streaming\StreamHandler;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\Tools\ToolResult;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
|--------------------------------------------------------------------------
| Tool results with text and image parts (tool_resolve.content)
|--------------------------------------------------------------------------
|
| A bridge that announced hello.tool_result_content gets the parts; any other
| gets `result`, the text with each image described. The image bytes go to the
| bridge only: the view's copy, the stream store and the recorded turn hold the
| description. Driven through the serve process's MessageHandler.
|
*/

uses(RefreshDatabase::class);

// A 1x1 transparent PNG.
const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function contentRig(bool $capable = true): object
{
    Event::fake();

    $registry = new ToolRegistry();
    $registry->register('render_map', 'Draw the floor', [], fn () => ToolResult::withContent([
        ToolResult::textPart('Floor 1, 40x30 squares.'),
        ToolResult::imagePart('image/png', TINY_PNG),
    ]));
    $registry->register('render_huge', 'Draw too much', [], fn () => ToolResult::withContent([
        ToolResult::textPart('Huge.'),
        ToolResult::imagePart('image/webp', base64_encode(str_repeat("\x07", 7 * 1024 * 1024))),
    ]));
    $registry->register('render_card', 'Draw the floor in a card', [], fn () => ToolResult::withContent(
        [ToolResult::textPart('Floor 1.'), ToolResult::imagePart('image/png', TINY_PNG)],
        ['floor' => 1],
    ), view: 'ui://dm/map');
    $registry->register('roll', 'A plain tool', [], fn () => '18');

    $rig = new stdClass();
    $rig->manager = new BridgeConnectionManager();
    $rig->manager->addConnection('user-1', 'conn-1');
    $rig->manager->setBridgeInfo('user-1', MessageHandler::bridgeInfoFromHello(
        ['type' => 'hello', 'bridge_version' => '0.38.0'] + ($capable ? ['tool_result_content' => true] : []),
    ));
    $rig->handler = new MessageHandler($rig->manager, app(TokenManager::class), $registry);
    $rig->conversation = Conversation::create(['mode' => 'bridge', 'provider' => 'claude']);
    $rig->handler->registerRelayedRequest('req-c', 'user-1', (string) $rig->conversation->id);
    $rig->send = fn (array $frame) => $rig->handler->handleMessage('conn-1', null, json_encode($frame));
    $rig->call = fn (string $tool, ?string $providerId = null) => ($rig->send)([
        'type' => MessageTypes::TOOL_CALL, 'request_id' => 'req-c', 'tool_call_id' => 'tc_1', 'tool_name' => $tool, 'arguments' => [],
    ] + ($providerId !== null ? ['provider_tool_call_id' => $providerId] : []));

    return $rig;
}

it('reads tool_result_content from hello', function () {
    expect(MessageHandler::bridgeInfoFromHello(['type' => 'hello', 'tool_result_content' => true])['capabilities'])
        ->toContain('tool_result_content');
});

it('sends the parts to a bridge that announced them, and the text with the image described as result', function () {
    $reply = (contentRig()->call)('render_map');

    expect($reply['type'])->toBe(MessageTypes::TOOL_RESOLVE)
        ->and($reply['result'])->toBe("Floor 1, 40x30 squares.\n[image: image/png, 0 KB]")
        ->and($reply['content'])->toBe([
            ['type' => 'text', 'text' => 'Floor 1, 40x30 squares.'],
            ['type' => 'image', 'data' => TINY_PNG, 'mimeType' => 'image/png'],
        ]);
});

it('sends an older bridge the text only', function () {
    $reply = (contentRig(capable: false)->call)('render_map');

    expect($reply)->not->toHaveKey('content')
        ->and($reply['result'])->toBe("Floor 1, 40x30 squares.\n[image: image/png, 0 KB]");
});

it('leaves a plain result as it was', function () {
    $reply = (contentRig()->call)('roll');

    expect($reply)->not->toHaveKey('content')->and($reply['result'])->toBe('18');
});

it('sends text only, saying so, when the images would come near the bridge frame limit', function () {
    $reply = (contentRig()->call)('render_huge');

    expect($reply)->not->toHaveKey('content')
        ->and($reply['result'])->toStartWith("Huge.\n[image: image/webp, 7168 KB]\n[images not sent: this result is 9.3 MB, over the 8 MB")
        ->and(strlen(json_encode($reply)))->toBeLessThan(1024);
});

it('keeps the image bytes out of the view, the stream store and the recorded turn', function () {
    $rig = contentRig();
    $stream = fn (string $event, array $data) => ($rig->send)(['type' => MessageTypes::STREAM, 'request_id' => 'req-c', 'event' => $event, 'data' => $data]);

    $stream(MessageTypes::BLOCK_START, ['block_index' => 0, 'block_type' => 'tool_call', 'tool_name' => 'mcp__bridge__render_card', 'tool_call_id' => 'toolu_9']);
    $stream(MessageTypes::BLOCK_STOP, ['block_index' => 0]);
    $reply = ($rig->call)('render_card', 'toolu_9');
    // The bridge echoes the CLI's tool_result with the image described (ai-bridge describePart).
    $stream(MessageTypes::TOOL_RESULT, ['tool_call_id' => 'toolu_9', 'result' => 'Floor 1.[image: image/png, 0 KB]']);
    $stream(MessageTypes::DONE, ['usage' => null]);

    expect($reply['content'][1]['data'])->toBe(TINY_PNG);

    $kept = app(StreamStoreContract::class)->getValue('req-c', StreamHandler::toolViewKey('toolu_9'));
    expect($kept['result'])->toBe([
        'content' => [['type' => 'text', 'text' => 'Floor 1.'], ['type' => 'text', 'text' => '[image: image/png, 0 KB]']],
        'structuredContent' => ['floor' => 1],
    ]);

    $everything = json_encode([
        app(StreamStoreContract::class)->range('req-c'),
        $rig->conversation->messages()->get()->toArray(),
    ], JSON_UNESCAPED_SLASHES);
    expect($everything)->not->toContain(TINY_PNG)
        ->and($everything)->toContain('[image: image/png, 0 KB]');
});

it('refuses parts that are not text or a readable image', function (Closure $make, string $message) {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'svg' => [fn () => ToolResult::imagePart('image/svg+xml', TINY_PNG), 'An image part is one of'],
    'not base64' => [fn () => ToolResult::imagePart('image/png', 'not base64!'), 'base64-encoded'],
    'no parts' => [fn () => ToolResult::withContent([]), 'at least one part'],
    'unknown part' => [fn () => ToolResult::withContent([['type' => 'audio']]), 'use ToolResult::textPart()'],
    'too many' => [fn () => ToolResult::withContent(array_fill(0, 33, ToolResult::textPart('x'))), 'at most 32'],
]);

it('encodes as its text, never the image bytes', function () {
    $result = ToolResult::withContent([ToolResult::textPart('a'), ToolResult::imagePart('image/png', TINY_PNG)]);

    expect(json_encode($result))->toBe(json_encode("a\n[image: image/png, 0 KB]"))
        ->and($result->hasImages())->toBeTrue()
        ->and(ToolResult::withView('t', [])->content())->toBeNull();
});
