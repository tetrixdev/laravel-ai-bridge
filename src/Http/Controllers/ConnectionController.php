<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tetrix\AiBridge\AiBridgeManager;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Models\Connection;

/**
 * HTTP API for AI connections (CLI bridges + BYOK endpoints) and their
 * advertised capabilities (providers + models).
 *
 * Scoped through the project-supplied connections resolver. Routes are
 * additionally protected by the configured route_middleware.
 */
class ConnectionController extends Controller
{
    public function __construct(
        private readonly AiBridgeManager $manager,
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * GET /ai-bridge/connections — list connections with live capabilities.
     */
    public function index(Request $request): JsonResponse
    {
        $connections = $this->manager->connectionsQuery($request)->get();

        $payload = $connections->map(function (Connection $connection) {
            $data = $connection->only(['id', 'type', 'name', 'last_connected_at']);
            $data['providers'] = $connection->isBridge()
                ? $this->bridgeProviders($connection)
                : $this->byokProviders($connection);

            return $data;
        });

        return response()->json(['connections' => $payload]);
    }

    /**
     * POST /ai-bridge/connections — register a connection.
     *
     * For type=bridge the response includes a connection token + the bridge
     * command so the user can start their local CLI bridge.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:bridge,byok'],
            'name' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:255', 'required_if:type,byok'],
            'api_key' => ['nullable', 'string', 'max:512', 'required_if:type,byok'],
        ]);

        $attributes = [
            'type' => $validated['type'],
            'name' => $validated['name'] ?? null,
        ];

        if ($validated['type'] === Connection::TYPE_BRIDGE) {
            $attributes['connection_key'] = (string) Str::uuid();
        } else {
            $attributes['endpoint'] = $validated['endpoint'];
            $attributes['api_key'] = $validated['api_key']; // encrypted by the model cast
        }

        $connection = Connection::create($attributes);

        event(new \Tetrix\AiBridge\Events\ConnectionCreated($connection, $request));

        $response = ['connection' => $connection->fresh()];

        if ($connection->isBridge()) {
            $token = $this->tokenManager->generate($connection->connection_key);
            $wsUrl = $this->websocketUrl();
            $response['token'] = $token;
            $response['websocket_url'] = $wsUrl;
            $response['command'] = "npx @tetrixdev/ai-bridge@latest --server {$wsUrl} --token {$token}";
        }

        return response()->json($response, 201);
    }

    /**
     * DELETE /ai-bridge/connections/{id}.
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $connection = $this->manager->connectionsQuery($request)->whereKey($id)->first();

        if ($connection === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Connection not found.'], 404);
        }

        $connection->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Live provider capabilities for a bridge connection.
     *
     * Queries the bridge WebSocket server's internal /api/status endpoint with
     * an internal_relay token scoped to the connection key. Falls back to the
     * cached last_providers when the server is unreachable.
     *
     * @return array<int, mixed>
     */
    private function bridgeProviders(Connection $connection): array
    {
        if (empty($connection->connection_key)) {
            return $connection->last_providers ?? [];
        }

        try {
            $relayToken = $this->tokenManager->generate(
                $connection->connection_key,
                ['scope' => TokenManager::INTERNAL_RELAY_SCOPE],
                60,
            );

            $response = Http::withToken($relayToken)
                ->timeout((int) config('ai-bridge.server.relay_timeout', 5))
                ->acceptJson()
                ->get($this->internalApiBase().'/api/status');

            if ($response->successful()) {
                $providers = $response->json('providers') ?? [];

                // Refresh the cache so capabilities survive a server restart.
                $connection->forceFill([
                    'last_providers' => $providers,
                    'last_connected_at' => $response->json('connected') ? now() : $connection->last_connected_at,
                ])->save();

                return $providers;
            }
        } catch (\Throwable $e) {
            Log::info('AI Bridge: bridge status unreachable for capabilities', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $connection->last_providers ?? [];
    }

    /**
     * Synthetic provider capabilities for a BYOK connection.
     *
     * @return array<int, mixed>
     */
    private function byokProviders(Connection $connection): array
    {
        $allowed = (array) config('ai-bridge.chat_completions.allowed_models', []);
        $configured = config('ai-bridge.chat_completions.model');

        $modelIds = $allowed !== [] ? $allowed : array_filter([$configured]);

        $models = array_map(fn ($id) => [
            'id' => $id,
            'name' => $id,
            'is_default' => $id === $configured,
        ], array_values($modelIds));

        return [[
            'name' => 'chat_completions',
            'available' => true,
            'supports_streaming' => true,
            'supports_tools' => true,
            'supports_thinking' => false,
            'supports_session_resume' => false,
            'models' => $models,
        ]];
    }

    /**
     * Public WebSocket URL handed to bridge clients.
     */
    private function websocketUrl(): string
    {
        $publicUrl = config('ai-bridge.server.public_url');
        if (! empty($publicUrl)) {
            return rtrim((string) $publicUrl, '/');
        }

        $host = (string) config('ai-bridge.server.host', '127.0.0.1');
        $port = (int) config('ai-bridge.server.port', 8085);
        if ($host === '0.0.0.0') {
            $host = 'localhost';
        }

        return "ws://{$host}:{$port}";
    }

    /**
     * Base URL for the bridge server's internal HTTP API.
     */
    private function internalApiBase(): string
    {
        $relayUrl = config('ai-bridge.server.relay_url');
        if (! empty($relayUrl)) {
            return rtrim((string) $relayUrl, '/');
        }

        $host = (string) config('ai-bridge.server.host', '127.0.0.1');
        $port = (int) config('ai-bridge.server.port', 8085);
        if ($host === '0.0.0.0') {
            $host = '127.0.0.1';
        }

        return "http://{$host}:{$port}";
    }
}
