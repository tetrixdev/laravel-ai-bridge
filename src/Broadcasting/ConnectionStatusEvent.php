<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Broadcasting;

use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcasting event that announces a CLI bridge connecting or disconnecting.
 *
 * Pushed to the per-connection private channel ("{prefix}.connection.{id}")
 * so the chat UI can refresh a connection's live capabilities the moment its
 * bridge comes online — no polling required.
 *
 * Uses ShouldBroadcastNow (not ShouldBroadcast): the bridge server dispatches
 * this from a long-running process where no queue worker is guaranteed, and
 * the signal is only useful while it is fresh.
 *
 * Clients listen for the "connection.status" event on the channel.
 */
class ConnectionStatusEvent implements ShouldBroadcastNow
{
    use InteractsWithBroadcasting;

    /**
     * @param  string  $channelName  Channel name WITHOUT the "private-" prefix
     *                               (e.g. "ai-bridge.connection.42").
     * @param  int|string  $connectionId  The Connection model's primary key.
     * @param  string  $status  Either "connected" or "disconnected".
     */
    public function __construct(
        private readonly string $channelName,
        public readonly int|string $connectionId,
        public readonly string $status,
    ) {
        // Pin the broadcast to AI Bridge's own connection (default "reverb").
        // Laravel's BroadcastEvent only honours broadcastConnections() from the
        // InteractsWithBroadcasting trait — a plain broadcastConnection() method
        // is never consulted, so the event must call broadcastVia() explicitly
        // or it falls back to the host app's (possibly unset) default.
        $this->broadcastVia(config('ai-bridge.broadcasting.connection'));
    }

    /**
     * The channel the event broadcasts on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channelName);
    }

    /**
     * The event's broadcast name (clients listen for ".connection.status").
     */
    public function broadcastAs(): string
    {
        return 'connection.status';
    }

    /**
     * The data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'status' => $this->status,
        ];
    }
}
