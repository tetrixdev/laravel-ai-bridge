<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Listeners;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Broadcasting\ConnectionStatusEvent;
use Tetrix\AiBridge\Events\BridgeConnected;
use Tetrix\AiBridge\Events\BridgeDisconnected;
use Tetrix\AiBridge\Models\Connection;

/**
 * Relays bridge connect/disconnect events to the browser over Reverb.
 *
 * The bridge WebSocket server (ai-bridge:serve) dispatches BridgeConnected /
 * BridgeDisconnected when a CLI bridge opens or closes its socket. This
 * listener turns those into a ConnectionStatusEvent broadcast on the owning
 * connection's private channel, so the chat UI updates a connection's live
 * capabilities the instant its bridge comes online — instead of polling.
 *
 * Bridge events identify the bridge by its JWT subject, which is the
 * Connection's `connection_key` (see BridgeWebSocketHandler::onOpen).
 */
class BroadcastConnectionStatus
{
    public function handleConnected(BridgeConnected $event): void
    {
        $this->broadcastStatus((string) $event->userId, 'connected');
    }

    public function handleDisconnected(BridgeDisconnected $event): void
    {
        $this->broadcastStatus((string) $event->userId, 'disconnected');
    }

    /**
     * Resolve the connection by its key and broadcast its new status.
     *
     * Failures are swallowed (logged only): a missed status push degrades to
     * the UI's slower refresh paths and must never crash the bridge server.
     */
    private function broadcastStatus(string $connectionKey, string $status): void
    {
        if (! config('ai-bridge.broadcasting.enabled', true)) {
            return;
        }

        try {
            $connection = Connection::query()
                ->where('connection_key', $connectionKey)
                ->first();

            if ($connection === null) {
                return;
            }

            $prefix = (string) config('ai-bridge.persistence.channel_prefix', 'ai-bridge');

            broadcast(new ConnectionStatusEvent(
                $prefix.'.connection.'.$connection->id,
                $connection->id,
                $status,
            ));
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: failed to broadcast connection status', [
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
