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
        // See onBlockStart for why these blocks are dropped.
        $inStreamToolCall = false;
        // FIFO of tool_call_ids dispatched via the WS frame path (onToolCall),
        // so that tool_result blocks coming back from the CLI's stream events
        // can be paired with the bridge-side block by ORDER. See onToolResult.
        /** @var array<int, string> $pendingWsToolCallIds */
        $pendingWsToolCallIds = [];

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
        $handler->onToolCall(function (string $name, array $params, string $callId) use (&$blocks, &$pendingWsToolCallIds) {
            $blocks[] = ['type' => 'tool_call', 'tool_name' => $name, 'parameters' => $params, 'tool_call_id' => $callId];
            $pendingWsToolCallIds[] = $callId;
        });
        $handler->onToolResult(function (string $callId, mixed $result) use (&$blocks, &$pendingWsToolCallIds) {
            // In bridge/MCP mode the CLI's tool_result carries the CLI's own
            // tool_call_id, but the corresponding tool_call block was stored
            // (by onToolCall above) with the bridge's `mcp-<requestId>-<n>` id —
            // so a chat UI pairing by tool_call_id cannot match them. Remap by
            // FIFO arrival order: the next pending WS-frame id wins. CLIs emit
            // results in the same order they invoked the tools, so this pairs
            // correctly for both sequential and (Claude's) parallel tool calls.
            //
            // BYOK/non-bridge providers also queue here, but their callIds
            // already match across tool_call → tool_result (both come from one
            // provider stream), so the remap is a no-op for them.
            $pairedId = ! empty($pendingWsToolCallIds)
                ? array_shift($pendingWsToolCallIds)
                : $callId;
            $blocks[] = ['type' => 'tool_result', 'tool_call_id' => $pairedId, 'result' => $result];
        });
        $handler->onDone(function (?array $usage) use (&$blocks, &$current, $conversation) {
            self::flushCurrent($blocks, $current);
            self::persist($conversation, $blocks, $usage, false);
            self::clearStreamingRequestId($conversation);
        });

        $persistPartial = function () use (&$blocks, &$current, $conversation) {
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
