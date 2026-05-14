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
            MessageTypes::PONG => $this->handlePong($connectionId, $message),
            MessageTypes::BLOCK_START => $this->handleStreamEvent($connectionId, $message),
            MessageTypes::BLOCK_DELTA => $this->handleStreamEvent($connectionId, $message),
            MessageTypes::BLOCK_STOP => $this->handleStreamEvent($connectionId, $message),
            MessageTypes::TOOL_CALL => $this->handleToolCall($connectionId, $message),
            MessageTypes::DONE => $this->handleDone($connectionId, $message),
            MessageTypes::ERROR => $this->handleError($connectionId, $message),
            MessageTypes::CANCELLED => $this->handleCancelled($connectionId, $message),
            default => null,
        };
    }

    /**
     * Handle a 'hello' message — authenticate the bridge connection.
     *
     * Expected message format:
     *   { "type": "hello", "token": "<JWT>", "protocol_version": "0.1" }
     *
     * @return array<string, mixed>  Welcome or connection_error response.
     */
    private function handleHello(string $connectionId, mixed $connection, array $message): array
    {
        $token = $message['token'] ?? '';
        $protocolVersion = $message['protocol_version'] ?? 'unknown';

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
            'connection_id' => $connectionId,
            'heartbeat_interval' => config('ai-bridge.websocket.heartbeat_interval', 30),
            'tools' => $this->toolRegistry->toArray(),
        ];
    }

    /**
     * Handle a 'pong' message — bridge responding to our ping.
     */
    private function handlePong(string $connectionId, array $message): ?array
    {
        // Pong received — the connection is alive. In a full implementation,
        // this would reset a heartbeat timeout timer.
        Log::debug('AI Bridge: pong received', ['connection_id' => $connectionId]);

        return null;
    }

    /**
     * Handle stream events (block_start, block_delta, block_stop).
     *
     * Routes the event to the appropriate StreamHandler based on request_id.
     */
    private function handleStreamEvent(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';

        if (empty($requestId)) {
            Log::warning('AI Bridge: stream event missing request_id', [
                'connection_id' => $connectionId,
                'type' => $message['type'],
            ]);

            return null;
        }

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if (! $handler) {
            Log::debug('AI Bridge: received stream event for unknown request', [
                'request_id' => $requestId,
                'type' => $message['type'],
            ]);

            return null;
        }

        $event = StreamEvent::fromArray($message);
        $handler->dispatchEvent($event);

        return null;
    }

    /**
     * Handle a 'tool_call' message from the bridge.
     *
     * The bridge is relaying the AI's request to execute a tool.
     * We execute it locally and send back a tool_result.
     */
    private function handleToolCall(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $toolName = $message['data']['tool_name'] ?? $message['tool_name'] ?? '';
        $params = $message['data']['parameters'] ?? $message['parameters'] ?? [];
        $callId = $message['data']['call_id'] ?? $message['call_id'] ?? '';

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
                    'type' => MessageTypes::TOOL_RESULT,
                    'request_id' => $requestId,
                    'call_id' => $callId,
                    'result' => $result,
                    'is_error' => false,
                ];
            } catch (\Throwable $e) {
                Log::error('AI Bridge: tool execution failed', [
                    'tool' => $toolName,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'type' => MessageTypes::TOOL_RESULT,
                    'request_id' => $requestId,
                    'call_id' => $callId,
                    'result' => ['error' => $e->getMessage()],
                    'is_error' => true,
                ];
            }
        }

        Log::warning('AI Bridge: tool not registered', ['tool' => $toolName]);

        return [
            'type' => MessageTypes::TOOL_RESULT,
            'request_id' => $requestId,
            'call_id' => $callId,
            'result' => ['error' => "Tool '{$toolName}' is not registered."],
            'is_error' => true,
        ];
    }

    /**
     * Handle a 'done' message — the AI response stream is complete.
     */
    private function handleDone(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $usage = $message['data']['usage'] ?? $message['usage'] ?? null;

        $handler = $this->connectionManager->getPendingRequest($requestId);
        if ($handler) {
            $handler->dispatchDone($usage);
            $this->connectionManager->removePendingRequest($requestId);
        }

        return null;
    }

    /**
     * Handle an 'error' message from the bridge.
     */
    private function handleError(string $connectionId, array $message): ?array
    {
        $requestId = $message['request_id'] ?? '';
        $code = $message['data']['code'] ?? $message['error'] ?? 'unknown';
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
     * Generate a ping message to send to a bridge connection.
     *
     * @return array<string, mixed>
     */
    public function buildPingMessage(): array
    {
        return [
            'type' => MessageTypes::PING,
            'timestamp' => time(),
        ];
    }
}
