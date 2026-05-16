<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Server;

use GuzzleHttp\Psr7\Message;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7\HttpFactory;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;
use Tetrix\AiBridge\WebSocket\MessageHandler;

/**
 * Dedicated WebSocket server for CLI bridge connections.
 *
 * This is NOT Laravel Reverb — it is a separate, lightweight server on its
 * own port that speaks the AI Bridge Protocol. It handles connections from
 * `npx @tetrixdev/ai-bridge` clients.
 *
 * Uses react/socket + ratchet/rfc6455 under the hood — the same stack
 * Laravel Reverb uses internally. Does NOT depend on cboden/ratchet.
 */
class BridgeWebSocketServer
{
    private ?LoopInterface $loop = null;

    private ?SocketServer $socket = null;

    /**
     * Auto-incrementing resource ID counter for connections.
     */
    private int $nextResourceId = 1;

    /**
     * The server negotiator for WebSocket handshakes.
     */
    private ?ServerNegotiator $negotiator = null;

    public function __construct(
        private readonly BridgeConnectionManager $connectionManager,
        private readonly MessageHandler $messageHandler,
        private readonly TokenManager $tokenManager,
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8085,
    ) {}

    /**
     * Start the WebSocket server.
     *
     * This method blocks — it runs the ReactPHP event loop until shutdown.
     *
     * TODO (ARCH-010): Heartbeat watchdog is not yet implemented.
     * PROTOCOL.md states the server must close connections that have not sent a ping
     * within 2 × heartbeat_interval (default: 60 seconds). Without this watchdog,
     * stale half-open connections (e.g. a silently-dropped mobile network connection)
     * accumulate in BridgeConnectionManager and cause subsequent ai_request messages
     * to be silently lost (sendToUser() writes to a broken socket).
     *
     * To implement: after setting up the socket, add a ReactPHP periodic timer:
     *   $heartbeatInterval = config('ai-bridge.websocket.heartbeat_interval', 30);
     *   $this->loop->addPeriodicTimer($heartbeatInterval * 2, function () use ($handler) {
     *       // Iterate connections, check last-ping timestamp, close stale ones
     *   });
     * Track last-seen timestamp per BridgeConnection in BridgeConnectionManager or
     * BridgeWebSocketHandler, update it on each received message, and call
     * $handler->onClose($conn) for connections silent for > 2 × heartbeat_interval.
     *
     * @param  callable|null  $onStart  Callback invoked after the server starts listening.
     */
    public function start(?callable $onStart = null): void
    {
        $this->loop = Loop::get();

        $this->negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());

        $handler = new BridgeWebSocketHandler(
            $this->connectionManager,
            $this->messageHandler,
            $this->tokenManager,
        );

        $this->socket = new SocketServer("{$this->host}:{$this->port}", [], $this->loop);

        $this->socket->on('connection', function (ConnectionInterface $tcpConnection) use ($handler) {
            $this->handleTcpConnection($tcpConnection, $handler);
        });

        if ($onStart !== null) {
            // Schedule the callback to run after the loop starts
            $this->loop->futureTick($onStart);
        }

