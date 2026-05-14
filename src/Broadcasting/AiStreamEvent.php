<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Broadcasting event that carries AI stream data to Reverb.
 *
 * Each StreamEvent (block_start, block_delta, block_stop, done, error, tool_call)
 * is wrapped in this class and broadcast to the specified channel.
 *
 * Clients listen for the "ai.stream" event on the channel.
 */
class AiStreamEvent implements ShouldBroadcast
{
    /**
     * @param  string  $channelName  The channel to broadcast on (e.g. "game.123").
     * @param  string  $requestId  The unique request ID for this stream.
     * @param  string  $event  The stream event type (block_start, block_delta, etc.).
     * @param  array<string, mixed>  $data  The event payload data.
     */
    public function __construct(
        private readonly string $channelName,
        public readonly string $requestId,
        public readonly string $event,
        public readonly array $data,
    ) {}

    /**
     * Get the channel the event should broadcast on.
     *
     * @return Channel
     */
    public function broadcastOn(): Channel
    {
        return new Channel($this->channelName);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'ai.stream';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->requestId,
            'event' => $this->event,
            'data' => $this->data,
        ];
    }

    /**
     * Get the broadcast connection name.
     */
    public function broadcastConnection(): ?string
    {
        $connection = config('ai-bridge.broadcasting.connection');

        return is_string($connection) ? $connection : null;
    }
}
