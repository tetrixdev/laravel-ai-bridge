<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Streaming;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Models\Conversation;
use Tetrix\AiBridge\Models\Message;
use Tetrix\AiBridge\Protocol\StreamEvent;

/**
 * Records the assistant side of a conversation turn by observing a StreamHandler.
 *
 * Attach a recorder to a StreamHandler and it accumulates the RAW block stream
 * — text, thinking, and tool blocks — and writes the assistant Message when the
 * stream terminates. It is used in two places, because bridge-mode events and
 * BYOK/Managed events are processed in different OS processes:
 *
 *  - BYOK / Managed (SSE): attached in the web process by AiBridgeManager.
 *  - Bridge (relayed): attached in the `ai-bridge:serve` process by RelayStream,
 *    which is where relayed stream events actually arrive.
 *
 * Captures thinking blocks for faithful UI replay; they are excluded from
 * history injection separately, by Conversation::historyFor().
 */
final class ConversationRecorder
{
    /**
     * Attach recording callbacks to a StreamHandler for the given conversation.
     */
    public static function attach(StreamHandler $handler, Conversation $conversation): void
    {
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = [];
        /** @var array<string, mixed>|null $current */
        $current = null;
        // True while accumulating block_delta for a stream-event tool_call block.
        // See onBlockStart for why these blocks are dropped. Reset defensively
        // on terminal events so a tool_call without a matching block_stop
        // (truncated stream) can't make us swallow subsequent block_deltas.
        $inStreamToolCall = false;

        $handler->onBlockStart(function (StreamEvent $event) use (&$current, &$inStreamToolCall) {
            $blockType = $event->data['block_type'] ?? 'text';
            // Drop stream-event tool_call blocks. They are a shadow of the WS
            // tool_call frame: the CLI emits block_start (tool_call) + a
            // block_delta carrying the args text + block_stop, but with no
            // tool_name and no tool_call_id. The chat UI cannot render them
            // (it skips tool_call blocks with no tool_name) and on a recorded
            // turn they appear as duplicate, unpaired tool_call entries. The
            // canonical block is stored by the onToolCall callback below.
            if ($blockType === 'tool_call') {
                $inStreamToolCall = true;
                $current = null;

                return;
            }
            $current = ['type' => $blockType, 'text' => ''];
        });
        $handler->onBlockDelta(function (StreamEvent $event) use (&$current, &$inStreamToolCall) {
            if ($inStreamToolCall) {
                return;
            }
            if ($current !== null) {
                $current['text'] .= $event->data['content'] ?? '';
            }
        });
        $handler->onBlockStop(function () use (&$blocks, &$current, &$inStreamToolCall) {
            if ($inStreamToolCall) {
                $inStreamToolCall = false;

                return;
            }
            if ($current !== null) {
                $blocks[] = $current;
                $current = null;
            }
        });
        $handler->onToolCall(function (string $name, array $params, string $callId) use (&$blocks) {
            $blocks[] = ['type' => 'tool_call', 'tool_name' => $name, 'parameters' => $params, 'tool_call_id' => $callId];
        });
        $handler->onToolResult(function (string $callId, mixed $result) use (&$blocks) {
            // Record the tool_result with the id the dispatcher provided. In
            // bridge mode that's the CLI's own id (which won't match the WS
            // tool_call block's `mcp-<rid>-<n>` id) — but the chat UI renders
            // tool_result blocks STANDALONE (see msgHtml in ai-bridge-chat.js:
            // type === 'tool_result' branch), so the mismatch is harmless. An
            // earlier draft tried to remap ids by FIFO arrival order; that
            // assumed CLIs emit results in invocation order, which is not
            // guaranteed under parallel tool_use, so it was dropped.
            $blocks[] = ['type' => 'tool_result', 'tool_call_id' => $callId, 'result' => $result];
        });
        $handler->onDone(function (?array $usage) use (&$blocks, &$current, &$inStreamToolCall, $conversation) {
            $inStreamToolCall = false;
            self::flushCurrent($blocks, $current);
            self::persist($conversation, $blocks, $usage, false);
            self::clearStreamingRequestId($conversation);
        });

        $persistPartial = function () use (&$blocks, &$current, &$inStreamToolCall, $conversation) {
            // A truncated stream may have left $inStreamToolCall set without a
            // matching block_stop. Clearing it isn't strictly necessary here
            // (this is a terminal — no more events arrive), but resetting
            // keeps the closure state consistent if a future refactor reuses
            // the recorder across turns.
            $inStreamToolCall = false;
            self::flushCurrent($blocks, $current);
            if (config('ai-bridge.persistence.persist_partial_on_error', true) && self::hasContent($blocks)) {
                self::persist($conversation, $blocks, null, true);
            }
            self::clearStreamingRequestId($conversation);
        };
        $handler->onError(fn () => $persistPartial());
        $handler->onCancelled(fn () => $persistPartial());
    }

    /**
     * Clear the conversation's `streaming_request_id` once a turn terminates.
     *
     * Done in a separate UPDATE rather than via the model instance so the
     * write is safe even if the recorder is operating on a stale Eloquent
     * instance (e.g. across the web/serve process split).
     */
    private static function clearStreamingRequestId(Conversation $conversation): void
    {
        try {
            Conversation::query()
                ->whereKey($conversation->id)
                ->update(['streaming_request_id' => null]);
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: failed to clear streaming_request_id', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>|null  $current
     */
    private static function flushCurrent(array &$blocks, ?array &$current): void
    {
        if ($current !== null) {
            $blocks[] = $current;
            $current = null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>|null  $usage
     */
    private static function persist(Conversation $conversation, array $blocks, ?array $usage, bool $incomplete): void
    {
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? 'text') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        try {
            $conversation->appendMessage(Message::ROLE_ASSISTANT, $text, [
                'blocks' => $blocks,
                'provider' => $conversation->provider,
                'model' => $conversation->model,
                'usage' => $usage,
                'incomplete' => $incomplete,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI Bridge: failed to persist assistant turn', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function hasContent(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'tool_call' || ($block['text'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
