<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcasting event that carries AI stream data to Reverb.
 *
 * Each StreamEvent (block_start, block_delta, block_stop, done, error, tool_call)
 * is wrapped in this class and broadcast to the specified channel.
 *
 * Uses ShouldBroadcastNow (not ShouldBroadcast) because streaming events
 * are latency-sensitive and must not be queued — they need to arrive in order
 * and in real-time during the AI response.
 *
 * Uses PrivateChannel to enforce channel authorization via Laravel's broadcasting.
 *
 * Clients listen for the "ai.stream" event on the channel.
 */
class AiStreamEvent implements ShouldBroadcastNow
{
    /**
     * @param  string  $channelName  The channel to broadcast on (e.g. "private-user.1.conversation.456").
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
     * @return PrivateChannel
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channelName);
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
