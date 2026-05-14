<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Server;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Auth\TokenValidationException;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * Ratchet WebSocket handler for CLI bridge connections.
 *
 * Implements the Ratchet MessageComponentInterface to handle the lifecycle
 * of bridge WebSocket connections: open, message, close, error.
 *
 * On connection open, validates the JWT from the `?token=` query parameter.
 * Incoming messages are routed through the MessageHandler. Outgoing responses
 * are sent back on the same connection.
 */
class BridgeWebSocketHandler implements MessageComponentInterface
{
    /**
     * Map of Ratchet resource ID => connection metadata.
     *
     * @var array<int, array{connection_id: string, user_id: string|null, connection: ConnectionInterface}>
     */
    private array $connections = [];

    public function __construct(
        private readonly BridgeConnectionManager $connectionManager,
        private readonly MessageHandler $messageHandler,
        private readonly TokenManager $tokenManager,
    ) {}

    /**
     * Called when a new WebSocket connection is opened.
     *
     * Validates the JWT from the query string. If valid, stores the connection.
     * If invalid, closes the connection with an error.
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $resourceId = $conn->resourceId;
        $connectionId = 'bridge-' . Str::uuid()->toString();

        // Extract token from query string
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $queryParams);
        $token = $queryParams['token'] ?? '';

        if (empty($token)) {
            Log::info('AI Bridge Server: connection rejected — no token', [
                'resource_id' => $resourceId,
            ]);

            $conn->send(json_encode([
                'type' => 'connection_error',
                'error' => 'missing_token',
                'message' => 'Connection requires a ?token= query parameter with a valid JWT.',
            ]));

            $conn->close();

            return;
        }

        // Validate JWT
        try {
            $decoded = $this->tokenManager->validate($token);
            $userId = (string) $decoded->sub;
        } catch (TokenValidationException $e) {
            Log::info('AI Bridge Server: connection rejected — invalid token', [
                'resource_id' => $resourceId,
                'error_code' => $e->errorCode,
            ]);

            $conn->send(json_encode([
                'type' => 'connection_error',
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ]));

            $conn->close();

            return;
        }

        // Store connection metadata
        $this->connections[$resourceId] = [
            'connection_id' => $connectionId,
            'user_id' => $userId,
            'connection' => $conn,
        ];

        // Register in the BridgeConnectionManager with a send callback
        $this->connectionManager->addConnection($userId, $connectionId, $conn);

        // Set up send callback so BridgeConnectionManager can send messages
        $this->connectionManager->setSendCallback(function (mixed $connection, array $payload): bool {
            if ($connection instanceof ConnectionInterface) {
                $connection->send(json_encode($payload));

                return true;
            }

            return false;
        });

        Log::info('AI Bridge Server: connection established', [
            'connection_id' => $connectionId,
            'user_id' => $userId,
            'resource_id' => $resourceId,
        ]);
    }

    /**
     * Called when a message is received from a bridge client.
     *
     * Delegates to the MessageHandler for protocol processing. If the handler
     * returns a response, it is sent back on the same connection.
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $resourceId = $from->resourceId;
        $meta = $this->connections[$resourceId] ?? null;

        if ($meta === null) {
            // Connection not registered — should not happen, but handle gracefully
            Log::warning('AI Bridge Server: message from unregistered connection', [
                'resource_id' => $resourceId,
            ]);

            return;
        }

        $connectionId = $meta['connection_id'];

        // Route through the MessageHandler
        $response = $this->messageHandler->handleMessage($connectionId, $from, $msg);

        if ($response !== null) {
            $from->send(json_encode($response));
        }
    }

    /**
     * Called when a WebSocket connection is closed.
     *
     * Cleans up connection tracking and fails any pending requests.
     */
    public function onClose(ConnectionInterface $conn): void
    {
        $resourceId = $conn->resourceId;
        $meta = $this->connections[$resourceId] ?? null;

        if ($meta === null) {
            return;
        }

        $connectionId = $meta['connection_id'];
        $userId = $meta['user_id'];

        Log::info('AI Bridge Server: connection closed', [
            'connection_id' => $connectionId,
            'user_id' => $userId,
            'resource_id' => $resourceId,
        ]);

        // Remove from BridgeConnectionManager (this also fails pending requests)
        if ($userId !== null) {
            $this->connectionManager->removeConnection($userId, 'websocket_closed');
        }

        unset($this->connections[$resourceId]);
    }

    /**
     * Called when an error occurs on a WebSocket connection.
     *
     * Logs the error and closes the connection.
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $resourceId = $conn->resourceId;
        $meta = $this->connections[$resourceId] ?? null;

        Log::error('AI Bridge Server: connection error', [
            'resource_id' => $resourceId,
            'connection_id' => $meta['connection_id'] ?? 'unknown',
            'user_id' => $meta['user_id'] ?? 'unknown',
            'error' => $e->getMessage(),
        ]);

        $conn->close();
    }

    /**
     * Get the count of active connections on this server.
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }
}
