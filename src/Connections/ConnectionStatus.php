<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Connections;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Models\Connection;

/**
 * Live status for an AI connection — whether it is currently usable and the providers it
 * offers — resolved authoritatively rather than from cached state.
 *
 * The bridge WebSocket server holds the real connection registry in memory (a PHP-FPM
 * worker can't see it), so for a **bridge** connection this queries the server's internal
 * `/api/status` endpoint (with a short-lived relay token) and write-through-refreshes the
 * connection's cached `last_providers` / `last_connected_at`. A **BYOK** connection is
 * always usable (it carries a key); its providers come from the chat-completions config.
 *
 * This is the public, reusable form of the logic the connections API uses internally —
 * call it from app code that needs to know if a connection is live (e.g. gating a feature
 * on an available provider) without re-deriving the relay-token + endpoint plumbing.
 */
class ConnectionStatus
{
    public function __construct(
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * Live status for any connection.
     *
     * @return array{connected: bool, providers: array<int, mixed>}
     */
    public function for(Connection $connection): array
    {
        if ($connection->isByok()) {
            return ['connected' => true, 'providers' => $this->byokProviders()];
        }

        return $this->bridgeLiveStatus($connection);
    }

    /** Whether the connection is currently usable. */
    public function isConnected(Connection $connection): bool
    {
        return $this->for($connection)['connected'];
    }

    /**
     * Query the bridge server for a bridge connection's live status, refreshing the cached
     * capabilities. Falls back to cached providers + connected=false when unreachable.
     *
     * @return array{connected: bool, providers: array<int, mixed>}
     */
    private function bridgeLiveStatus(Connection $connection): array
    {
        if (empty($connection->connection_key)) {
            return ['connected' => false, 'providers' => $connection->last_providers ?? []];
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
                $connected = (bool) $response->json('connected');

                // /api/status only includes `providers`/`connected_at` while a CLI is
                // attached. On a successful-but-disconnected poll keep the cached snapshot
                // rather than erasing it, and prefer the relay's own connect time.
                $providers = $connected
                    ? ($response->json('providers') ?? [])
                    : ($connection->last_providers ?? []);
                $connectedAt = $response->json('connected_at');

                $connection->forceFill([
                    'last_providers' => $providers,
                    'last_connected_at' => $connected && $connectedAt !== null
                        ? $connectedAt
                        : $connection->last_connected_at,
                ])->save();

                return ['connected' => $connected, 'providers' => $providers];
            }
        } catch (\Throwable $e) {
            Log::info('AI Bridge: bridge status unreachable', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['connected' => false, 'providers' => $connection->last_providers ?? []];
    }

    /**
     * The provider/model capabilities a BYOK connection exposes, from chat-completions config.
     *
     * @return array<int, mixed>
     */
    private function byokProviders(): array
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
