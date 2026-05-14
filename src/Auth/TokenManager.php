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
 */
class TokenManager
{
    private string $secret;

    private int $ttl;

    public function __construct()
    {
        $this->secret = config('ai-bridge.token.secret', '');
        $this->ttl = (int) config('ai-bridge.token.ttl', 86400);
    }

    /**
     * Generate a JWT connection token for the given user.
     *
     * @param  int|string  $userId  The authenticated user's ID.
     * @param  array<string, mixed>  $claims  Additional claims to embed in the token.
     * @return string  The encoded JWT.
     *
     * @throws InvalidArgumentException If the token secret is not configured.
     */
    public function generate(int|string $userId, array $claims = []): string
    {
        $this->ensureSecretConfigured();

        $now = time();

        $payload = array_merge($claims, [
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'jti' => Str::uuid()->toString(),
            'iss' => 'ai-bridge',
        ]);

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Validate a JWT connection token and return its decoded claims.
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
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
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
     * Get the configured TTL in seconds.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Ensure the JWT secret is configured.
     *
     * @throws InvalidArgumentException
     */
    private function ensureSecretConfigured(): void
    {
        if (empty($this->secret)) {
            throw new InvalidArgumentException(
                'AI Bridge token secret is not configured. Set AI_BRIDGE_TOKEN_SECRET in your .env file.'
            );
        }
    }
}
