<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\WebSocket;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Auth\TokenValidationException;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Streaming\RelayStream;
use Tetrix\AiBridge\Tools\ToolRegistry;

/**
 * Handles incoming WebSocket messages from CLI bridge clients.
 *
 * This class processes the AI Bridge Protocol messages and routes them
 * appropriately — hello messages trigger authentication, stream events
 * are dispatched to the appropriate StreamHandler, etc.
 *
 * Usage by the consuming app's WebSocket server:
 *   $handler = app(MessageHandler::class);
 *   $handler->handleMessage($connectionId, $connection, $rawJson);
 */
class MessageHandler
{
    public function __construct(
        private readonly BridgeConnectionManager $connectionManager,
        private readonly TokenManager $tokenManager,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    /**
     * Register a relayed (PHP-FPM) request as pending in the serve process.
     *
     * Requests issued under PHP-FPM are relayed to the bridge via the internal
     * HTTP API; their response events arrive at this separate serve process. The
     * PHP-FPM worker that issued the request never sees those events, so without
     * a pending request here tool calls would be rejected (no recorded owner) and
     * stream events would be dropped ("unknown request").
     *
     * This wires a RelayStream whose StreamHandler re-broadcasts every event via
     * AiStreamEvent on "user.{userId}.conversation.{conversationId}" — the same
     * channel convention StreamController::broadcast() uses — so the browser
     * receives stream events and tool calls verify+execute against a real owner.
     *
     * Cleanup is handled by the existing done/error/cancelled handlers, which
     * already call removePendingRequest().
     */
    public function registerRelayedRequest(string $requestId, string $userId, string $conversationId): void
    {
        $channel = "user.{$userId}.conversation.{$conversationId}";

        $relay = new RelayStream($requestId, $channel, $conversationId);

        $this->connectionManager->registerPendingRequest(
            $requestId,
            $relay->getStreamHandler(),
            $userId,
        );
    }

    /**
     * Handle a raw incoming WebSocket message.
     *
     * @param  string  $connectionId  Unique identifier for this WebSocket connection.
     * @param  mixed  $connection  The underlying WebSocket connection object.
     * @param  string  $rawMessage  The raw JSON message string.
     * @return array<string, mixed>|null  Response message to send back, or null.
     */
    public function handleMessage(string $connectionId, mixed $connection, string $rawMessage): ?array
    {
        $message = json_decode($rawMessage, true);

        if (! is_array($message) || ! isset($message['type'])) {
            Log::warning('AI Bridge: received invalid WebSocket message', [
                'connection_id' => $connectionId,
                'raw' => substr($rawMessage, 0, 500),
            ]);

            return [
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => 'invalid_message',
                'message' => 'Message must be valid JSON with a "type" field.',
            ];
        }

        $type = $message['type'];

        if (! MessageTypes::isValid($type)) {
            Log::warning('AI Bridge: received unknown message type', [
                'connection_id' => $connectionId,
                'type' => $type,
            ]);

            return null;
        }

        return match ($type) {
            MessageTypes::HELLO => $this->handleHello($connectionId, $connection, $message),
            MessageTypes::PING => $this->handlePing($connectionId, $message),
            MessageTypes::AI_REQUEST_ACK => $this->handleAiRequestAck($connectionId, $message),
            MessageTypes::STREAM => $this->handleStreamEnvelope($connectionId, $message),
            MessageTypes::TOOL_CALL => $this->handleToolCall($connectionId, $message),
            MessageTypes::ERROR => $this->handleError($connectionId, $message),
            MessageTypes::CANCELLED => $this->handleCancelled($connectionId, $message),
            MessageTypes::SESSION_RESET => $this->handleSessionReset($connectionId, $message),
            default => null,
        };
    }

    /**
     * Handle a 'session_reset' message from the bridge.
     *
     * session_reset is a valid protocol message type, but server-side history
     * replay is not yet implemented. Return a clear 'not_implemented' error
     * rather than silently discarding the message so clients do not hang.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function handleSessionReset(string $connectionId, array $message): array
    {
        Log::info('AI Bridge: received session_reset — feature not implemented', [
            'connection_id' => $connectionId,
        ]);

        return [
            'type' => MessageTypes::CONNECTION_ERROR,
            'error' => 'not_implemented',
            'message' => 'session_reset is not yet supported by this server. '
                .'Reconnecting bridges should start a fresh session; conversation history replay is not available.',
            'request_id' => $message['request_id'] ?? null,
        ];
    }

    /**
     * Handle a 'hello' message — complete the protocol handshake.
     *
     * Authentication can happen in two ways:
     * 1. Pre-authenticated: The dedicated BridgeWebSocketServer validates the JWT
     *    at connection time (via ?token= URL param). The connection is already
     *    registered in BridgeConnectionManager before the hello arrives.
     * 2. Token in hello body: For custom WebSocket integrations where authentication
     *    happens via the hello message instead. Falls back to this if not pre-authed.
     *
     * @return array<string, mixed>  Welcome or connection_error response.
     */
    private function handleHello(string $connectionId, mixed $connection, array $message): array
    {
        $protocolVersion = $message['version'] ?? $message['protocol_version'] ?? 'unknown';

        // Validate protocol version compatibility — reject incompatible major
        // versions. Minor version differences are allowed (additive changes).
        $supportedMajorVersion = 0; // Current protocol is v0.1
        if ($protocolVersion !== 'unknown') {
            $versionParts = explode('.', ltrim($protocolVersion, 'v'));
            $clientMajor = (int) ($versionParts[0] ?? 0);
            if ($clientMajor !== $supportedMajorVersion) {
                Log::warning('AI Bridge: protocol version mismatch', [
                    'connection_id' => $connectionId,
                    'client_version' => $protocolVersion,
                    'supported_major' => $supportedMajorVersion,
                ]);

                return [
                    'type' => MessageTypes::CONNECTION_ERROR,
                    'error' => 'protocol_version_mismatch',
                    'message' => "Incompatible protocol version: server supports major version {$supportedMajorVersion}, client sent {$protocolVersion}. Please update your bridge client.",
                ];
            }
        }

        // Check if this connection was already authenticated at connect time
        // (e.g. by BridgeWebSocketServer via ?token= URL param)
        $existingUserId = $this->connectionManager->getUserIdByConnectionId($connectionId);

        if ($existingUserId !== null) {
            // Already authenticated — store providers from hello and return welcome
            $providers = $message['providers'] ?? [];
            $this->connectionManager->setProviders($existingUserId, $providers);

            $this->logBridgeConnection($existingUserId, $connectionId, $protocolVersion, $providers, 'pre-authenticated');

            return $this->buildWelcomeResponse($connectionId);
        }

        // Not pre-authenticated — require token in the hello body
        $token = $message['token'] ?? '';

        if (empty($token)) {
            return [
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => 'missing_token',
                'message' => 'Hello message must include a "token" field (or authenticate via ?token= URL param).',
            ];
        }

        try {
            $decoded = $this->tokenManager->validate($token);
            $userId = (string) $decoded->sub;
        } catch (TokenValidationException $e) {
            Log::info('AI Bridge: bridge authentication failed', [
                'connection_id' => $connectionId,
                'error_code' => $e->errorCode,
            ]);

            return [
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ];
        }

        // Register the connection with provider capabilities
        $providers = $message['providers'] ?? [];
        $this->connectionManager->addConnection($userId, $connectionId, $connection, $providers);

        $this->logBridgeConnection($userId, $connectionId, $protocolVersion, $providers, 'connected');

        return $this->buildWelcomeResponse($connectionId);
    }

    /**
     * Build the standard welcome response sent after successful handshake.
     */
    private function buildWelcomeResponse(string $connectionId): array
    {
        return [
            'type' => MessageTypes::WELCOME,
            'session_id' => $connectionId,
            'tools' => $this->toolRegistry->toArray(),
            'config' => [
                'heartbeat_interval' => (int) config('ai-bridge.websocket.heartbeat_interval', 30),
                'request_timeout' => (int) config('ai-bridge.websocket.request_timeout', 300),
            ],
        ];
    }

    /**
     * Log a bridge connection event with provider details.
     */
    private function logBridgeConnection(string $userId, string $connectionId, string $protocolVersion, array $providers, string $label): void
    {
        $availableProviders = array_filter($providers, fn ($p) => ($p['available'] ?? false));
        Log::info("AI Bridge: bridge {$label}", [
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'protocol_version' => $protocolVersion,
            'providers' => array_map(fn ($p) => $p['name'] ?? 'unknown', $availableProviders),
        ]);
    }

    /**
     * Handle a 'ping' message — bridge checking server liveness.
     *
     * Per PROTOCOL.md, the bridge sends ping and the server responds with pong.
     */
    private function handlePing(string $connectionId, array $message): ?array
    {
        Log::debug('AI Bridge: ping received', ['connection_id' => $connectionId]);

        return [
            'type' => MessageTypes::PONG,
            'timestamp' => $message['timestamp'] ?? time(),
        ];
    }

    /**
     * Handle an 'ai_request_ack' message — bridge acknowledges receipt of ai_request.
     */
    private function handleAiRequestAck(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $cliSessionId = $message['cli_session_id'] ?? null;

        Log::debug('AI Bridge: ai_request acknowledged', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
            'cli_session_id' => $cliSessionId,
        ]);

        return null;
    }

