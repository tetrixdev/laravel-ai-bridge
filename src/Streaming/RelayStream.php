<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Broadcasting\AiStreamEvent;
use Tetrix\AiBridge\Contracts\StreamableProvider;
use Tetrix\AiBridge\Enums\ProviderMode;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Protocol\StreamEvent;
use Tetrix\AiBridge\Support\BridgeLog;

/**
 * Serve-side counterpart of BridgeStream for relayed (PHP-FPM) requests.
 *
 * When a request originates under PHP-FPM, BridgeStream relays it to the
 * bridge server's internal HTTP API. The actual AI response events then
 * arrive asynchronously at the separate `ai-bridge:serve` process — never
 * at the PHP-FPM worker that issued the request. The broadcasting callbacks
 * wired by AiBridgeManager::streamAndBroadcast() live in that PHP-FPM worker
 * and therefore never see the events.
 *
 * RelayStream bridges that gap. The serve process registers a RelayStream as
 * the pending request for each relayed request_id. Its StreamHandler callbacks
 * re-broadcast every event via AiStreamEvent on the user/conversation channel,
 * so the browser receives stream events and tool calls can be verified and
 * executed (the StreamHandler is what verifySenderOwnsRequest() looks up).
 *
 * Unlike BridgeStream, RelayStream does not drive the stream — the remote
 * bridge already received the ai_request. start() and cancel() are no-ops.
 */
final class RelayStream implements StreamableProvider
{
    private readonly StreamHandler $streamHandler;

    /**
     * @param  string  $requestId       The relayed request's ID (preserved for event routing).
     * @param  string  $channel         The broadcast channel, e.g. "user.1.conversation.456".
     * @param  string  $conversationId  The conversation ID, for StreamCompleted reporting.
     */
    public function __construct(
        string $requestId,
        private readonly string $channel,
        string $conversationId = '',
    ) {
        $this->streamHandler = new StreamHandler($this, $requestId);
        $this->streamHandler->setMode(ProviderMode::Bridge);
        $this->streamHandler->setConversationId($conversationId);

        $this->streamHandler->onBlockStart(function (StreamEvent $event): void {
            $this->broadcast($event->event, $event->data);
        });
        $this->streamHandler->onBlockDelta(function (StreamEvent $event): void {
            $this->broadcast($event->event, $event->data);
        });
        $this->streamHandler->onBlockStop(function (StreamEvent $event): void {
            $this->broadcast($event->event, $event->data);
        });
        $this->streamHandler->onToolCall(function (string $name, array $params, string $callId): void {
            $this->broadcast('tool_call', [
                'tool_name' => $name,
                'parameters' => $params,
                'tool_call_id' => $callId,
            ]);
        });
        $this->streamHandler->onDone(function (?array $usage): void {
            $this->broadcast('done', ['usage' => $usage]);
        });
        $this->streamHandler->onError(function (string $code, string $message): void {
            $this->broadcast('error', ['code' => $code, 'message' => $message]);
        });
        $this->streamHandler->onCancelled(function (string $reason): void {
            $this->broadcast('cancelled', ['reason' => $reason]);
        });

        // Bridge-mode stream events arrive in THIS (serve) process, so this is
        // where bridge-conversation persistence must happen. When the relayed
        // request belongs to a persisted conversation (numeric DB id), attach a
        // recorder that writes the assistant turn on completion. The user turn
        // was already persisted by the web process before the request relayed.
        if ($conversationId !== '' && is_numeric($conversationId)) {
            try {
                $conversation = Conversation::find($conversationId);
                if ($conversation !== null) {
                    ConversationRecorder::attach($this->streamHandler, $conversation);
                }
            } catch (\Throwable $e) {
                Log::warning('AI Bridge: could not attach conversation recorder to relayed stream', [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Broadcast a single relayed stream event via Reverb.
     *
     * Broadcasting failures must NOT bubble up — a missing or misconfigured
     * broadcast driver must never break tool execution or terminate the stream.
     *
     * @param  array<string, mixed>  $data
     */
    private function broadcast(string $eventName, array $data): void
    {
        // A turn's terminal events get an info line (so a completed/failed
        // turn is visible without verbose logging); intermediate block/tool
        // events are debug-only to keep the relay-path log readable.
        if (in_array($eventName, ['done', 'error', 'cancelled'], true)) {
            BridgeLog::info('relayed stream '.$eventName, [
                'request_id' => $this->streamHandler->requestId,
                'channel' => $this->channel,
                'detail' => $eventName === 'error' ? $data : null,
            ]);
        } else {
            BridgeLog::verbose('broadcasting relayed stream event', [
                'request_id' => $this->streamHandler->requestId,
                'channel' => $this->channel,
                'event' => $eventName,
            ]);
        }

        try {
            event(new AiStreamEvent($this->channel, $this->streamHandler->requestId, $eventName, $data));
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to broadcast relayed stream event (is the broadcast driver / Reverb configured?)', [
                'request_id' => $this->streamHandler->requestId,
                'channel' => $this->channel,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function setConversationId(string $conversationId): static
    {
        $this->streamHandler->setConversationId($conversationId);

        return $this;
    }

    public function setMessage(string $message): static
    {
        return $this;
    }

    public function setOptions(array $options): static
    {
        return $this;
    }

    /**
     * No-op — the remote bridge already received the ai_request and drives the stream.
     */
    public function start(): void
    {
        // Intentionally empty: RelayStream does not originate the request.
    }

    /**
     * No-op — cancellation of relayed requests is handled by the relay path, not here.
     */
    public function cancel(): void
    {
        // Intentionally empty: RelayStream does not drive the stream.
    }

    /**
     * No-op — completion tracking is not needed for relayed requests.
     */
    public function markCompleted(): void
    {
        // Intentionally empty.
    }

    public function getStreamHandler(): StreamHandler
    {
        return $this->streamHandler;
    }
}
