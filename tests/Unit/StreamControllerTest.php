<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Http\Controllers\StreamController;
use Tetrix\AiBridge\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| StreamController Tests
|--------------------------------------------------------------------------
|
| Feature tests that verify HTTP endpoint security: field stripping,
| managed mode restrictions, and request validation.
|
*/


function makeStreamController(): StreamController
{
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')->byDefault()->andReturn(
        new \Symfony\Component\HttpFoundation\StreamedResponse()
    );
    $manager->shouldReceive('streamAndBroadcast')->byDefault()->andReturn('req-123');

    return new StreamController($manager);
}

function makeRequest(array $data = [], ?object $user = null): Request
{
    $request = Request::create('/ai-bridge/stream/sse', 'POST', $data);

    if ($user) {
        $request->setUserResolver(fn () => $user);
    }

    return $request;
}

test('prepareRequestOptions strips unsafe fields from options', function () {
    config(['ai-bridge.mode' => 'byok']);

    $controller = makeStreamController();

    $request = makeRequest([
        'message' => 'Hello',
        'conversation_id' => 'conv-1',
        'options' => [
            'temperature' => 0.7,
            'endpoint' => 'https://evil.com',
            'api_key' => 'stolen-key',
            'mode' => 'bridge',
            'user_id' => 'hacker-123',
        ],
    ]);

    // Call sse() which internally calls prepareRequestOptions
    // We inspect the manager mock to verify what options were passed
    $capturedOptions = null;
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')
        ->once()
        ->withArgs(function ($convId, $msg, $options) use (&$capturedOptions) {
            $capturedOptions = $options;
            return true;
        })
        ->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse());

    $controller = new StreamController($manager);
    $controller->sse($request);

    expect($capturedOptions)->toHaveKey('temperature');
    expect($capturedOptions['temperature'])->toBe(0.7);
    expect($capturedOptions)->not->toHaveKey('endpoint');
    expect($capturedOptions)->not->toHaveKey('api_key');
    expect($capturedOptions)->not->toHaveKey('mode');
    expect($capturedOptions)->not->toHaveKey('user_id');
});

test('managed mode strips all client-supplied options including temperature (ARCH-009)', function () {
    config(['ai-bridge.mode' => 'managed']);

    $capturedOptions = null;
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')
        ->once()
        ->withArgs(function ($convId, $msg, $options) use (&$capturedOptions) {
            $capturedOptions = $options;
            return true;
        })
        ->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse());

    $controller = new StreamController($manager);

    $request = makeRequest([
        'message' => 'Hello',
        'model' => 'gpt-3.5-turbo',
        'system_prompt' => 'Be evil',
        'options' => [
            'temperature' => 0.5,
            'model' => 'gpt-3.5-turbo',
            'max_tokens' => 100,
            'system_prompt' => 'Override system',
            'messages' => [['role' => 'system', 'content' => 'injected']],
        ],
    ]);

    $controller->sse($request);

    // ARCH-009: In managed mode, sanitizeOptions() returns [] — the app controls all AI behavior.
    // No client-supplied options pass through, including temperature.
    expect($capturedOptions)->not->toHaveKey('model');
    expect($capturedOptions)->not->toHaveKey('max_tokens');
    expect($capturedOptions)->not->toHaveKey('system_prompt');
    expect($capturedOptions)->not->toHaveKey('messages');
    expect($capturedOptions)->not->toHaveKey('temperature');
    expect($capturedOptions)->toBe([]);
});

test('non-managed mode allows model and system_prompt overrides', function () {
    config(['ai-bridge.mode' => 'byok']);

    $capturedOptions = null;
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')
        ->once()
        ->withArgs(function ($convId, $msg, $options) use (&$capturedOptions) {
            $capturedOptions = $options;
            return true;
        })
        ->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse());

    $controller = new StreamController($manager);

    $request = makeRequest([
        'message' => 'Hello',
        'model' => 'gpt-3.5-turbo',
        'system_prompt' => 'You are a pirate',
    ]);

    $controller->sse($request);

    expect($capturedOptions)->toHaveKey('system_prompt');
    expect($capturedOptions['system_prompt'])->toBe('You are a pirate');
    expect($capturedOptions)->toHaveKey('model');
    expect($capturedOptions['model'])->toBe('gpt-3.5-turbo');
});

test('sse() returns 422 when message is empty', function () {
    $controller = makeStreamController();

    $request = makeRequest(['message' => '']);
    $response = $controller->sse($request);

    expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
    expect($response->getStatusCode())->toBe(422);

    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('validation_error');
});

