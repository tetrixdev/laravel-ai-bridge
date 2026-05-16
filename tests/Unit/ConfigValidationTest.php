<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuration Validation Tests
|--------------------------------------------------------------------------
|
| These tests verify that configuration values are validated correctly
| and that appropriate warnings/errors are produced for invalid configs.
|
*/


test('ProviderMode enum rejects invalid mode string', function () {
    $result = ProviderMode::tryFrom('invalid_mode');

    expect($result)->toBeNull();
});

test('ProviderMode enum accepts valid bridge mode', function () {
    expect(ProviderMode::from('bridge'))->toBe(ProviderMode::Bridge);
});

test('ProviderMode enum accepts valid byok mode', function () {
    expect(ProviderMode::from('byok'))->toBe(ProviderMode::Byok);
});

test('ProviderMode enum accepts valid managed mode', function () {
    expect(ProviderMode::from('managed'))->toBe(ProviderMode::Managed);
});

test('ProviderMode::from() throws for invalid mode', function () {
    ProviderMode::from('invalid');
})->throws(ValueError::class);

test('ProviderMode::usesChatCompletions() returns correct values', function () {
    expect(ProviderMode::Bridge->usesChatCompletions())->toBeFalse();
    expect(ProviderMode::Byok->usesChatCompletions())->toBeTrue();
    expect(ProviderMode::Managed->usesChatCompletions())->toBeTrue();
});

test('ProviderMode::requiresBridge() returns correct values', function () {
    expect(ProviderMode::Bridge->requiresBridge())->toBeTrue();
    expect(ProviderMode::Byok->requiresBridge())->toBeFalse();
    expect(ProviderMode::Managed->requiresBridge())->toBeFalse();
});

test('empty route_middleware triggers warning log', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message) {
            return str_contains($message, 'route_middleware is empty');
        });

    // Set empty middleware before booting the provider
    config(['ai-bridge.route_middleware' => []]);

    // Re-boot the service provider to trigger the warning
    $provider = new \Tetrix\AiBridge\AiBridgeServiceProvider(app());
    $provider->boot();
});

test('non-empty route_middleware does not trigger warning', function () {
    Log::shouldReceive('warning')
        ->withArgs(function (string $message) {
            return str_contains($message, 'route_middleware is empty');
        })
        ->never();

    config(['ai-bridge.route_middleware' => ['auth']]);

    $provider = new \Tetrix\AiBridge\AiBridgeServiceProvider(app());
    $provider->boot();
});

test('default config has sensible defaults', function () {
    expect(config('ai-bridge.mode'))->toBe('byok');
    // NOTE (BL-012): The test environment overrides token.ttl to 3600 in TestCase.php.
    // The package default is 86400 (24h). This test verifies the test-env config, not
    // the package default. The assertion reflects the TestCase override intentionally.
    expect(config('ai-bridge.token.ttl'))->toBe(3600); // TestCase override, not the package default (86400)
    expect(config('ai-bridge.route_middleware'))->toBe(['auth']);
});

test('package config file specifies 86400 as the TTL env default', function () {
    // Read the raw config file source to confirm the documented default is 86400.
    // We cannot call env() here without the application, but we can grep the source.
    $configSource = file_get_contents(__DIR__ . '/../../config/ai-bridge.php');
    expect($configSource)->toContain("AI_BRIDGE_TOKEN_TTL', 86400");
});

test('broadcasting config defaults', function () {
    expect(config('ai-bridge.broadcasting.enabled'))->toBeTrue();
    expect(config('ai-bridge.broadcasting.connection'))->toBe('reverb');
});

test('websocket config defaults', function () {
    expect(config('ai-bridge.websocket.heartbeat_interval'))->toBe(30);
    expect(config('ai-bridge.websocket.request_timeout'))->toBe(300);
});

test('chat_completions config defaults', function () {
    expect(config('ai-bridge.chat_completions.max_tokens'))->toBe(4096);
    expect(config('ai-bridge.chat_completions.stream_timeout'))->toBe(300);
});
