<?php

declare(strict_types=1);

use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Tools\ToolRegistry;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/*
| A `posture` frame captured from the real @tetrixdev/ai-bridge binary, byte
| for byte, rather than hand-written here. Hand-written fixtures agree with
| whoever wrote them; this one agrees with the bridge.
*/
it('accepts a posture frame produced by the real bridge', function () {
    $raw = file_get_contents(__DIR__.'/fixtures/posture-from-bridge.json');

    $manager = new BridgeConnectionManager();
    $handler = new MessageHandler(
        connectionManager: $manager,
        tokenManager: app(TokenManager::class),
        toolRegistry: new ToolRegistry(),
    );

    $handler->handleMessage('conn-1', null, json_encode([
        'type' => MessageTypes::HELLO,
        'version' => '0.1',
        'token' => app(TokenManager::class)->generate('user-1'),
        'providers' => [],
    ]));

    $handler->handleMessage('conn-1', null, $raw);

    $posture = $manager->getPosture('user-1');

    expect($posture['cli_isolation'])->toBe('isolated')
        ->and($posture['requested'])->toBe('native')
        ->and($posture['reason'])->toBe('requires_allow_native')
        ->and($posture['message'])->toContain('--allow-native');
});
