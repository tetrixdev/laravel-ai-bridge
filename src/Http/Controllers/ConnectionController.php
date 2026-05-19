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

        $prefix = (string) config('ai-bridge.persistence.channel_prefix', 'ai-bridge');

        $payload = $connections->map(function (Connection $connection) use ($prefix) {
            $data = $connection->only(['id', 'type', 'name', 'last_connected_at']);

            if ($connection->isBridge()) {
                // Live capabilities + whether a CLI process is currently
                // attached, so the UI can show an accurate status indicator.
                $status = $this->bridgeLiveStatus($connection);
                $data['providers'] = $status['providers'];
                $data['connected'] = $status['connected'];

                // The private channel that pushes this bridge's connect/disconnect
                // status — the chat UI subscribes to it instead of polling.
                $data['channel'] = $prefix.'.connection.'.$connection->id;
            } else {
                $data['providers'] = $this->byokProviders($connection);
            }

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
            $token = $this->generateBridgeToken($connection);
            $wsUrl = $this->websocketUrl();
            $response['token'] = $token;
            $response['websocket_url'] = $wsUrl;
            $response['command'] = $this->bridgeCommand($wsUrl, $token);
        }

        return response()->json($response, 201);
    }

    /**
     * PATCH /ai-bridge/connections/{id} — rename a connection.
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $connection = $this->manager->connectionsQuery($request)->whereKey($id)->first();

        if ($connection === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Connection not found.'], 404);
        }

        // PATCH semantics: only touch the name when the field was sent.
        if ($request->has('name')) {
            $connection->name = $request->input('name');
            $connection->save();
        }

        return response()->json(['connection' => $connection->fresh()]);
    }

    /**
     * POST /ai-bridge/connections/{id}/regenerate — rotate a bridge's token.
     *
     * Rotates the connection_key so the previous token can never authenticate
     * again, then actively disconnects any bridge still using it. Returns a
     * fresh token + command for the user to start the bridge somewhere new.
     */
    public function regenerate(Request $request, int|string $id): JsonResponse
    {
        $connection = $this->manager->connectionsQuery($request)->whereKey($id)->first();

        if ($connection === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Connection not found.'], 404);
        }

        if (! $connection->isBridge()) {
            return response()->json([
                'error' => 'not_a_bridge',
                'message' => 'Only CLI bridge connections have a connection token.',
            ], 422);
        }

        $oldKey = $connection->connection_key;

        $connection->forceFill(['connection_key' => (string) Str::uuid()])->save();

        // Cut off any bridge still attached with the old token. Its key no
        // longer exists, so the handshake would reject a reconnect anyway —
        // this just makes the running process exit now instead of later.
        if (! empty($oldKey)) {
            $this->disconnectBridge($oldKey);
        }

        $token = $this->generateBridgeToken($connection);
        $wsUrl = $this->websocketUrl();

        return response()->json([
            'connection' => $connection->fresh(),
            'token' => $token,
            'websocket_url' => $wsUrl,
            'command' => $this->bridgeCommand($wsUrl, $token),
        ]);
    }

    /**
     * Generate a CLI bridge connection token.
     *
     * Carries a `cid` claim (the connection id) so the WebSocket handshake can
     * confirm the connection still exists and its key has not been rotated.
     * Uses the long bridge TTL — bridges are semi-permanent connections.
     */
    private function generateBridgeToken(Connection $connection): string
    {
        return $this->tokenManager->generate(
            (string) $connection->connection_key,
            ['cid' => $connection->id],
            (int) config('ai-bridge.token.bridge_ttl', 2592000),
        );
    }

    /**
     * Ask the bridge WebSocket server to drop the live connection for a key.
     *
     * Best-effort: a failure (server down, nothing connected) is fine — the
     * caller has already invalidated the key in the database.
     */
    private function disconnectBridge(string $connectionKey): void
    {
        try {
            $relayToken = $this->tokenManager->generate(
                $connectionKey,
                ['scope' => TokenManager::INTERNAL_RELAY_SCOPE],
                60,
            );

            Http::withToken($relayToken)
                ->timeout((int) config('ai-bridge.server.relay_timeout', 5))
                ->acceptJson()
                ->post($this->internalApiBase().'/api/disconnect');
        } catch (\Throwable $e) {
            Log::info('AI Bridge: bridge disconnect request failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the command shown to the user for starting their CLI bridge.
     *
     * Defaults to the published npm package via npx. For local development of
     * the bridge CLI itself, set ai-bridge.cli.local_path
     * (AI_BRIDGE_CLI_LOCAL_PATH) to an ai-bridge repo checkout: when
     * APP_ENV=local the command instead runs that checkout's build directly,
     * so CLI changes can be tested without an npm publish. The checkout must be
     * built first (`npm run build`).
     */
    private function bridgeCommand(string $wsUrl, string $token): string
    {
        $localPath = config('ai-bridge.cli.local_path');

        if (app()->environment('local') && is_string($localPath) && $localPath !== '') {
            $cli = rtrim($localPath, '/').'/dist/cli.js';

            return "node {$cli} --server {$wsUrl} --token {$token}";
        }

        return "npx @tetrixdev/ai-bridge@latest --server {$wsUrl} --token {$token}";
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

        // Cut off any live bridge before removing the row, so the CLI process
        // exits immediately rather than lingering on an orphaned connection.
        if ($connection->isBridge() && ! empty($connection->connection_key)) {
            $this->disconnectBridge($connection->connection_key);
        }

        $connection->delete();

        event(new \Tetrix\AiBridge\Events\ConnectionDeleted($connection, $request));

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Live status (providers + whether a CLI process is attached) for a bridge.
     *
     * Queries the bridge WebSocket server's internal /api/status endpoint with
     * an internal_relay token scoped to the connection key. Falls back to the
     * cached last_providers (and connected=false) when the server is unreachable.
     *
     * @return array{providers: array<int, mixed>, connected: bool}
     */
    private function bridgeLiveStatus(Connection $connection): array
    {
        if (empty($connection->connection_key)) {
            return ['providers' => $connection->last_providers ?? [], 'connected' => false];
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
                $connected = (bool) $response->json('connected');

                // Refresh the cache so capabilities survive a server restart.
                $connection->forceFill([
                    'last_providers' => $providers,
                    'last_connected_at' => $connected ? now() : $connection->last_connected_at,
                ])->save();

                return ['providers' => $providers, 'connected' => $connected];
            }
        } catch (\Throwable $e) {
            Log::info('AI Bridge: bridge status unreachable for capabilities', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['providers' => $connection->last_providers ?? [], 'connected' => false];
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
