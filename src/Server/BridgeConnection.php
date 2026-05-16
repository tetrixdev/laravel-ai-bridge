<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Server;

use React\Stream\WritableStreamInterface;
use Tetrix\AiBridge\Contracts\SendableConnection;

/**
 * Lightweight wrapper around a WebSocket connection stream.
 *
 * Replaces the Ratchet ConnectionInterface with a simple value object that
 * holds the underlying React stream and provides send/close helpers. Each
 * upgraded WebSocket connection gets its own BridgeConnection instance.
 *
 * Implements SendableConnection so BridgeConnectionManager can type-hint against
 * the interface rather than the concrete class, breaking the circular namespace
 * dependency between the WebSocket and Server namespaces.
 */
class BridgeConnection implements SendableConnection
{
    public function __construct(
        public readonly int $resourceId,
        private readonly WritableStreamInterface $stream,
        private readonly \Closure $sendCallback,
    ) {}

    /**
     * Send a text message (already-encoded string) to the client.
     */
    public function send(string $data): void
    {
        ($this->sendCallback)($data);
    }

    /**
     * Close the WebSocket connection.
     */
    public function close(): void
    {
        $this->stream->end();
    }
}