    /**
     * Handle a 'stream' envelope message from the bridge.
     *
     * Per PROTOCOL.md, all streaming events arrive in an envelope:
     *   { "type": "stream", "request_id": "...", "event": "<event_type>", "data": {...} }
     *
     * The "event" field contains the actual event type (block_start, block_delta,
     * block_stop, tool_result, done, error, tool_call).
     */
    private function handleStreamEnvelope(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $eventType = $message['event'] ?? '';

        if (empty($requestId)) {
            Log::warning('AI Bridge: stream envelope missing request_id', [
                'connection_id' => $connectionId,
            ]);

            return null;
        }

        if (empty($eventType)) {
            Log::warning('AI Bridge: stream envelope missing event field', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        // Handle tool_call events inside the stream envelope specially
        if ($eventType === MessageTypes::TOOL_CALL) {
            return $this->handleToolCallFromStream($connectionId, $message);
        }

        // Handle done events — need to clean up pending request
        if ($eventType === MessageTypes::DONE) {
            return $this->handleDoneFromStream($connectionId, $message);
        }

        // Handle error events inside stream — need to clean up pending request
        if ($eventType === MessageTypes::ERROR) {
            return $this->handleErrorFromStream($connectionId, $message);
        }

        // For block_start, block_delta, block_stop, tool_result — route to StreamHandler
        $handler = $this->connectionManager->getPendingRequest($requestId);
        if (! $handler) {
            Log::debug('AI Bridge: received stream event for unknown request', [
                'request_id' => $requestId,
                'event' => $eventType,
            ]);

            return null;
        }

        // Verify the sender owns the pending request. Fails closed: reject the
        // event if the sender is unregistered or does not own the request.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            $senderUserId = $this->connectionManager->getUserIdByConnectionId($connectionId);
            $requestUserId = $this->connectionManager->getPendingRequestUserId($requestId);
            Log::warning('AI Bridge: stream event from wrong or unregistered user, discarding', [
                'request_id' => $requestId,
                'sender_user_id' => $senderUserId,
                'request_user_id' => $requestUserId,
            ]);

            return null;
        }

        $event = StreamEvent::fromArray($message);
        $handler->dispatchEvent($event);

        return null;
    }

    /**
     * Handle a tool_call event from within a stream envelope.
     *
     * The bridge is relaying the AI's request to execute a tool.
     * We execute it locally and send back a tool_resolve message.
     *
     * Applies the same ownership check as top-level tool_call messages so a
     * bridge client cannot execute tools against a request it does not own.
     */
    private function handleToolCallFromStream(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        // SEC: Verify the sender owns this request before executing any tool.
        // Mirrors the check in handleToolCall() for top-level tool_call messages.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: stream-envelope tool_call from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        $data = $message['data'] ?? [];

        return $this->executeToolCall(
            $requestId,
            $data['tool_name'] ?? '',
            $data['parameters'] ?? [],
            $data['tool_call_id'] ?? $data['call_id'] ?? '',
        );
    }

    /**
     * Handle a 'tool_call' message from the bridge (top-level, non-envelope format).
     *
     * The bridge is relaying the AI's request to execute a tool.
     * We execute it locally and send back a tool_resolve message.
     */
    private function handleToolCall(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        // Apply the same ownership check as handleStreamEnvelope() so a bridge
        // client cannot execute tools registered for another user's request.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: tool_call from wrong user (top-level), discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        return $this->executeToolCall(
            $requestId,
            $message['data']['tool_name'] ?? $message['tool_name'] ?? '',
            $message['data']['parameters'] ?? $message['parameters'] ?? [],
            $message['data']['tool_call_id'] ?? $message['data']['call_id'] ?? $message['call_id'] ?? '',
        );
    }

