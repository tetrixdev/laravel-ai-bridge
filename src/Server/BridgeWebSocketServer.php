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

        $tcpConnection->on('data', function (string $data) use ($tcpConnection, $handler, &$httpBuffer, &$upgraded, &$headersComplete, &$expectedBodyLength, &$headerLength) {
            // If already upgraded, data is handled by the MessageBuffer (attached below).
            if ($upgraded) {
                return;
            }

            $httpBuffer .= $data;

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

            $this->upgradeConnection($tcpConnection, $request, $handler);
        });
    }

    /**
     * Attempt a WebSocket upgrade on the given TCP connection.
     *
     * If the handshake succeeds, writes the 101 response to the stream, creates
     * a BridgeConnection wrapper and MessageBuffer, then notifies the handler.
     */
    private function upgradeConnection(
        ConnectionInterface $tcpConnection,
        RequestInterface $request,
        BridgeWebSocketHandler $handler,
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

        $resourceId = $this->nextResourceId++;
        $queryString = $request->getUri()->getQuery();

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

        // Notify the handler that the WebSocket connection is open
        $handler->onOpen($bridgeConnection, $queryString);
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

        // Health check — no auth required
        if ($method === 'GET' && $path === '/api/health') {
            $this->httpResponse($tcpConnection, 200, [
                'status' => 'ok',
                'connections' => $this->connectionManager->connectionCount(),
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
            $decoded = $this->tokenManager->validate($token);
        } catch (\Throwable $e) {
            $this->httpResponse($tcpConnection, 401, [
                'error' => 'invalid_token',
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // Route to endpoints
        match (true) {
            $method === 'GET' && $path === '/api/status' => $this->apiStatus($tcpConnection),
            $method === 'POST' && $path === '/api/request' => $this->apiRequest($tcpConnection, $request, $decoded),
            default => $this->httpResponse($tcpConnection, 404, [
                'error' => 'not_found',
                'message' => "Unknown endpoint: {$method} {$path}",
            ]),
        };
    }

    /**
     * GET /api/status — Return connected users and their connection metadata.
     */
    private function apiStatus(ConnectionInterface $tcpConnection): void
    {
        $userIds = $this->connectionManager->connectedUserIds();
        $connections = [];

        foreach ($userIds as $userId) {
            $data = $this->connectionManager->getConnection($userId);
            $connections[] = [
                'user_id' => $userId,
                'connection_id' => $data['connection_id'] ?? null,
                'connected_at' => $data['connected_at'] ?? null,
            ];
        }

        $this->httpResponse($tcpConnection, 200, [
            'connections' => $connections,
            'count' => count($connections),
        ]);
    }

    /**
     * POST /api/request — Send an ai_request to a user's connected bridge.
     *
     * Expected body:
     * {
     *   "user_id": "1",
     *   "provider": "claude",
     *   "message": "Hello!",
     *   "conversation_id": "conv-001",
     *   "system_prompt": "You are helpful.",
     *   "options": { "max_tokens": 100 }
     * }
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

        // The user_id can be specified in the body, or default to the token's sub
        $userId = (string) ($body['user_id'] ?? $decoded->sub ?? '');
        $provider = $body['provider'] ?? '';
        $message = $body['message'] ?? '';
        $conversationId = $body['conversation_id'] ?? '';

        if (empty($provider) || empty($message)) {
            $this->httpResponse($tcpConnection, 400, [
                'error' => 'missing_fields',
                'message' => 'Fields "provider" and "message" are required.',
            ]);

            return;
        }

        if (! $this->connectionManager->hasConnection($userId)) {
            $this->httpResponse($tcpConnection, 404, [
                'error' => 'bridge_not_connected',
                'message' => "No active bridge connection for user '{$userId}'.",
                'connected_users' => $this->connectionManager->connectedUserIds(),
            ]);

            return;
        }

        // Build ai_request payload
        $requestId = 'req-' . bin2hex(random_bytes(8));
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
