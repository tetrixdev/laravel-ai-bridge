<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Protocol;

/**
 * All protocol message type constants for the AI Bridge Protocol v0.1.
 *
 * These constants define the message types exchanged between the server
 * (this package) and CLI bridge clients over WebSocket.
 *
 * This is intentionally a constants class rather than a backed enum because the
 * all() / bridgeOrigin() / serverOrigin() grouping methods require
 * runtime-composable arrays that backed enums cannot express cleanly.
 */
final class MessageTypes
{
    /** @var string[]|null Cached result of all() — populated once on first call. */
    private static ?array $allCache = null;
    // ── Connection Lifecycle ────────────────────────────────────────────

    /** Sent by bridge immediately after WebSocket upgrade. */
    public const HELLO = 'hello';

    /** Sent by server in response to a valid hello. */
    public const WELCOME = 'welcome';

    /** Sent by server when hello validation fails. */
    public const CONNECTION_ERROR = 'connection_error';

    // ── Heartbeat ───────────────────────────────────────────────────────

    /** Sent by bridge to check server liveness. */
    public const PING = 'ping';

    /** Sent by server in response to ping. */
    public const PONG = 'pong';

    // ── AI Request / Response ───────────────────────────────────────────

    /** Sent by server to request an AI completion from the bridge. */
    public const AI_REQUEST = 'ai_request';

    /** Sent by bridge to acknowledge receipt of an ai_request. */
    public const AI_REQUEST_ACK = 'ai_request_ack';

    /**
     * Sent by server to replay conversation history after a lost session.
     *
     * TODO: server-side implementation is not yet complete — no code sends this
     * message, and reconnected bridges currently start fresh rather than
     * replaying history.
     */
    public const SESSION_RESET = 'session_reset';

    // ── Streaming ───────────────────────────────────────────────────────

    /**
     * The envelope type for all streaming events from bridge to server.
     *
     * Streaming events are wrapped in: { "type": "stream", "event": "<event_type>", ... }
     * The individual event types (block_start, block_delta, etc.) appear in the "event" field.
     */
    public const STREAM = 'stream';

    /** Stream event: a new content block has started. */
    public const BLOCK_START = 'block_start';

    /** Stream event: a delta (chunk) within a content block. */
    public const BLOCK_DELTA = 'block_delta';

    /** Stream event: a content block has ended. */
    public const BLOCK_STOP = 'block_stop';

    /** Stream event: the AI wants to call a tool. */
    public const TOOL_CALL = 'tool_call';

    /**
     * Stream event (bridge → server): acknowledges receipt of a tool result.
     *
     * This is a streaming event sent by the bridge inside the "stream" envelope
     * to confirm it received a tool_resolve message and passed the result to the CLI.
     */
    public const TOOL_RESULT = 'tool_result';

    /**
     * Server → bridge: sends the result of a tool execution back to the bridge.
     *
     * This is a top-level message type (NOT inside a stream envelope).
     * The bridge receives this, passes the result to the CLI, and sends back
     * a stream event with event: "tool_result" to acknowledge.
     */
    public const TOOL_RESOLVE = 'tool_resolve';

    /**
     * Server → bridge: a tool execution failed.
     *
     * Sent when the server fails to execute a tool the AI requested.
     */
    public const TOOL_ERROR = 'tool_error';

    /** Stream event: the entire AI response is complete. */
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
     * Result is cached in a static property so the array is not reconstructed
     * on every incoming WebSocket message.
     *
     * @return string[]
     */
    public static function all(): array
    {
        if (self::$allCache !== null) {
            return self::$allCache;
        }

        return self::$allCache = [
            self::HELLO,
            self::WELCOME,
            self::CONNECTION_ERROR,
            self::PING,
            self::PONG,
            self::AI_REQUEST,
            self::AI_REQUEST_ACK,
            self::SESSION_RESET,
            self::STREAM,
            self::BLOCK_START,
            self::BLOCK_DELTA,
            self::BLOCK_STOP,
            self::TOOL_CALL,
            self::TOOL_RESULT,
            self::TOOL_RESOLVE,
            self::TOOL_ERROR,
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
     * Per PROTOCOL.md:
     * - hello: after WebSocket connects
     * - ping: every heartbeat interval (bridge pings, server pongs)
     * - ai_request_ack: after receiving an ai_request
     * - stream: envelope for all streaming events (block_start, block_delta, etc.)
     * - tool_call: AI wants to invoke a server-side tool
     * - error: request-level error (non-streaming)
     * - cancelled: acknowledges cancellation
     *
     * @return string[]
     */
    public static function bridgeOrigin(): array
    {
        return [
            self::HELLO,
            self::PING,
            self::AI_REQUEST_ACK,
            self::STREAM,
            self::TOOL_CALL,
            self::ERROR,
            self::CANCELLED,
        ];
    }

    /**
     * Message types that are sent by the server (server → bridge).
     *
     * Per PROTOCOL.md:
     * - welcome: after receiving hello
     * - pong: after receiving ping
     * - ai_request: new AI request for a conversation
     * - session_reset: replay conversation after lost session
     * - tool_resolve: returning tool execution result to bridge
     * - tool_error: tool execution failed
     * - cancel: cancel an in-progress request
     *
     * @return string[]
     */
    public static function serverOrigin(): array
    {
        return [
            self::WELCOME,
            self::PONG,
            self::AI_REQUEST,
            self::SESSION_RESET,
            self::TOOL_RESOLVE,
            self::TOOL_ERROR,
            self::CANCEL,
        ];
    }
}
