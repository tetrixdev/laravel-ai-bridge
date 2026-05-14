<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Server;

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
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
 * Uses Ratchet/ReactPHP under the hood.
 */
class BridgeWebSocketServer
{
    private ?LoopInterface $loop = null;

    private ?IoServer $server = null;

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

        $handler = new BridgeWebSocketHandler(
            $this->connectionManager,
            $this->messageHandler,
            $this->tokenManager,
        );

        $wsServer = new WsServer($handler);
        $httpServer = new HttpServer($wsServer);

        $socket = new SocketServer("{$this->host}:{$this->port}", [], $this->loop);
        $this->server = new IoServer($httpServer, $socket, $this->loop);

        if ($onStart !== null) {
            // Schedule the callback to run after the loop starts
            $this->loop->futureTick($onStart);
        }

        $this->loop->run();
    }

    /**
     * Stop the server gracefully.
     */
    public function stop(): void
    {
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
