<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates and validates JWT connection tokens for bridge authentication.
 *
 * Tokens are short-lived JWTs that a bridge client presents during WebSocket
 * upgrade to prove it is authorized to act on behalf of a specific user.
 *
 * Config values are read lazily to avoid issues with early instantiation
 * before the application config is fully loaded.
 */
class TokenManager
{
    /**
     * Scope value that identifies internal relay tokens (PHP-FPM → bridge server).
     */
    public const INTERNAL_RELAY_SCOPE = 'internal_relay';
    /**
     * Generate a JWT connection token for the given user.
     *
     * NOTE: $claims cannot override reserved keys (sub, iat, exp, jti, iss, aud).
     * These are always set by the token manager to ensure security invariants.
     * An InvalidArgumentException is thrown if reserved keys are passed.
     *
     * @param  int|string  $userId  The authenticated user's ID.
     * @param  array<string, mixed>  $claims  Additional claims to embed in the token.
     * @param  int|null  $ttl  TTL in seconds. Defaults to the configured TTL if null.
     * @return string  The encoded JWT.
     *
     * @throws InvalidArgumentException If the token secret is not configured or reserved claims are passed.
     */
    public function generate(int|string $userId, array $claims = [], ?int $ttl = null): string
    {
        $this->ensureSecretConfigured();

        $reservedKeys = ['sub', 'iat', 'exp', 'jti', 'iss', 'aud'];
        $conflicts = array_intersect($reservedKeys, array_keys($claims));
        if (! empty($conflicts)) {
            throw new InvalidArgumentException(
                'Cannot override reserved JWT claims: ' . implode(', ', $conflicts) . '. These are managed internally.'
            );
        }

        $resolvedTtl = $ttl ?? $this->getTtl();

        // Guard against zero or negative TTL, which would produce immediately-expired
        // tokens with no error.
        if ($resolvedTtl <= 0) {
            throw new InvalidArgumentException(
                'Token TTL must be a positive integer in seconds. Got: '.$resolvedTtl.'. '
                .'Check that AI_BRIDGE_TOKEN_TTL is set to a positive integer in your .env file (default: 86400).'
            );
        }

        $now = time();

        $payload = array_merge($claims, [
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + $resolvedTtl,
            'jti' => Str::uuid()->toString(),
            'iss' => 'ai-bridge',
            // Bind the token to this application instance so a token issued by
            // one deployment is not accepted by another sharing the same secret.
            'aud' => $this->getAudience(),
        ]);

        return JWT::encode($payload, $this->getSecret(), 'HS256');
    }