        $this->loop->run();
    }

    /**
     * Handle a new raw TCP connection.
     *
     * Buffers the initial HTTP request data, performs the WebSocket upgrade
     * handshake using ratchet/rfc6455's ServerNegotiator, then sets up a
     * MessageBuffer to parse incoming WebSocket frames.
     */
    private function handleTcpConnection(ConnectionInterface $tcpConnection, BridgeWebSocketHandler $handler): void
    {
        $httpBuffer = '';
        $upgraded = false;
        $headersComplete = false;
        $expectedBodyLength = 0;
        $headerLength = 0;

        // Store a reference to the HTTP-buffering closure so we can remove it
        // after the WebSocket upgrade (EFF-002: prevents the stale listener from
        // firing on every subsequent WebSocket frame, even though it exits immediately).
        $httpListener = null;
        $httpListener = function (string $data) use ($tcpConnection, $handler, &$httpBuffer, &$upgraded, &$headersComplete, &$expectedBodyLength, &$headerLength, &$httpListener) {
            // If already upgraded, data is handled by the MessageBuffer (attached below).
            // This branch should never fire after upgradeConnection() removes the listener,
            // but the guard remains as a safety net.
            if ($upgraded) {
                return;
            }

            $httpBuffer .= $data;

            // SEC: Prevent unbounded buffer growth before WebSocket upgrade (max 64KB).
            if (strlen($httpBuffer) > 65536) {
                $tcpConnection->write("HTTP/1.1 413 Payload Too Large\r\nContent-Length: 16\r\n\r\nPayload too large");
                $tcpConnection->end();

                return;
            }

            // Phase 1: wait for headers to complete (\r\n\r\n)
            if (! $headersComplete) {
                $headerEnd = strpos($httpBuffer, "\r\n\r\n");
                if ($headerEnd === false) {
                    return;
                }

                $headersComplete = true;
                $headerLength = $headerEnd + 4;

                // Extract Content-Length from headers to know how much body to expect
                $headerSection = substr($httpBuffer, 0, $headerEnd);
                if (preg_match('/^Content-Length:\s*(\d+)/im', $headerSection, $m)) {
                    $expectedBodyLength = (int) $m[1];
                }
            }

            // Phase 2: wait for the full body (if any)
            $receivedBodyLength = strlen($httpBuffer) - $headerLength;
            if ($receivedBodyLength < $expectedBodyLength) {
                return;
            }

            $upgraded = true;

            // Parse the raw HTTP request into a PSR-7 request
            try {
                $request = Message::parseRequest($httpBuffer);
            } catch (\Throwable) {
                $tcpConnection->write("HTTP/1.1 400 Bad Request\r\nContent-Length: 12\r\n\r\nBad Request\n");
                $tcpConnection->end();

                return;
            }

            $httpBuffer = '';

            // Check if this is a WebSocket upgrade request or a plain HTTP request
            if (! $this->isWebSocketUpgrade($request)) {
                $this->handleHttpRequest($tcpConnection, $request);

                return;
            }

            $this->upgradeConnection($tcpConnection, $request, $handler, $httpListener);
        };

        $tcpConnection->on('data', $httpListener);
    }

    /**
     * Attempt a WebSocket upgrade on the given TCP connection.
     *
     * If the handshake succeeds, writes the 101 response to the stream, creates
     * a BridgeConnection wrapper and MessageBuffer, then notifies the handler.
     *
     * @param  callable|null  $httpListener  The HTTP-buffering data listener to remove after upgrade (EFF-002).
     */
    private function upgradeConnection(
        ConnectionInterface $tcpConnection,
        RequestInterface $request,
        BridgeWebSocketHandler $handler,
        ?callable $httpListener = null,
    ): void {
        // Perform the WebSocket upgrade handshake
        $response = $this->negotiator->handshake($request);

        if ($response->getStatusCode() !== 101) {
            $tcpConnection->write(Message::toString($response));
            $tcpConnection->end();

            return;
        }

        // Write the 101 Switching Protocols response to complete the upgrade
        $tcpConnection->write(Message::toString($response));

        // EFF-002: Remove the HTTP-buffering listener now that the WebSocket upgrade
        // is complete. Without this, the listener stays registered and fires (and
        // immediately returns) on every subsequent WebSocket frame, wasting dispatch cycles.
        if ($httpListener !== null) {
            $tcpConnection->removeListener('data', $httpListener);
        }

        $resourceId = $this->nextResourceId++;
        $queryString = $request->getUri()->getQuery();
        $authorizationHeader = $request->getHeaderLine('Authorization');

        // Guard against duplicate onClose calls (close frame + TCP close event)
        $closed = false;
        $doClose = function () use ($handler, &$bridgeConnection, &$closed) {
            if ($closed) {
                return;
            }
            $closed = true;
            $handler->onClose($bridgeConnection);
        };

        // Create a BridgeConnection wrapper with a send callback that encodes
        // outgoing messages into WebSocket frames before writing to the stream.
        $bridgeConnection = new BridgeConnection(
            resourceId: $resourceId,
            stream: $tcpConnection,
            sendCallback: function (string $data) use ($tcpConnection) {
                $frame = new Frame($data);
                $tcpConnection->write($frame->getContents());
            },
        );

        // Set up the MessageBuffer to parse incoming WebSocket frames.
        // The buffer calls our onMessage handler when a complete message arrives,
        // and handles control frames (ping/pong/close) automatically.
        // ARCH-004: Cap message size at 1 MB to prevent memory exhaustion from
        // oversized bridge messages. This accommodates typical AI response payloads
        // while bounding per-connection memory consumption.
        $maxMessagePayloadSize = 1024 * 1024; // 1 MB
        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            onMessage: function (MessageInterface $message) use ($handler, $bridgeConnection) {
                $handler->onMessage($bridgeConnection, (string) $message->getPayload());
            },
            onControl: function (FrameInterface $frame) use ($tcpConnection, $doClose, $bridgeConnection) {
                match ($frame->getOpcode()) {
                    Frame::OP_PING => $tcpConnection->write(
                        (new Frame($frame->getPayload(), opcode: Frame::OP_PONG))->getContents()
                    ),
                    Frame::OP_CLOSE => (function () use ($tcpConnection, $doClose, $frame) {
                        // Echo the close frame back per RFC 6455
                        $tcpConnection->write(
                            (new Frame($frame->getPayload(), opcode: Frame::OP_CLOSE))->getContents()
                        );
                        $doClose();
                        $tcpConnection->end();
                    })(),
                    default => null,
                };
            },
            maxMessagePayloadSize: $maxMessagePayloadSize,
            sender: function (string $data) use ($tcpConnection) {
                $tcpConnection->write($data);
            },
        );

        // Route incoming TCP data through the MessageBuffer for frame parsing
        $tcpConnection->on('data', [$messageBuffer, 'onData']);

        // Handle TCP connection close (e.g. client disconnects without close frame)
        $tcpConnection->on('close', $doClose);

        // Handle TCP errors
        $tcpConnection->on('error', function (\Exception $e) use ($handler, $bridgeConnection) {
            $handler->onError($bridgeConnection, $e);
        });

        // Notify the handler that the WebSocket connection is open.
        // Pass the Authorization header so the handler can prefer it over the query param.
        $handler->onOpen($bridgeConnection, $queryString, $authorizationHeader);
    }

    /**
     * Stop the server gracefully.
     */
    public function stop(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
        }

        if ($this->loop !== null) {
            $this->loop->stop();
        }
    }

    /**
     * Get the event loop instance (available after start() is called).
     */
    public function getLoop(): ?LoopInterface
    {
        return $this->loop;
    }

    /**
     * Get the host address.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the port number.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    // -------------------------------------------------------------------------
    // Internal HTTP API
    // -------------------------------------------------------------------------
    // The bridge server accepts plain HTTP requests alongside WebSocket
    // connections. This allows the web app (PHP-FPM) to communicate with
    // connected bridge clients through the same port.
    //
    // Endpoints:
    //   POST /api/request   — Send an ai_request to a user's bridge
    //   GET  /api/status    — Check connected users
    //   GET  /api/health    — Health check
    //
    // TODO (ARCH-005): Extract this HTTP API handling into a separate
    // InternalHttpHandler class to improve separation of concerns.
    // The WebSocket server class should only manage socket lifecycle.
    // -------------------------------------------------------------------------

    /**
     * Check whether the HTTP request is a WebSocket upgrade.
     */
    private function isWebSocketUpgrade(RequestInterface $request): bool
    {
        $upgrade = strtolower($request->getHeaderLine('Upgrade'));

        return $upgrade === 'websocket';
    }

    /**
     * Handle a plain HTTP request (non-WebSocket).
     *
     * Routes to internal API endpoints for inter-process communication.
     * Validates Bearer token authentication on protected endpoints.
     */
    private function handleHttpRequest(ConnectionInterface $tcpConnection, RequestInterface $request): void
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        // Health check — no auth required.
        // SEC-011: Return only a minimal status string — do not include connection count
        // or other operational metrics. The connection count is low-value intelligence
        // on its own but could be used to profile usage patterns if the port is exposed.
        if ($method === 'GET' && $path === '/api/health') {
            $this->httpResponse($tcpConnection, 200, [
                'status' => 'ok',
            ]);

            return;
        }

        // Validate Bearer token for protected endpoints
        $authHeader = $request->getHeaderLine('Authorization');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            $this->httpResponse($tcpConnection, 401, [
                'error' => 'missing_token',
                'message' => 'Authorization header with Bearer token required.',
            ]);

            return;
        }

        $token = substr($authHeader, 7);

        try {
            // SEC-002: Require 'internal_relay' scope so user-facing bridge tokens
            // cannot be used to call the internal HTTP API.
            $decoded = $this->tokenManager->validate($token, 'internal_relay');
        } catch (\Throwable $e) {
            $this->httpResponse($tcpConnection, 401, [
                'error' => 'invalid_token',
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // Route to endpoints
        match (true) {
            $method === 'GET' && $path === '/api/status' => $this->apiStatus($tcpConnection, $decoded),
            $method === 'POST' && $path === '/api/request' => $this->apiRequest($tcpConnection, $request, $decoded),
            default => $this->httpResponse($tcpConnection, 404, [
                'error' => 'not_found',
                'message' => "Unknown endpoint: {$method} {$path}",
            ]),
        };
    }

    /**
     * GET /api/status — Return connection status for the authenticated user only.
     *
     * SEC: Only shows the requesting user's own connection data, not all users.
     */
    private function apiStatus(ConnectionInterface $tcpConnection, object $decoded): void
    {
        $userId = (string) ($decoded->sub ?? '');

        if (empty($userId)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_subject',
                'message' => 'Token is missing the "sub" claim.',
            ]);

            return;
        }

        $connected = $this->connectionManager->hasConnection($userId);
        $response = [
            'user_id' => $userId,
            'connected' => $connected,
        ];

        if ($connected) {
            $data = $this->connectionManager->getConnection($userId);
            $providers = $this->connectionManager->getProviders($userId);

            $response['connection_id'] = $data['connection_id'] ?? null;
            $response['connected_at'] = $data['connected_at'] ?? null;
            $response['providers'] = $providers;
        }

        $this->httpResponse($tcpConnection, 200, $response);
    }

    /**
     * POST /api/request — Send an ai_request to a user's connected bridge.
     *
     * Expected body:
     * {
     *   "provider": "claude",
     *   "message": "Hello!",
     *   "conversation_id": "conv-001",
     *   "system_prompt": "You are helpful.",
     *   "options": { "max_tokens": 100 }
     * }
     *
     * The user_id is derived from the JWT sub claim — never from the request body.
     */
    private function apiRequest(ConnectionInterface $tcpConnection, RequestInterface $request, object $decoded): void
    {
        $rawBody = (string) $request->getBody();
        $body = json_decode($rawBody, true);

        if (! is_array($body)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'invalid_body',
                'message' => 'Request body must be valid JSON.',
            ]);

            return;
        }

        // SEC: user_id is always derived from the JWT sub claim, not the request body.
        // This prevents users from impersonating other users' bridge connections.
        $userId = (string) ($decoded->sub ?? '');
        $provider = $body['provider'] ?? '';
        $message = $body['message'] ?? '';
        $conversationId = $body['conversation_id'] ?? '';

        // Only 'message' is required. 'provider' is optional routing metadata —
        // the bridge client can fall back to its configured default when omitted.
        if (empty($message)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_fields',
                'message' => 'Field "message" is required.',
            ]);

            return;
        }

        if (! $this->connectionManager->hasConnection($userId)) {
            // SEC: Don't leak connected user IDs in error responses
            $this->httpResponse($tcpConnection, 404, [
                'error' => 'bridge_not_connected',
                'message' => 'No active bridge connection for this user.',
            ]);

            return;
        }

        // Build ai_request payload — use the request_id from the relay body if provided,
        // to preserve the original request_id for event routing back to the caller.
        // SEC-005: Validate that a caller-supplied request_id is not already registered
        // as a pending request owned by a DIFFERENT user. If it is, generate a new one
        // to prevent stream event hijacking.
        $callerRequestId = ! empty($body['request_id']) ? $body['request_id'] : null;
        if ($callerRequestId !== null) {
            $pendingOwner = $this->connectionManager->getPendingRequestUserId($callerRequestId);
            if ($pendingOwner !== null && $pendingOwner !== $userId) {
                // The supplied request_id is owned by another user — generate a fresh one
                Log::warning('AI Bridge: caller supplied a request_id owned by a different user, generating new one', [
                    'supplied_request_id' => $callerRequestId,
                    'caller_user_id' => $userId,
                    'owner_user_id' => $pendingOwner,
                ]);
                $callerRequestId = null;
            }
        }
        $requestId = $callerRequestId ?? 'req-' . bin2hex(random_bytes(8));
        $payload = [
            'type' => 'ai_request',
            'request_id' => $requestId,
            'provider' => $provider,
            'conversation_id' => $conversationId,
            'message' => $message,
            'options' => $body['options'] ?? [],
        ];

        if (isset($body['system_prompt'])) {
            $payload['system_prompt'] = $body['system_prompt'];
        }

        if (isset($body['messages'])) {
            $payload['messages'] = $body['messages'];
        }

        // Forward tools from relay body so the bridge knows which tools are available
        $payload['tools'] = $body['tools'] ?? [];

        $sent = $this->connectionManager->sendToUser($userId, $payload);

        if (! $sent) {
            $this->httpResponse($tcpConnection, 500, [
                'error' => 'send_failed',
                'message' => 'Failed to send ai_request to bridge.',
            ]);

            return;
        }

        $this->httpResponse($tcpConnection, 200, [
            'ok' => true,
            'request_id' => $requestId,
            'user_id' => $userId,
            'provider' => $provider,
        ]);
    }

    /**
     * Send an HTTP JSON response on the TCP connection and close it.
     *
     * @param  array<string, mixed>  $data
     */
    private function httpResponse(ConnectionInterface $tcpConnection, int $statusCode, array $data): void
    {
        $statusTexts = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 500 => 'Internal Server Error'];
        $statusText = $statusTexts[$statusCode] ?? 'Unknown';

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $length = strlen($json);

        $response = "HTTP/1.1 {$statusCode} {$statusText}\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: {$length}\r\n" .
            "Connection: close\r\n" .
            "\r\n" .
            $json;

        $tcpConnection->write($response);
        $tcpConnection->end();
    }
}
