<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Protocol;

use Tetrix\AiBridge\Enums\BlockType;

/**
 * Immutable value object representing a single event in an AI response stream.
 *
 * StreamEvents are the unified currency of the streaming interface — regardless
 * of whether the response comes from a CLI bridge, BYOK, or managed provider,
 * consuming code always receives StreamEvent instances.
 */
final class StreamEvent
{
    public function __construct(
        /** Unique identifier for the AI request that produced this event. */
        public readonly string $requestId,
        /** Event type: block_start, block_delta, block_stop, done, error, tool_call. */
        public readonly string $event,
        /** Event-specific payload data. */
        public readonly array $data,
    ) {}

    /**
     * Create a block_start event.
     */
    public static function blockStart(string $requestId, BlockType $blockType, int $blockIndex): self
    {
        return new self($requestId, MessageTypes::BLOCK_START, [
            'block_type' => $blockType->value,
            'block_index' => $blockIndex,
        ]);
    }

    /**
     * Create a block_delta event.
     */
    public static function blockDelta(string $requestId, BlockType $blockType, int $blockIndex, string $content): self
    {
        return new self($requestId, MessageTypes::BLOCK_DELTA, [
            'block_type' => $blockType->value,
            'block_index' => $blockIndex,
            'content' => $content,
        ]);
    }

    /**
     * Create a block_stop event.
     */
    public static function blockStop(string $requestId, BlockType $blockType, int $blockIndex): self
    {
        return new self($requestId, MessageTypes::BLOCK_STOP, [
            'block_type' => $blockType->value,
            'block_index' => $blockIndex,
        ]);
    }

    /**
     * Create a tool_call event.
     */
    public static function toolCall(string $requestId, string $toolName, array $params, string $callId): self
    {
        return new self($requestId, MessageTypes::TOOL_CALL, [
            'tool_name' => $toolName,
            'parameters' => $params,
            'call_id' => $callId,
        ]);
    }

    /**
     * Create a done event.
     */
    public static function done(string $requestId, ?array $usage = null): self
    {
        return new self($requestId, MessageTypes::DONE, [
            'usage' => $usage,
        ]);
    }

    /**
     * Create an error event.
     */
    public static function error(string $requestId, string $code, string $message): self
    {
        return new self($requestId, MessageTypes::ERROR, [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * Get the block type from the event data, if applicable.
     */
    public function blockType(): ?BlockType
    {
        if (! isset($this->data['block_type'])) {
            return null;
        }

        return BlockType::tryFrom($this->data['block_type']);
    }

    /**
     * Serialize the event to an array suitable for JSON encoding or WebSocket transmission.
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'type' => $this->event,
            'data' => $this->data,
        ];
    }

    /**
     * Create a StreamEvent from an incoming array (e.g. decoded WebSocket message).
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            requestId: $payload['request_id'] ?? '',
            event: $payload['type'] ?? '',
            data: $payload['data'] ?? [],
        );
    }
}
