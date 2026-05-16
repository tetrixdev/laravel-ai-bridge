<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Server;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Auth\TokenValidationException;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * WebSocket handler for CLI bridge connections.
 *
 * Handles the lifecycle of bridge WebSocket connections: open, message,
 * close, error. Works with BridgeConnection value objects provided by
 * the BridgeWebSocketServer (react/http + ratchet/rfc6455 stack).
 *
 * On connection open, validates the JWT from the `?token=` query parameter.
 * Incoming messages are routed through the MessageHandler. Outgoing responses
 * are sent back on the same connection.
 */
class BridgeWebSocketHandler
{
    /**
     * Map of resource ID => connection metadata.
     *
     * @var array<int, array{connection_id: string, user_id: string|null, connection: BridgeConnection}>
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
     * Validates the JWT from the Authorization header (preferred) or query string
     * (fallback for backward compatibility). If valid, stores the connection.
     * If invalid, closes the connection with an error.
     *
     * @param  string  $queryString  Raw query string from the WebSocket upgrade URL.
     * @param  string  $authorizationHeader  Value of the Authorization header, if any.
     */
    public function onOpen(BridgeConnection $conn, string $queryString, string $authorizationHeader = ''): void
    {
        $resourceId = $conn->resourceId;
        $connectionId = 'bridge-' . Str::uuid()->toString();

        // Prefer the Authorization: Bearer header to keep the JWT out of server
        // access logs. The ?token= query parameter is a backward-compatible fallback.
        $token = $this->extractToken($authorizationHeader, $queryString);

        if (empty($token)) {
            Log::info('AI Bridge Server: connection rejected — no token', [
                'resource_id' => $resourceId,
            ]);

            $conn->send(json_encode([
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => 'missing_token',
                'message' => 'Connection requires an Authorization: Bearer <token> header or a ?token= query parameter.',
            ]));

            $conn->close();

            return;
        }

        // Validate JWT
        try {
            $decoded = $this->tokenManager->validate($token);
            $userId = (string) $decoded->sub;
        } catch (TokenValidationException $e) {
            // Log the specific error code server-side but return a generic
            // 'token_invalid' code to the client to prevent error-oracle attacks.
            Log::info('AI Bridge Server: connection rejected — invalid token', [
                'resource_id' => $resourceId,
                'error_code' => $e->errorCode,
            ]);

            $conn->send(json_encode([
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => 'token_invalid',
                'message' => 'Token validation failed.',
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

        // Register in the BridgeConnectionManager — BridgeConnection objects support
        // direct send via the BridgeConnectionManager::sendToUser() instanceof check.
        $this->connectionManager->addConnection($userId, $connectionId, $conn);

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
    public function onMessage(BridgeConnection $from, string $msg): void
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
    public function onClose(BridgeConnection $conn): void
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

        // Reconnection race: the old TCP connection may fire onClose after a new
        // connection has already replaced it. Only remove from the manager if its
        // current connection_id for this user still matches the closing connection.
        if ($userId !== null) {
            $currentConnectionId = $this->connectionManager->getConnectionId($userId);
            if ($currentConnectionId === $connectionId) {
                $this->connectionManager->removeConnection($userId, 'websocket_closed');
            } else {
                Log::info('AI Bridge Server: stale connection closed after reconnect — skipping removeConnection', [
                    'closing_connection_id' => $connectionId,
                    'current_connection_id' => $currentConnectionId,
                    'user_id' => $userId,
                ]);
            }
        }

        unset($this->connections[$resourceId]);
    }

    /**
     * Called when an error occurs on a WebSocket connection.
     *
     * Logs the error and closes the connection.
     */
    public function onError(BridgeConnection $conn, \Exception $e): void
    {
        $resourceId = $conn->resourceId;
        $meta = $this->connections[$resourceId] ?? null;

        Log::error('AI Bridge Server: connection error', [
            'resource_id' => $resourceId,
            'connection_id' => $meta['connection_id'] ?? 'unknown',
            'user_id' => $meta['user_id'] ?? 'unknown',
            'error' => $e->getMessage(),
        ]);

        // Clean up connection tracking before closing to prevent leaks
        if ($meta !== null) {
            $userId = $meta['user_id'];
            $connectionId = $meta['connection_id'];

            // Reconnection race: mirrors the guard in onClose(). Only remove from
            // the manager if its current connection_id still matches the errored one.
            if ($userId !== null) {
                $currentConnectionId = $this->connectionManager->getConnectionId($userId);
                if ($currentConnectionId === $connectionId) {
                    $this->connectionManager->removeConnection($userId, 'websocket_error');
                } else {
                    Log::info('AI Bridge Server: stale connection errored after reconnect — skipping removeConnection', [
                        'errored_connection_id' => $connectionId,
                        'current_connection_id' => $currentConnectionId,
                        'user_id' => $userId,
                    ]);
                }
            }
            unset($this->connections[$resourceId]);
        }

        $conn->close();
    }

    /**
     * Get the count of active connections on this server.
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Extract the JWT from the connection credentials.
     *
     * Checks Authorization: Bearer header first (preferred — keeps token out of logs).
     * Falls back to ?token= query parameter for backward compatibility.
     */
    private function extractToken(string $authorizationHeader, string $queryString): string
    {
        // Prefer Authorization: Bearer header
        if (str_starts_with($authorizationHeader, 'Bearer ')) {
            return substr($authorizationHeader, 7);
        }

        // Fall back to query parameter
        parse_str($queryString, $queryParams);
        $queryToken = $queryParams['token'] ?? '';

        // The ?token= query parameter is a deprecated fallback — the full JWT
        // appears in access logs. Warn so operators can migrate clients.
        if ($queryToken !== '') {
            Log::warning('AI Bridge Server: bridge authenticated via deprecated ?token= query parameter. '
                .'The JWT may be exposed in access logs — migrate this client to the Authorization: Bearer header.');
        }

        return $queryToken;
    }
}
