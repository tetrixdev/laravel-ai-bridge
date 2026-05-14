<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * HTTP endpoints for AI Bridge operations.
 *
 * Provides token generation for bridge connections and status checking.
 */
class BridgeController extends Controller
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly BridgeConnectionManager $connectionManager,
    ) {}

    /**
     * Generate a JWT connection token for the authenticated user.
     *
     * POST /ai-bridge/token
     *
     * Response:
     *   {
     *     "token": "<JWT>",
     *     "expires_in": 86400,
     *     "websocket_url": "..."
     *   }
     */
    public function generateToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'You must be authenticated to generate a bridge token.',
            ], 401);
        }

        $token = $this->tokenManager->generate($user->getAuthIdentifier(), [
            'name' => $user->name ?? null,
        ]);

        return response()->json([
            'token' => $token,
            'expires_in' => $this->tokenManager->getTtl(),
        ]);
    }

    /**
     * Check the bridge connection status for the authenticated user.
     *
     * GET /ai-bridge/status
     *
     * Response:
     *   {
     *     "mode": "bridge",
     *     "bridge_connected": true,
     *     "connection_id": "...",
     *     "connected_at": 1234567890
     *   }
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'You must be authenticated to check bridge status.',
            ], 401);
        }

        $userId = $user->getAuthIdentifier();
        $mode = ProviderMode::from(config('ai-bridge.mode', 'byok'));
        $connected = $this->connectionManager->hasConnection($userId);
        $connectionData = $this->connectionManager->getConnection($userId);

        $response = [
            'mode' => $mode->value,
            'bridge_connected' => $connected,
        ];

        if ($connected && $connectionData) {
            $response['connection_id'] = $connectionData['connection_id'];
            $response['connected_at'] = $connectionData['connected_at'];
        }

        return response()->json($response);
    }
}