    /**
     * Validate a JWT connection token and return its decoded claims.
     *
     * NOTE: Individual token revocation is not currently supported. JWTs are
     * stateless and valid until expiry. To revoke all tokens immediately,
     * rotate the AI_BRIDGE_TOKEN_SECRET value. This invalidates every
     * outstanding token but requires all bridge clients to reconnect with
     * a new token.
     *
     * @param  string  $token  The JWT to validate.
     * @param  string|null  $expectedScope  When non-null, the token's 'scope' claim must
     *                                      match this value exactly. Pass 'internal_relay'
     *                                      to restrict to relay-only tokens, or null to
     *                                      accept any scope (user-facing bridge tokens have
     *                                      no scope claim).
     * @return object  The decoded token payload.
     *
     * @throws InvalidArgumentException If the token secret is not configured.
     * @throws TokenValidationException If the token is invalid, expired, or has the wrong scope.
     */
    public function validate(string $token, ?string $expectedScope = null): object
    {
        $this->ensureSecretConfigured();

        try {
            $decoded = JWT::decode($token, new Key($this->getSecret(), 'HS256'));
        } catch (ExpiredException $e) {
            throw new TokenValidationException('Token has expired.', 'token_expired', $e);
        } catch (SignatureInvalidException $e) {
            throw new TokenValidationException('Token signature is invalid.', 'token_invalid_signature', $e);
        } catch (\Exception $e) {
            throw new TokenValidationException('Token validation failed: '.$e->getMessage(), 'token_invalid', $e);
        }

        // Ensure required claims are present
        if (! isset($decoded->sub)) {
            throw new TokenValidationException('Token is missing the "sub" claim.', 'token_missing_subject');
        }

        if (! isset($decoded->iss) || $decoded->iss !== 'ai-bridge') {
            throw new TokenValidationException('Token has an invalid issuer.', 'token_invalid_issuer');
        }

        // Enforce the audience claim so a token issued by one application instance
        // cannot authenticate against another instance sharing the same secret.
        $expectedAudience = $this->getAudience();
        if (! isset($decoded->aud) || $decoded->aud !== $expectedAudience) {
            throw new TokenValidationException('Token has an invalid audience.', 'token_invalid_audience');
        }

        // Enforce scope when requested. Internal relay tokens have
        // scope='internal_relay'; user-facing bridge tokens have no scope claim.
        // This prevents cross-class token use.
        if ($expectedScope !== null) {
            $actualScope = $decoded->scope ?? null;
            if ($actualScope !== $expectedScope) {
                throw new TokenValidationException(
                    "Token scope mismatch: expected '{$expectedScope}', got '".($actualScope ?? 'none')."'.",
                    'token_wrong_scope'
                );
            }
        } else {
            // When no specific scope is required (user-facing auth), reject internal tokens
            // to prevent relay tokens from authenticating bridge WebSocket connections.
            $actualScope = $decoded->scope ?? null;
            if ($actualScope === self::INTERNAL_RELAY_SCOPE) {
                throw new TokenValidationException(
                    "Internal relay tokens may not be used for user-facing bridge authentication.",
                    'token_wrong_scope'
                );
            }
        }

        return $decoded;
    }

    /**
     * Extract the user ID from a validated token.
     *
     * @param  string  $token  The JWT to extract the user ID from.
     * @return string  The user ID (sub claim).
     */
    public function getUserId(string $token): string
    {
        $decoded = $this->validate($token);

        return (string) $decoded->sub;
    }

    /**
     * Check if a token is valid without throwing exceptions.
     *
     * This method guarantees it never throws — it returns false for every failure,
     * including configuration errors (missing/short secret), so callers can do
     * defensive checks without wrapping in try/catch.
     *
     * @param  string  $token  The JWT to check.
     * @return bool  True if the token is valid, false otherwise. Never throws.
     */
    public function isValid(string $token): bool
    {
        try {
            $this->validate($token);

            return true;
        } catch (TokenValidationException) {
            return false;
        } catch (InvalidArgumentException $e) {
            // Secret not configured or too short — treat as invalid rather than crashing.
            Log::warning('AI Bridge: isValid() called but token secret is not properly configured', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the configured TTL in seconds (read lazily from config).
     */
    public function getTtl(): int
    {
        return (int) config('ai-bridge.token.ttl', 86400);
    }

    /**
     * Get the configured JWT secret (read lazily from config).
     */
    private function getSecret(): string
    {
        return config('ai-bridge.token.secret', '');
    }

    /**
     * Get the audience that identifies this application instance.
     *
     * Defaults to config('app.url') so tokens are scoped to a single deployment.
     * Can be overridden via ai-bridge.token.audience.
     */
    private function getAudience(): string
    {
        return (string) (config('ai-bridge.token.audience') ?: config('app.url', 'ai-bridge'));
    }

    /**
     * Ensure the JWT secret is configured and meets minimum length requirements.
     *
     * The secret must be at least 32 bytes (256 bits) for HS256 security.
     * Generate with: openssl rand -hex 32
     *
     * @throws InvalidArgumentException
     */
    private function ensureSecretConfigured(): void
    {
        $secret = $this->getSecret();

        if (empty($secret)) {
            throw new InvalidArgumentException(
                'AI Bridge token secret is not configured. Set AI_BRIDGE_TOKEN_SECRET in your .env file. Generate with: openssl rand -hex 32'
            );
        }

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException(
                'AI Bridge token secret must be at least 32 bytes for HS256 security. Current length: ' . strlen($secret) . '. Generate with: openssl rand -hex 32'
            );
        }
    }
}
