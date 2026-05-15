<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
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
     * Generate a JWT connection token for the given user.
     *
     * NOTE: $claims cannot override reserved keys (sub, iat, exp, jti, iss).
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

        $reservedKeys = ['sub', 'iat', 'exp', 'jti', 'iss'];
        $conflicts = array_intersect($reservedKeys, array_keys($claims));
        if (! empty($conflicts)) {
            throw new InvalidArgumentException(
                'Cannot override reserved JWT claims: ' . implode(', ', $conflicts) . '. These are managed internally.'
            );
        }

        $now = time();

        $payload = array_merge($claims, [
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + ($ttl ?? $this->getTtl()),
            'jti' => Str::uuid()->toString(),
            'iss' => 'ai-bridge',
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
     * @return object  The decoded token payload.
     *
     * @throws InvalidArgumentException If the token secret is not configured.
     * @throws TokenValidationException If the token is invalid or expired.
     */
    public function validate(string $token): object
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
     * @param  string  $token  The JWT to check.
     * @return bool  True if the token is valid, false otherwise.
     */
    public function isValid(string $token): bool
    {
        try {
            $this->validate($token);

            return true;
        } catch (TokenValidationException) {
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
