<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Contracts;

/**
 * Abstraction for a WebSocket connection that can send text messages.
 *
 * Introduced to break the circular namespace dependency between
 * WebSocket\BridgeConnectionManager (which needed to know about
 * Server\BridgeConnection) and the Server namespace (which depends on
 * the WebSocket namespace). By type-hinting against this interface,
 * BridgeConnectionManager no longer needs to import Server\BridgeConnection.
 *
 * Implementors: Server\BridgeConnection
 */
interface SendableConnection
{
    /**
     * Send a text message to the remote end.
     */
    public function send(string $data): void;

    /**
     * Close the connection, sending a WebSocket close frame with the given
     * status code (e.g. 4001 to signal an invalid/rejected token).
     */
    public function close(int $code = 1000): void;
}
