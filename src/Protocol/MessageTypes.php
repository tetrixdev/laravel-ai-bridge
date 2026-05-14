<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Protocol;

/**
 * All protocol message type constants for the AI Bridge Protocol v0.1.
 *
 * These constants define the message types exchanged between the server
 * (this package) and CLI bridge clients over WebSocket.
 */
final class MessageTypes
{
    // ── Connection Lifecycle ────────────────────────────────────────────

    /** Sent by bridge immediately after WebSocket upgrade. */
    public const HELLO = 'hello';

    /** Sent by server in response to a valid hello. */
    public const WELCOME = 'welcome';

    /** Sent by server when hello validation fails. */
    public const CONNECTION_ERROR = 'connection_error';

    // ── Heartbeat ───────────────────────────────────────────────────────

    /** Sent by server to check bridge liveness. */
    public const PING = 'ping';

    /** Sent by bridge in response to ping. */
    public const PONG = 'pong';

    // ── AI Request / Response ───────────────────────────────────────────

    /** Sent by server to request an AI completion from the bridge. */
    public const AI_REQUEST = 'ai_request';

    /** Sent by bridge: a new content block has started. */
    public const BLOCK_START = 'block_start';

    /** Sent by bridge: a delta (chunk) within a content block. */
    public const BLOCK_DELTA = 'block_delta';

    /** Sent by bridge: a content block has ended. */
    public const BLOCK_STOP = 'block_stop';

    /** Sent by bridge: the AI wants to call a tool. */
    public const TOOL_CALL = 'tool_call';

    /** Sent by server: the result of a tool execution. */
    public const TOOL_RESULT = 'tool_result';

    /** Sent by bridge: the entire AI response is complete. */
    public const DONE = 'done';

    /** Sent by bridge: an error occurred during AI processing. */
    public const ERROR = 'error';

    // ── Control ─────────────────────────────────────────────────────────

    /** Sent by server to cancel an in-progress AI request. */
    public const CANCEL = 'cancel';

    /** Sent by bridge to acknowledge a cancellation. */
    public const CANCELLED = 'cancelled';

    /**
     * All valid message types as an array, useful for validation.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::HELLO,
            self::WELCOME,
            self::CONNECTION_ERROR,
            self::PING,
            self::PONG,
            self::AI_REQUEST,
            self::BLOCK_START,
            self::BLOCK_DELTA,
            self::BLOCK_STOP,
            self::TOOL_CALL,
            self::TOOL_RESULT,
            self::DONE,
            self::ERROR,
            self::CANCEL,
            self::CANCELLED,
        ];
    }

    /**
     * Check if a given type is a valid message type.
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * Message types that are sent by the bridge (client → server).
     *
     * @return string[]
     */
    public static function bridgeOrigin(): array
    {
        return [
            self::HELLO,
            self::PONG,
            self::BLOCK_START,
            self::BLOCK_DELTA,
            self::BLOCK_STOP,
            self::TOOL_CALL,
            self::DONE,
            self::ERROR,
            self::CANCELLED,
        ];
    }

    /**
     * Message types that are sent by the server (server → bridge).
     *
     * @return string[]
     */
    public static function serverOrigin(): array
    {
        return [
            self::WELCOME,
            self::CONNECTION_ERROR,
            self::PING,
            self::AI_REQUEST,
            self::TOOL_RESULT,
            self::CANCEL,
        ];
    }
}
