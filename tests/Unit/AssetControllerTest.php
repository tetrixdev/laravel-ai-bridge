<?php

declare(strict_types=1);

use Tetrix\AiBridge\Http\Controllers\AssetController;

it('serves the allowlisted chat component asset', function () {
    $response = (new AssetController())->show('ai-bridge-chat.js');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('javascript');
});

it('serves the vendored pusher and echo assets', function () {
    expect((new AssetController())->show('pusher.min.js')->getStatusCode())->toBe(200)
        ->and((new AssetController())->show('echo.iife.js')->getStatusCode())->toBe(200);
});

it('rejects a non-allowlisted file', function () {
    (new AssetController())->show('../config/ai-bridge.php');
})->throws(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