    /**
     * Execute a tool call: dispatch to StreamHandler callbacks, run the tool, return response.
     *
     * Shared implementation for both stream-envelope and top-level tool_call messages.
     */
    private function executeToolCall(string $requestId, string $toolName, array $params, string $callId): ?array
    {
        $handler = $this->connectionManager->getPendingRequest($requestId);

        // If the request is no longer pending (stream already terminated),
        // return a tool_error to prevent the bridge from hanging.
        if (! $handler) {
            Log::warning('AI Bridge: tool call received for completed/unknown request', [
                'request_id' => $requestId,
                'tool' => $toolName,
            ]);

            return [
                'type' => MessageTypes::TOOL_ERROR,
                'request_id' => $requestId,
                'tool_call_id' => $callId,
                'error' => 'request_already_completed',
            ];
        }

        // Validate tool_name before dispatching so consuming-app callbacks are
        // never invoked with an empty tool name.
        if (empty($toolName)) {
            Log::warning('AI Bridge: tool call received with empty tool_name', [
                'request_id' => $requestId,
            ]);

            return [
                'type' => MessageTypes::TOOL_ERROR,
                'request_id' => $requestId,
                'tool_call_id' => $callId,
                'error' => 'Tool call received with empty tool_name — check the bridge client is sending a valid tool_name field.',
            ];
        }

        // Tool execution is synchronous and runs on the ReactPHP event loop
        // thread — a slow tool blocks all WebSocket events for every connected
        // user. Tool handlers registered via AiBridge::registerTool() must be
        // non-blocking when the bridge server runs under ReactPHP.

        // Dispatch to the StreamHandler's tool call callbacks
        $handler->dispatchToolCall($toolName, $params, $callId);

        // Execute the tool if registered
        if ($this->toolRegistry->has($toolName)) {
            try {
                $result = $this->toolRegistry->execute($toolName, $params);

                // Validate that the result is JSON-serializable
                if (json_encode($result) === false) {
                    Log::error('AI Bridge: tool result not JSON-serializable', [
                        'tool' => $toolName,
                        'json_error' => json_last_error_msg(),
                    ]);

                    return [
                        'type' => MessageTypes::TOOL_ERROR,
                        'request_id' => $requestId,
                        'tool_call_id' => $callId,
                        'error' => 'Tool execution failed',
                    ];
                }

                return [
                    'type' => MessageTypes::TOOL_RESOLVE,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                // Return a generic message to the client, log the full exception server-side.
                Log::error('AI Bridge: tool execution failed', [
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return [
                    'type' => MessageTypes::TOOL_ERROR,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'error' => 'Tool execution failed',
                ];
            }
        }

        Log::warning('AI Bridge: tool not registered', ['tool' => $toolName]);

        return [
            'type' => MessageTypes::TOOL_ERROR,
            'request_id' => $requestId,
            'tool_call_id' => $callId,
            'error' => "Tool '{$toolName}' is not registered.",
        ];
    }

    /**
     * Handle a 'done' event from within a stream envelope.
     */
    private function handleDoneFromStream(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $data = $message['data'] ?? [];
        $usage = $data['usage'] ?? null;

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if (! $handler) {
            Log::debug('AI Bridge: done event received for unknown request (expected in PHP-FPM relay mode)', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
                'usage' => $usage,
            ]);

            return null;
        }

        // Apply ownership check so a bridge cannot terminate another user's
        // request by sending a spoofed done envelope.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: done event from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        $handler->dispatchDone($usage);
        $this->connectionManager->removePendingRequest($requestId);

        return null;
    }

    /**
     * Handle an 'error' event from within a stream envelope.
     */
    private function handleErrorFromStream(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $data = $message['data'] ?? [];
        $code = $data['code'] ?? 'unknown';
        $rawMessage = $data['message'] ?? 'Unknown error';
        $errorMessage = mb_substr(strip_tags($rawMessage), 0, 500);

        Log::error('AI Bridge: stream error from bridge', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
            'code' => $code,
            'message' => $errorMessage,
        ]);

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if (! $handler) {
            return null;
        }

        // Apply ownership check so a bridge cannot terminate another user's
        // request by sending a spoofed error envelope.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: error event from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        $handler->dispatchError($code, $errorMessage);
        $this->connectionManager->removePendingRequest($requestId);

        return null;
    }

    /**
     * Handle an 'error' message from the bridge (top-level, non-streaming).
     *
     * Applies the same ownership check as the other terminal handlers so an
     * authenticated bridge client cannot abort another user's request.
     */
    private function handleError(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $code = $message['data']['code'] ?? $message['code'] ?? $message['error'] ?? 'unknown';
        $rawMessage = $message['data']['message'] ?? $message['message'] ?? 'Unknown error';
        $errorMessage = mb_substr(strip_tags($rawMessage), 0, 500);

        // Verify the sender owns this request before dispatching the error.
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: error message from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        Log::error('AI Bridge: error from bridge', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
            'code' => $code,
            'message' => $errorMessage,
        ]);

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if ($handler) {
            $handler->dispatchError($code, $errorMessage);
            $this->connectionManager->removePendingRequest($requestId);
        }

        return null;
    }

