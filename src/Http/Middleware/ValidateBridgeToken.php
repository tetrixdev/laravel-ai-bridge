<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Auth\TokenValidationException;

/**
 * Middleware to validate bridge connection tokens.
 *
 * Used on WebSocket upgrade routes to ensure the connecting bridge
 * has a valid JWT token. The token can be provided via:
 * - Authorization: Bearer <token> header
 * - ?token=<token> query parameter
 *
 * On success, the validated user ID is set as a request attribute.
 */
class ValidateBridgeToken
{
    public function __construct(
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (empty($token)) {
            return response()->json([
                'error' => 'missing_token',
                'message' => 'A bridge connection token is required.',
            ], 401);
        }

        try {
            $decoded = $this->tokenManager->validate($token);
        } catch (TokenValidationException $e) {
            // Return a generic error code to limit oracle information; the
            // detailed error code is preserved in server-side logs.
            \Illuminate\Support\Facades\Log::info('AI Bridge: token validation failed', [
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'token_invalid',
                'message' => 'Bridge token validation failed.',
            ], 401);
        }

        // Set the validated user ID on the request for downstream use
        $request->attributes->set('bridge_user_id', (string) $decoded->sub);
        $request->attributes->set('bridge_token_claims', $decoded);

        return $next($request);
    }

    /**
     * Extract the token from the request.
     *
     * Checks Authorization header first, then falls back to query parameter.
     */
    private function extractToken(Request $request): ?string
    {
        // Check Authorization: Bearer <token>
        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Check query parameter
        return $request->query('token');
    }
}