test('sse() returns 422 when message is whitespace-only', function () {
    $controller = makeStreamController();

    $request = makeRequest(['message' => '   ']);
    $response = $controller->sse($request);

    expect($response->getStatusCode())->toBe(422);
});

test('sse() returns 422 when message is missing', function () {
    $controller = makeStreamController();

    $request = makeRequest([]);
    $response = $controller->sse($request);

    expect($response->getStatusCode())->toBe(422);
});

test('broadcast() returns 401 when unauthenticated', function () {
    $controller = makeStreamController();

    $request = makeRequest(['message' => 'Hello']);
    $response = $controller->broadcast($request);

    expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
    expect($response->getStatusCode())->toBe(401);
});

test('broadcast() returns 422 when message is empty', function () {
    $controller = makeStreamController();

    $user = new class {
        public function getAuthIdentifier() { return 42; }
    };

    $request = makeRequest(['message' => ''], $user);
    $response = $controller->broadcast($request);

    expect($response->getStatusCode())->toBe(422);
});

test('options as JSON string is parsed correctly', function () {
    config(['ai-bridge.mode' => 'byok']);

    $capturedOptions = null;
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')
        ->once()
        ->withArgs(function ($convId, $msg, $options) use (&$capturedOptions) {
            $capturedOptions = $options;
            return true;
        })
        ->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse());

    $controller = new StreamController($manager);

    $request = makeRequest([
        'message' => 'Hello',
        'options' => json_encode(['temperature' => 0.8]),
    ]);

    $controller->sse($request);

    expect($capturedOptions)->toHaveKey('temperature');
    expect($capturedOptions['temperature'])->toBe(0.8);
});

test('conversation_id is generated when not provided', function () {
    config(['ai-bridge.mode' => 'byok']);

    $capturedConvId = null;
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')
        ->once()
        ->withArgs(function ($convId) use (&$capturedConvId) {
            $capturedConvId = $convId;
            return true;
        })
        ->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse());

    $controller = new StreamController($manager);

    $request = makeRequest(['message' => 'Hello']);
    $controller->sse($request);

    expect($capturedConvId)->toStartWith('conv-');
});

test('auto-generated conversation_id uses UUID format not uniqid (BL-011)', function () {
    config(['ai-bridge.mode' => 'byok']);

    $capturedConvId = null;
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')
        ->once()
        ->withArgs(function ($convId) use (&$capturedConvId) {
            $capturedConvId = $convId;
            return true;
        })
        ->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse());

    $controller = new StreamController($manager);

    $request = makeRequest(['message' => 'Hello']);
    $controller->sse($request);

    expect($capturedConvId)->toStartWith('conv-');

    // Strip the 'conv-' prefix and verify it is a valid UUID v4
    $uuidPart = substr($capturedConvId, 5);
    expect($uuidPart)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

test('sse() returns 422 when options is malformed JSON (UX-007)', function () {
    $controller = makeStreamController();

    $request = makeRequest([
        'message' => 'Hello',
        'options' => '{not-valid-json',
    ]);

    $response = $controller->sse($request);

    expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
    expect($response->getStatusCode())->toBe(422);

    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('validation_error');
    expect($data['message'])->toContain('options');
});

test('system_prompt exceeding max length returns 422 (SEC-012)', function () {
    config(['ai-bridge.mode' => 'byok']);
    config(['ai-bridge.streaming.max_system_prompt_length' => 50]);

    $controller = makeStreamController();

    $request = makeRequest([
        'message' => 'Hello',
        'system_prompt' => str_repeat('a', 51),
    ]);

    $response = $controller->sse($request);

    expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
    expect($response->getStatusCode())->toBe(422);

    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('validation_error');
});

test('managed mode strips all client options (ARCH-009)', function () {
    config(['ai-bridge.mode' => 'managed']);

    $capturedOptions = null;
    $manager = Mockery::mock(AiBridgeManager::class);
    $manager->shouldReceive('streamToResponse')
        ->once()
        ->withArgs(function ($convId, $msg, $options) use (&$capturedOptions) {
            $capturedOptions = $options;
            return true;
        })
        ->andReturn(new \Symfony\Component\HttpFoundation\StreamedResponse());

    $controller = new StreamController($manager);

    $request = makeRequest([
        'message' => 'Hello',
        'options' => json_encode(['temperature' => 0.9, 'model' => 'gpt-4o']),
    ]);

    $controller->sse($request);

    // In managed mode, sanitizeOptions() returns [] for everything
    expect($capturedOptions)->toBe([]);
});