    /**
     * Handle a 'cancelled' message — bridge acknowledges cancellation.
     *
     * Dispatches a cancelled event to the StreamHandler so consumers (SSE, Reverb)
     * receive a terminal event and don't hang waiting for done.
     *
     * Applies the same ownership check as the other terminal handlers so a
     * bridge client cannot cancel another user's request.
     */
    private function handleCancelled(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        // SEC: Verify the sender owns this request before dispatching cancellation.
        // Mirrors the check in handleDoneFromStream() and handleErrorFromStream().
        if (! $this->verifySenderOwnsRequest($connectionId, $requestId)) {
            Log::warning('AI Bridge: cancelled message from wrong or unregistered user, discarding', [
                'connection_id' => $connectionId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        Log::info('AI Bridge: request cancelled', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
        ]);

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if ($handler) {
            $handler->dispatchCancelled('Request was cancelled.');
            $this->connectionManager->removePendingRequest($requestId);
        }

        return null;
    }

    /**
     * Verify that the connection identified by $connectionId owns the pending request.
     *
     * Returns true only when:
     *  - The connection is registered in BridgeConnectionManager (userId is non-null), AND
     *  - The pending request has a recorded owner (requestUserId is non-null), AND
     *  - Both userIds match.
     *
     * Fails CLOSED: any null value (unregistered connection or unknown request owner)
     * causes rejection rather than allowing the event through. This prevents partially-
     * authenticated connections from injecting events into any pending request.
     *
     * @param  string  $connectionId  The connection sending the event.
     * @param  string  $requestId     The request_id the event targets.
     */
    private function verifySenderOwnsRequest(string $connectionId, string $requestId): bool
    {
        $senderUserId = $this->connectionManager->getUserIdByConnectionId($connectionId);
        $requestUserId = $this->connectionManager->getPendingRequestUserId($requestId);

        // Both must be non-null and must match
        return $senderUserId !== null
            && $requestUserId !== null
            && $senderUserId === $requestUserId;
    }
}
