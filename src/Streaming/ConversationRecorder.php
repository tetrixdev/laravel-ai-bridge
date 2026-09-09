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
     * The MCP namespace the bridge registers its own server under.
     *
     * A server-declared tool reaches the model as `mcp__bridge__<tool>` and is
     * relayed back over the WebSocket as a `tool_call` frame, so it is recorded
     * from that frame instead of from the stream block. Anything else ran on the
     * operator's own machine and the stream block is its only record.
     */
    private const BRIDGE_TOOL_PREFIX = 'mcp__bridge__';

    /** Is this tool one the server resolves, rather than one that ran locally? */
    private static function isServerResolved(string $toolName): bool
    {
        return str_starts_with($toolName, self::BRIDGE_TOOL_PREFIX);
    }

    /**
     * Decode a locally-run tool call's arguments.
     *
     * The bridge sends them as one delta carrying the complete JSON object, so
     * this normally parses. When it does not — a turn truncated mid-arguments —
     * the raw text is kept under `_raw` rather than discarded, because "the
     * arguments were cut off" and "the tool was called with none" are different
     * things and a reader should be able to tell them apart.
     *
     * @return array<string, mixed>
     */
    private static function decodeArguments(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : ['_raw' => $trimmed];
    }

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
            if ($blockType === 'tool_call') {
                $toolName = $event->data['tool_name'] ?? null;

                // A tool the SERVER resolves arrives twice: once as this block
                // and once as the canonical WS tool_call frame, which carries
                // the parsed arguments. Recording both shows the same call
                // twice, so the block is dropped and onToolCall below wins.
                //
                // A tool that ran on the operator's own machine — Bash, Read,
                // an editor — has no WS frame, so this block is the only record
                // there will ever be. Dropping it unconditionally, which is what
                // this used to do, is why a chat could only say "4 tool calls":
                // the names were being thrown away here, one layer after being
                // thrown away in StreamHandler.
                if ($toolName === null || $toolName === '' || self::isServerResolved($toolName)) {
                    $inStreamToolCall = true;
                    $current = null;

                    return;
                }

                $inStreamToolCall = false;
                $current = [
                    'type' => 'tool_call',
                    'tool_name' => $toolName,
                    'text' => '',
                ];
                if (isset($event->data['tool_call_id']) && is_string($event->data['tool_call_id'])) {
                    $current['tool_call_id'] = $event->data['tool_call_id'];
                }

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
                // For a locally-run tool the accumulated delta text IS the
                // arguments JSON. Decode it so a consumer gets the same shape
                // as a server-resolved call, and keep the raw text when it does
                // not parse rather than reporting no arguments at all.
                if (($current['type'] ?? '') === 'tool_call') {
                    $current['parameters'] = self::decodeArguments($current['text'] ?? '');
                    unset($current['text']);
                }
                $blocks[] = $current;
                $current = null;
            }
        });
        $handler->onToolCall(function (string $name, array $params, string $callId) use (&$blocks) {
            $blocks[] = ['type' => 'tool_call', 'tool_name' => $name, 'parameters' => $params, 'tool_call_id' => $callId];
        });
        $handler->onToolResult(function (string $callId, mixed $result, ?bool $isError = null) use (&$blocks) {
            // Record the tool_result with the id the dispatcher provided. In
            // bridge mode that's the CLI's own id (which won't match the WS
            // tool_call block's `mcp-<rid>-<n>` id) — but the chat UI renders
            // tool_result blocks STANDALONE (see msgHtml in ai-bridge-chat.js:
            // type === 'tool_result' branch), so the mismatch is harmless. An
            // earlier draft tried to remap ids by FIFO arrival order; that
            // assumed CLIs emit results in invocation order, which is not
            // guaranteed under parallel tool_use, so it was dropped.
            $block = ['type' => 'tool_result', 'tool_call_id' => $callId, 'result' => $result];
            // Only when the provider actually said. Absent must not be read as
            // success — a tool printing "Error: no matches" is not a failure,
            // and a failure that reported nothing is not a success.
            if ($isError !== null) {
                $block['is_error'] = $isError;
            }
            $blocks[] = $block;
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
