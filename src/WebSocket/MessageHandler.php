<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\WebSocket;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Auth\TokenValidationException;
use Tetrix\AiBridge\Enums\BlockType;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;
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
            default => null,
        };
    }

    /**
     * Handle a 'hello' message — authenticate the bridge connection.
     *
     * Expected message format:
     *   { "type": "hello", "token": "<JWT>", "version": "0.1", ... }
     *
     * @return array<string, mixed>  Welcome or connection_error response.
     */
    private function handleHello(string $connectionId, mixed $connection, array $message): array
    {
        $token = $message['token'] ?? '';
        $protocolVersion = $message['version'] ?? $message['protocol_version'] ?? 'unknown';

        if (empty($token)) {
            return [
                'type' => MessageTypes::CONNECTION_ERROR,
                'error' => 'missing_token',
                'message' => 'Hello message must include a "token" field.',
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

        // Register the connection
        $this->connectionManager->addConnection($userId, $connectionId, $connection);

        Log::info('AI Bridge: bridge connected', [
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'protocol_version' => $protocolVersion,
        ]);

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

        $event = StreamEvent::fromArray($message);
        $handler->dispatchEvent($event);

        return null;
    }

    /**
     * Handle a tool_call event from within a stream envelope.
     *
     * The bridge is relaying the AI's request to execute a tool.
     * We execute it locally and send back a tool_resolve message.
     */
    private function handleToolCallFromStream(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $data = $message['data'] ?? [];
        $toolName = $data['tool_name'] ?? '';
        $params = $data['parameters'] ?? [];
        $callId = $data['tool_call_id'] ?? $data['call_id'] ?? '';

        $handler = $this->connectionManager->getPendingRequest($requestId);

        // Dispatch to the StreamHandler's tool call callbacks
        if ($handler) {
            $handler->dispatchToolCall($toolName, $params, $callId);
        }

        // Execute the tool if registered
        if ($this->toolRegistry->has($toolName)) {
            try {
                $result = $this->toolRegistry->execute($toolName, $params);

                return [
                    'type' => MessageTypes::TOOL_RESOLVE,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                Log::error('AI Bridge: tool execution failed', [
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'type' => MessageTypes::TOOL_ERROR,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'error' => $e->getMessage(),
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
     * Handle a 'tool_call' message from the bridge (top-level, non-envelope format).
     *
     * The bridge is relaying the AI's request to execute a tool.
     * We execute it locally and send back a tool_resolve message.
     */
    private function handleToolCall(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $toolName = $message['data']['tool_name'] ?? $message['tool_name'] ?? '';
        $params = $message['data']['parameters'] ?? $message['parameters'] ?? [];
        $callId = $message['data']['tool_call_id'] ?? $message['data']['call_id'] ?? $message['call_id'] ?? '';

        $handler = $this->connectionManager->getPendingRequest($requestId);

        // Dispatch to the StreamHandler's tool call callbacks
        if ($handler) {
            $handler->dispatchToolCall($toolName, $params, $callId);
        }

        // Execute the tool if registered
        if ($this->toolRegistry->has($toolName)) {
            try {
                $result = $this->toolRegistry->execute($toolName, $params);

                return [
                    'type' => MessageTypes::TOOL_RESOLVE,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                Log::error('AI Bridge: tool execution failed', [
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'type' => MessageTypes::TOOL_ERROR,
                    'request_id' => $requestId,
                    'tool_call_id' => $callId,
                    'error' => $e->getMessage(),
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
        if ($handler) {
            $handler->dispatchDone($usage);
            $this->connectionManager->removePendingRequest($requestId);
        }

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
        $errorMessage = $data['message'] ?? 'Unknown error';

        Log::error('AI Bridge: stream error from bridge', [
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
     * Handle an 'error' message from the bridge (top-level, non-streaming).
     */
    private function handleError(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $code = $message['data']['code'] ?? $message['code'] ?? $message['error'] ?? 'unknown';
        $errorMessage = $message['data']['message'] ?? $message['message'] ?? 'Unknown error';

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
     */
    private function handleCancelled(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        Log::info('AI Bridge: request cancelled', [
            'connection_id' => $connectionId,
            'request_id' => $requestId,
        ]);

        $this->connectionManager->removePendingRequest($requestId);

        return null;
    }

    /**
     * Generate a pong message to send in response to a bridge ping.
     *
     * @deprecated Use handlePing() instead which returns the pong automatically.
     * @return array<string, mixed>
     */
    public function buildPongMessage(?int $timestamp = null): array
    {
        return [
            'type' => MessageTypes::PONG,
            'timestamp' => $timestamp ?? time(),
        ];
    }
}
