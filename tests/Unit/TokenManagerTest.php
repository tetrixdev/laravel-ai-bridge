<?php

declare(strict_types=1);

use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Auth\TokenValidationException;
use Tetrix\AiBridge\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| TokenManager Unit Tests
|--------------------------------------------------------------------------
|
| These tests verify JWT generation, validation, and security constraints.
|
*/

uses(TestCase::class);

beforeEach(function () {
    $this->tokenManager = app(TokenManager::class);
});

test('generate() creates a valid JWT string', function () {
    $token = $this->tokenManager->generate(42);

    expect($token)->toBeString();

    // JWT format: three base64url-encoded parts separated by dots
    $parts = explode('.', $token);
    expect($parts)->toHaveCount(3);
});

test('validate() decodes a valid token correctly', function () {
    $token = $this->tokenManager->generate(123, ['role' => 'admin']);

    $decoded = $this->tokenManager->validate($token);

    expect($decoded->sub)->toBe('123');
    expect($decoded->iss)->toBe('ai-bridge');
    expect($decoded->role)->toBe('admin');
    expect($decoded->iat)->toBeInt();
    expect($decoded->exp)->toBeInt();
    expect($decoded->jti)->toBeString();
});

test('validate() rejects expired tokens', function () {
    // Generate a token with -1 second TTL (already expired)
    $token = $this->tokenManager->generate(1, [], -1);

    $this->tokenManager->validate($token);
})->throws(TokenValidationException::class, 'Token has expired.');

test('validate() rejects tokens with wrong secret', function () {
    // Generate token with current secret
    $token = $this->tokenManager->generate(1);

    // Change the secret
    config(['ai-bridge.token.secret' => str_repeat('b', 64)]);

    $this->tokenManager->validate($token);
})->throws(TokenValidationException::class);

test('isValid() returns true for a valid token', function () {
    $token = $this->tokenManager->generate(42);

    expect($this->tokenManager->isValid($token))->toBeTrue();
});

test('isValid() returns false for an invalid token without throwing', function () {
    expect($this->tokenManager->isValid('not-a-real-token'))->toBeFalse();
});

test('isValid() returns false for an expired token without throwing', function () {
    $token = $this->tokenManager->generate(1, [], -1);

    expect($this->tokenManager->isValid($token))->toBeFalse();
});

test('generate() with reserved claims throws InvalidArgumentException', function () {
    $this->tokenManager->generate(1, ['sub' => 'hack']);
})->throws(InvalidArgumentException::class, 'Cannot override reserved JWT claims: sub');

test('generate() with multiple reserved claims lists all conflicts', function () {
    $this->tokenManager->generate(1, ['iat' => 0, 'exp' => 99999]);
})->throws(InvalidArgumentException::class, 'Cannot override reserved JWT claims: iat, exp');

test('ensureSecretConfigured() throws when secret is empty', function () {
    config(['ai-bridge.token.secret' => '']);

    $this->tokenManager->generate(1);
})->throws(InvalidArgumentException::class, 'AI Bridge token secret is not configured');

test('ensureSecretConfigured() throws when secret is too short', function () {
    config(['ai-bridge.token.secret' => 'short']);

    $this->tokenManager->generate(1);
})->throws(InvalidArgumentException::class, 'AI Bridge token secret must be at least 32 bytes');

test('getTtl() returns configured TTL', function () {
    config(['ai-bridge.token.ttl' => 7200]);

    expect($this->tokenManager->getTtl())->toBe(7200);
});

test('getTtl() returns 0 when config is explicitly null (cast to int)', function () {
    // When config is set to null, (int) null = 0. The default 86400 in config()
    // only applies when the key is completely absent from the config repository.
    config(['ai-bridge.token.ttl' => null]);

    expect($this->tokenManager->getTtl())->toBe(0);
});

test('generate() allows custom TTL override', function () {
    $token = $this->tokenManager->generate(1, [], 60);
    $decoded = $this->tokenManager->validate($token);

    // exp should be iat + 60
    expect($decoded->exp - $decoded->iat)->toBe(60);
});

test('generate() uses configured TTL when no override provided', function () {
    config(['ai-bridge.token.ttl' => 1800]);

    $token = $this->tokenManager->generate(1);
    $decoded = $this->tokenManager->validate($token);

    expect($decoded->exp - $decoded->iat)->toBe(1800);
});

test('getUserId() extracts user ID from valid token', function () {
    $token = $this->tokenManager->generate('user-abc');

    expect($this->tokenManager->getUserId($token))->toBe('user-abc');
});
