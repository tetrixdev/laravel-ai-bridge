<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Contracts\StreamStoreContract;
use Tetrix\AiBridge\Protocol\MessageTypes;
use Tetrix\AiBridge\Protocol\StreamEvent;

/**
 * Attach a {@see StreamStoreContract} to a {@see StreamHandler} so every
 * dispatched event lands in the per-turn event buffer.
 *
 * Mirrors the {@see ConversationRecorder} pattern: a small static helper that
 * wires callbacks at attach-time and forgets. Attach points (in `AiBridgeManager`
 * and in `RelayStream`) match those of the recorder — the buffer is populated
 * in whichever process actually sees the events.
 *
 * Failures appending to the store are logged and swallowed. A broken buffer
 * must not break the stream itself; the worst case for a transient store
 * failure is that the browser cannot resume after a refresh — the assistant
 * row is still persisted by the recorder at terminal.
 */
final class BufferingSink
{
    public static function attach(StreamHandler $handler, StreamStoreContract $store): void
    {
        $rid = $handler->requestId;

        // Apply the same thinking-block suppression decision the SSE wiring
        // applies, so what gets buffered matches what the UI expects to see
        // on replay.
        $suppressThinking = (bool) config('ai-bridge.streaming.suppress_thinking_blocks', true);
        $currentBlockSuppressed = false;

        $append = static function (string $event, array $data) use ($store, $rid): void {
            try {
                $store->appendEvent($rid, $event, $data);
            } catch (\Throwable $e) {
                Log::error('AI Bridge: failed to buffer stream event', [
                    'request_id' => $rid,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        $handler->onBlockStart(function (StreamEvent $event) use ($append, $suppressThinking, &$currentBlockSuppressed): void {
            $currentBlockSuppressed = $suppressThinking && ($event->data['block_type'] ?? '') === 'thinking';
            if ($currentBlockSuppressed) {
                return;
            }
            $append($event->event, $event->data);
        });

        $handler->onBlockDelta(function (StreamEvent $event) use ($append, &$currentBlockSuppressed): void {
            if ($currentBlockSuppressed) {
                return;
            }
            $append($event->event, $event->data);
        });

        $handler->onBlockStop(function (StreamEvent $event) use ($append, &$currentBlockSuppressed): void {
            if ($currentBlockSuppressed) {
                $currentBlockSuppressed = false;

                return;
            }
            $append($event->event, $event->data);
        });

        $handler->onToolCall(function (string $name, array $params, string $callId) use ($append): void {
            $append(MessageTypes::TOOL_CALL, [
                'tool_name' => $name,
                'parameters' => $params,
                'tool_call_id' => $callId,
            ]);
        });

        // Terminal events both write the event AND flip the buffer status, so
        // the SSE tail and the status endpoint can tell the turn is finished.
        $handler->onDone(function (?array $usage) use ($append, $store, $rid): void {
            $append(MessageTypes::DONE, ['usage' => $usage]);
            self::completeQuietly($store, $rid, 'completed');
        });

        $handler->onError(function (string $code, string $errorMessage) use ($append, $store, $rid): void {
            $append(MessageTypes::ERROR, ['code' => $code, 'message' => $errorMessage]);
            self::completeQuietly($store, $rid, 'failed');
        });

        $handler->onCancelled(function (string $reason) use ($append, $store, $rid): void {
            $append(MessageTypes::CANCELLED, ['reason' => $reason]);
            self::completeQuietly($store, $rid, 'cancelled');
        });
    }

    private static function completeQuietly(StreamStoreContract $store, string $rid, string $status): void
    {
        try {
            $store->complete($rid, $status);
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to mark stream buffer complete', [
                'request_id' => $rid,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
