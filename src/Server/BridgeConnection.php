<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Server;

use Ratchet\RFC6455\Messaging\Frame;
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
     *
     * Sends a proper RFC 6455 close frame carrying the status code before
     * ending the stream, so the client learns *why* it was disconnected.
     * Notably {@see SendableConnection::CLOSE_INVALID_TOKEN} signals an
     * invalid/rejected token — the bridge CLI treats that as fatal and
     * stops retrying.
     */
    public function close(int $code = 1000): void
    {
        $closeFrame = new Frame(pack('n', $code), opcode: Frame::OP_CLOSE);
        $this->stream->end($closeFrame->getContents());
    }
}
