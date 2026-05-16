<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
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

        // Do not include PII (name) in the JWT — it ends up in logs.
        $token = $this->tokenManager->generate($user->getAuthIdentifier());

        $host = config('ai-bridge.server.host', '127.0.0.1');
        $port = (int) config('ai-bridge.server.port', 8085);

        // Normalize the 0.0.0.0 bind-all address to 127.0.0.1 — browsers cannot
        // connect to 0.0.0.0.
        if ($host === '0.0.0.0') {
            $host = '127.0.0.1';
        }

        // Operators can override the public WebSocket URL for TLS deployments
        // (e.g. wss://...); otherwise the internal ws:// bind address is used.
        $configuredPublicUrl = config('ai-bridge.server.public_url');

        // Warn when returning a plaintext ws:// URL for a non-loopback host —
        // browsers block ws:// connections on HTTPS pages (mixed-content policy).
        if (empty($configuredPublicUrl) && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            Log::warning('AI Bridge: token endpoint returning a plaintext ws:// WebSocket URL for a non-loopback host. Set AI_BRIDGE_PUBLIC_URL=wss://... for production TLS deployments to avoid browser mixed-content failures.', [
                'host' => $host,
            ]);
        }

        $websocketUrl = ! empty($configuredPublicUrl)
            ? rtrim($configuredPublicUrl, '/')
            : "ws://{$host}:{$port}";

        return response()->json([
            'token' => $token,
            'expires_in' => $this->tokenManager->getTtl(),
            'websocket_url' => $websocketUrl,
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

        try {
            $mode = ProviderMode::from(config('ai-bridge.mode', 'byok'));
        } catch (\ValueError) {
            $allowed = implode(', ', array_map(fn (ProviderMode $m) => "'{$m->value}'", ProviderMode::cases()));
            return response()->json([
                'error' => 'invalid_configuration',
                'message' => "Invalid AI_BRIDGE_MODE value. Allowed values: {$allowed}.",
            ], 500);
        }

        // Known limitation: under PHP-FPM this always returns bridge_connected=false
        // because BridgeConnectionManager's in-memory state is empty in each worker —
        // the WebSocket server runs in a separate process.
        $connectionData = $this->connectionManager->getConnection($userId);
        $connected = $connectionData !== null;

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
