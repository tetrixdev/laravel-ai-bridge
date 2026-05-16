<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\WebSocket;

use Illuminate\Support\Facades\Event;
use Tetrix\AiBridge\Contracts\SendableConnection;
use Tetrix\AiBridge\Events\BridgeConnected;
use Tetrix\AiBridge\Events\BridgeDisconnected;
use Tetrix\AiBridge\Streaming\StreamHandler;

/**
 * Manages active bridge connections and pending AI requests.
 *
 * Tracks which users have an active CLI bridge connected via WebSocket.
 * In-memory state — each WebSocket server worker maintains its own instance.
 *
 * The actual WebSocket transport (send/receive) is handled by the consuming
 * app's WebSocket server (e.g. Laravel Reverb). This class provides the
 * connection bookkeeping and message routing layer.
 *
 * ARCH-003 — PHP-FPM LIFECYCLE WARNING:
 * This class is registered as a singleton in the service container, but that does
 * NOT mean state persists across HTTP requests in PHP-FPM. PHP-FPM uses a
 * shared-nothing model: each worker gets its own process, and the singleton is
 * destroyed at the end of each request. Connection state only persists in
 * LONG-RUNNING processes:
 *   - The dedicated bridge WebSocket server (`ai-bridge:serve`)
 *   - Laravel Octane / Swoole workers
 *
 * Under PHP-FPM, hasConnection() / hasBridge() always return false because the
 * WebSocket server runs in a separate process. BridgeStream correctly accounts
 * for this by falling back to relayViaHttpApi() when no connection is found.
 * Do NOT call hasBridge() from a controller expecting in-memory state to reflect
 * the bridge server's actual connection state — use the /api/status endpoint on
 * the bridge server instead.
 */
class BridgeConnectionManager
{
    /**
     * Map of user_id => connection metadata.
     *
     * @var array<string, array{connection_id: string, connected_at: int, connection: mixed, providers: array<int, array<string, mixed>>}>
     */
    private array $connections = [];

    /**
     * Reverse index: connection_id => user_id.
     * Makes getUserIdByConnectionId() and removeConnectionByConnectionId() O(1).
     *
     * @var array<string, string>
     */
    private array $connectionIdIndex = [];

    /**
     * Map of request_id => pending request metadata.
     *
     * @var array<string, array{stream_handler: StreamHandler, user_id: string}>
     */
    private array $pendingRequests = [];

    /**
     * Callback for sending messages over the WebSocket connection.
     * Set by the consuming app's WebSocket server integration.
     *
     * @var \Closure|null  fn(mixed $connection, array $payload): bool
     */
    private ?\Closure $sendCallback = null;

    /**
     * Set the send callback used to transmit messages over WebSocket.
     *
     * The consuming app must set this to integrate with their WebSocket server.
     * The callback receives the connection object and the message payload array.
     *
     * @param  \Closure  $callback  fn(mixed $connection, array $payload): bool
     */
    public function setSendCallback(\Closure $callback): void
    {
        $this->sendCallback = $callback;
    }

    /**
     * Register a new bridge connection for a user.
     *
     * @param  int|string  $userId  The authenticated user ID.
     * @param  string  $connectionId  A unique identifier for this connection.
     * @param  mixed  $connection  The underlying WebSocket connection object.
     * @param  array<int, array<string, mixed>>  $providers  Provider capabilities from the bridge hello message.
     */
    public function addConnection(int|string $userId, string $connectionId, mixed $connection = null, array $providers = []): void
    {
        $userId = (string) $userId;

        // If the user already has a connection, disconnect it first
        if ($this->hasConnection($userId)) {
            $this->removeConnection($userId, 'replaced_by_new_connection');
        }

        $this->connections[$userId] = [
            'connection_id' => $connectionId,
            'connected_at' => time(),
            'connection' => $connection,
            'providers' => $providers,
        ];

        // Maintain reverse index for O(1) lookup by connection_id (ARCH-007)
        $this->connectionIdIndex[$connectionId] = $userId;

        Event::dispatch(new BridgeConnected($userId, $connectionId, time()));
    }

    /**
     * Remove a bridge connection for a user.
     *
     * @param  int|string  $userId  The user ID.
     * @param  string|null  $reason  The reason for disconnection.
     */
    public function removeConnection(int|string $userId, ?string $reason = null): void
    {
        $userId = (string) $userId;

        if (! isset($this->connections[$userId])) {
            return;
        }

        $connectionId = $this->connections[$userId]['connection_id'];

        // Fail any pending requests for this user
        $this->failPendingRequestsForUser($userId, $reason);

        unset($this->connections[$userId]);
        unset($this->connectionIdIndex[$connectionId]);

        Event::dispatch(new BridgeDisconnected($userId, $connectionId, $reason));
    }

    /**
     * Remove a bridge connection by its connection ID.
     *
     * This is useful when the consuming app's WebSocket server only knows the
     * connection ID (not the user ID), e.g. in onClose handlers.
     *
     * @param  string  $connectionId  The connection ID to look up and remove.
     * @param  string|null  $reason  The reason for disconnection.
     */
    public function removeConnectionByConnectionId(string $connectionId, ?string $reason = null): void
    {
        // O(1) lookup via reverse index (ARCH-007)
        $userId = $this->connectionIdIndex[$connectionId] ?? null;
        if ($userId !== null) {
            $this->removeConnection($userId, $reason);
        }
    }

    /**
     * Check if a user has an active bridge connection.
     */
    public function hasConnection(int|string $userId): bool
    {
        return isset($this->connections[(string) $userId]);
    }

