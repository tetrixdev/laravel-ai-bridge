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
     * WebSocket close code signalling the connection was rejected — bad,
     * expired, revoked, or stale-cid token, or a deleted/regenerated bridge.
     * The bridge CLI treats this as fatal and stops reconnecting. Lives on
     * the interface so every site that closes for "your token is no good"
     * can reference the same name without re-importing BridgeConnection
     * (the interface exists precisely to avoid that namespace cycle).
     */
    public const CLOSE_INVALID_TOKEN = 4001;

    /**
     * Send a text message to the remote end.
     */
    public function send(string $data): void;

    /**
     * Close the connection, sending a WebSocket close frame with the given
     * status code (e.g. {@see self::CLOSE_INVALID_TOKEN} to signal an
     * invalid/rejected token).
     */
    public function close(int $code = 1000): void;
}
