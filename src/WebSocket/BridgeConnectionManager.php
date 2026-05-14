<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\WebSocket;

use Illuminate\Support\Facades\Event;
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
 */
class BridgeConnectionManager
{
    /**
     * Map of user_id => connection metadata.
     *
     * @var array<string, array{connection_id: string, connected_at: int, connection: mixed}>
     */
    private array $connections = [];

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
     */
    public function addConnection(int|string $userId, string $connectionId, mixed $connection = null): void
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
        ];

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
        $this->failPendingRequestsForUser($userId);

        unset($this->connections[$userId]);

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
        foreach ($this->connections as $userId => $data) {
            if ($data['connection_id'] === $connectionId) {
                $this->removeConnection($userId, $reason);

                return;
            }
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
     * Send a message payload to a user's bridge connection.
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

        if ($this->sendCallback === null) {
            // No send callback configured — the consuming app hasn't set one up.
            // This is expected during development or when using a mock transport.
            return false;
        }

        try {
            return (bool) ($this->sendCallback)($connectionData['connection'], $payload);
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
    protected function failPendingRequestsForUser(string $userId): void
    {
        foreach ($this->pendingRequests as $requestId => $handler) {
            if ($handler['user_id'] === $userId) {
                $handler['stream_handler']->dispatchError('bridge_disconnected', 'Bridge connection lost');
                unset($this->pendingRequests[$requestId]);
            }
        }
    }
}
