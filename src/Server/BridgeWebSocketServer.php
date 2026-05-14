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

        $tcpConnection->on('data', function (string $data) use ($tcpConnection, $handler, &$httpBuffer, &$upgraded) {
            // If already upgraded, data is handled by the MessageBuffer (attached below).
            if ($upgraded) {
                return;
            }

            $httpBuffer .= $data;

            // Wait for the complete HTTP request (ends with \r\n\r\n)
            if (strpos($httpBuffer, "\r\n\r\n") === false) {
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
}
