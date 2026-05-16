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
    // BL-008: generate() now guards against non-positive TTL, so we must forge
    // an already-expired token directly using the Firebase JWT library.
    $secret = config('ai-bridge.token.secret');
    $now = time();
    $payload = [
        'sub' => '1',
        'iat' => $now - 10,
        'exp' => $now - 5, // expired 5 seconds ago
        'jti' => 'test-jti',
        'iss' => 'ai-bridge',
    ];
    $token = \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');

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
    // Forge an expired token directly (see generate() BL-008 guard note above)
    $secret = config('ai-bridge.token.secret');
    $now = time();
    $payload = [
        'sub' => '1',
        'iat' => $now - 10,
        'exp' => $now - 5,
        'jti' => 'test-jti',
        'iss' => 'ai-bridge',
    ];
    $token = \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');

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

// --- SEC-002: Scope validation ---

test('validate() rejects internal_relay token when no expectedScope provided (SEC-002)', function () {
    // An internal_relay token must not authenticate a user bridge connection
    $token = $this->tokenManager->generate(1, ['scope' => 'internal_relay']);

    try {
        $this->tokenManager->validate($token);
        $this->fail('Expected TokenValidationException');
    } catch (\Tetrix\AiBridge\Auth\TokenValidationException $e) {
        expect($e->errorCode)->toBe('token_wrong_scope');
    }
});

test('validate() accepts token with internal_relay scope when expectedScope matches (SEC-002)', function () {
    $token = $this->tokenManager->generate(1, ['scope' => 'internal_relay']);

    $decoded = $this->tokenManager->validate($token, 'internal_relay');

    expect($decoded->sub)->toBe('1');
    expect($decoded->scope)->toBe('internal_relay');
});

test('validate() rejects user token when internal_relay scope required (SEC-002)', function () {
    // A regular user token (no scope claim) must not be accepted as an internal relay token
    $token = $this->tokenManager->generate(1);

    try {
        $this->tokenManager->validate($token, 'internal_relay');
        $this->fail('Expected TokenValidationException');
    } catch (\Tetrix\AiBridge\Auth\TokenValidationException $e) {
        expect($e->errorCode)->toBe('token_wrong_scope');
    }
});

test('validate() accepts scopeless user token when no expectedScope provided (SEC-002)', function () {
    // Normal user-facing bridge tokens have no scope claim — this should pass
    $token = $this->tokenManager->generate(42);

    $decoded = $this->tokenManager->validate($token);

    expect($decoded->sub)->toBe('42');
    expect(isset($decoded->scope))->toBeFalse();
});

// --- BL-008: Zero TTL guard ---

test('generate() throws InvalidArgumentException for zero TTL (BL-008)', function () {
    $this->tokenManager->generate(1, [], 0);
})->throws(InvalidArgumentException::class, 'Token TTL must be a positive integer');

test('generate() throws InvalidArgumentException for negative TTL (BL-008)', function () {
    $this->tokenManager->generate(1, [], -100);
})->throws(InvalidArgumentException::class, 'Token TTL must be a positive integer');

test('generate() throws when config TTL is zero (BL-008)', function () {
    config(['ai-bridge.token.ttl' => 0]);

    $this->tokenManager->generate(1);
})->throws(InvalidArgumentException::class, 'Token TTL must be a positive integer');

// --- CONS-001/BL-005: isValid() never-throws contract ---

test('isValid() returns false when token secret is empty — never throws (CONS-001/BL-005)', function () {
    config(['ai-bridge.token.secret' => '']);

    // Must return false, not throw InvalidArgumentException
    $result = $this->tokenManager->isValid('any-token');

    expect($result)->toBeFalse();
});

test('isValid() returns false when token secret is too short — never throws (CONS-001/BL-005)', function () {
    config(['ai-bridge.token.secret' => 'short-secret']);

    // Must return false, not throw InvalidArgumentException (secret < 32 bytes)
    $result = $this->tokenManager->isValid('any-token');

    expect($result)->toBeFalse();
});

test('isValid() returns false for malformed token without throwing (CONS-001/BL-005)', function () {
    // Malformed string — not a valid JWT at all
    $result = $this->tokenManager->isValid('not.a.valid.jwt.at.all');

    expect($result)->toBeFalse();
});