    /**
     * Get connection metadata for a user.
     *
     * @return array{connection_id: string, connected_at: int, connection: mixed}|null
     */
    public function getConnection(int|string $userId): ?array
    {
        return $this->connections[(string) $userId] ?? null;
    }

    /**
     * Get the connection ID for a user, or null if not connected.
     */
    public function getConnectionId(int|string $userId): ?string
    {
        return $this->connections[(string) $userId]['connection_id'] ?? null;
    }

    /**
     * Look up the user ID associated with a connection ID.
     *
     * This is useful when a message arrives on a connection that was already
     * authenticated at connect time (e.g. via JWT in URL query param), and the
     * MessageHandler needs to know the user without re-authenticating.
     *
     * O(1) via reverse index (ARCH-007).
     */
    public function getUserIdByConnectionId(string $connectionId): ?string
    {
        return $this->connectionIdIndex[$connectionId] ?? null;
    }

    /**
     * Store provider capabilities from the bridge hello message.
     *
     * For pre-authenticated connections, the connection is registered before
     * the hello arrives. This method allows updating providers after the fact.
     *
     * @param  int|string  $userId  The user ID.
     * @param  array<int, array<string, mixed>>  $providers  Provider capabilities from hello message.
     */
    public function setProviders(int|string $userId, array $providers): void
    {
        $userId = (string) $userId;

        if (isset($this->connections[$userId])) {
            $this->connections[$userId]['providers'] = $providers;
        }
    }

    /**
     * Get the provider capabilities for a user's bridge connection.
     *
     * @param  int|string  $userId  The user ID.
     * @return array<int, array<string, mixed>>  Provider capabilities (may be empty if not yet received).
     */
    public function getProviders(int|string $userId): array
    {
        return $this->connections[(string) $userId]['providers'] ?? [];
    }

    /**
     * Send a message payload to a user's bridge connection.
     *
     * Supports two sending mechanisms:
     * 1. Direct BridgeConnection — if the stored connection object is a
     *    BridgeConnection instance, send JSON directly.
     * 2. Send callback — for custom transport integrations, falls back to
     *    the registered send callback.
     *
     * @param  int|string  $userId  The user ID.
     * @param  array<string, mixed>  $payload  The message to send.
     * @return bool  True if the message was sent successfully.
     */
    public function sendToUser(int|string $userId, array $payload): bool
    {
        $userId = (string) $userId;

        $connectionData = $this->connections[$userId] ?? null;
        if (! $connectionData) {
            return false;
        }

        $connection = $connectionData['connection'] ?? null;

        // Try direct send via the SendableConnection interface (ARCH-012).
        // BridgeConnection implements this interface; custom transport objects may too.
        if ($connection instanceof SendableConnection) {
            try {
                $connection->send(json_encode($payload));

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        // Fall back to send callback
        if ($this->sendCallback === null) {
            // No send callback configured — the consuming app hasn't set one up.
            // This is expected during development or when using a mock transport.
            return false;
        }

        try {
            return (bool) ($this->sendCallback)($connection, $payload);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Register a pending AI request so incoming stream events can be routed.
     *
     * @param  string  $requestId  The unique request ID.
     * @param  StreamHandler  $handler  The stream handler to dispatch events to.
     * @param  string  $userId  The user ID that owns this request.
     */
    public function registerPendingRequest(string $requestId, StreamHandler $handler, string $userId = ''): void
    {
        $this->pendingRequests[$requestId] = [
            'stream_handler' => $handler,
            'user_id' => $userId,
        ];
    }

    /**
     * Get the StreamHandler for a pending request.
     */
    public function getPendingRequest(string $requestId): ?StreamHandler
    {
        $entry = $this->pendingRequests[$requestId] ?? null;

        return $entry ? $entry['stream_handler'] : null;
    }

    /**
     * Remove a pending request (after completion or cancellation).
     */
    public function removePendingRequest(string $requestId): void
    {
        unset($this->pendingRequests[$requestId]);
    }

    /**
     * Get the user ID that owns a pending request.
     */
    public function getPendingRequestUserId(string $requestId): ?string
    {
        $entry = $this->pendingRequests[$requestId] ?? null;

        return $entry ? ($entry['user_id'] ?: null) : null;
    }

    /**
     * Get all active connection user IDs.
     *
     * @return string[]
     */
    public function connectedUserIds(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Get the total number of active connections.
     */
    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Fail all pending requests for a given user (e.g. on disconnect).
     *
     * Iterates all pending requests, finds those belonging to the given user,
     * dispatches an error event to their StreamHandlers, and removes them.
     */
    private function failPendingRequestsForUser(string $userId, ?string $reason = null): void
    {
        // When the disconnection reason is 'replaced_by_new_connection', the bridge
        // is reconnecting — use a more specific error code so consumers can differentiate.
        $errorCode = $reason === 'replaced_by_new_connection' ? 'bridge_reconnecting' : 'bridge_disconnected';
        $errorMessage = $reason === 'replaced_by_new_connection'
            ? 'Bridge is reconnecting — the in-flight request was interrupted. Please resend your message.'
            : 'Bridge connection lost.';

        // UX-008: In-flight requests are failed immediately when a new bridge connection
        // replaces the old one. There is currently no grace period. To add one, you would
        // queue the new connection registration and delay calling removeConnection() until
        // pending requests complete (or a timeout lapses). This is left as a future
        // enhancement; for now the error message clearly instructs the user to resend.

        foreach ($this->pendingRequests as $requestId => $handler) {
            if ($handler['user_id'] === $userId) {
                $handler['stream_handler']->dispatchError($errorCode, $errorMessage);
                unset($this->pendingRequests[$requestId]);
            }
        }
    }
}
