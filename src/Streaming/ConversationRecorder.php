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
     * How much of a tool call's arguments to persist.
     *
     * Locally-run tool calls are now recorded, and some of them carry a whole
     * file: a `Write` call's arguments are the file body. Storing that verbatim
     * in every recorded turn is an unbounded growth path in the messages table
     * that did not exist while these blocks were being dropped. Truncation is
     * marked explicitly rather than done silently.
     */
    private const MAX_ARGUMENT_BYTES = 65536;

    /** The MCP namespace the bridge registers its own tools under. */
    private const BRIDGE_TOOL_PREFIX = 'mcp__bridge__';

    /**
     * Does this stream block describe the same call as a `tool_call` frame?
     *
     * The question is NOT "is it namespaced under the bridge" — that was the
     * first answer here and it is wrong. A server-declared tool with
     * `execute: "local"` is run BY the bridge and never emits a `tool_call`
     * frame (PROTOCOL.md), yet it still reaches the model as
     * `mcp__bridge__<tool>`; matching on the prefix deleted exactly those calls
     * and left their results orphaned. The reverse also fails: in `native`
     * posture an operator's own MCP server named `bridge` produces the same
     * prefix for tools this server never declared.
     *
     * So the shadow is identified by the frame that actually arrives. A tool
     * reaches the model namespaced (`mcp__bridge__roll_dice`) and comes back
     * over the WebSocket under its bare name (`roll_dice`).
     */
    private static function describesSameCall(string $blockToolName, string $frameToolName): bool
    {
        // Deliberately NOT an open-ended `__` suffix test. That matched any
        // `mcp__<anything>__<name>`, so in `native` posture a call to the
        // OPERATOR's own MCP server was claimed by a bridge frame for a
        // same-named server tool — destroying the local call's only record,
        // which is the very bug this reconciliation exists to prevent, reached
        // from the other side.
        //
        // PROTOCOL.md is explicit: a `mcp__bridge__<tool>` block has a frame,
        // and anything else has nothing else. The bare match covers providers
        // that report a tool name without a namespace.
        return $blockToolName === self::BRIDGE_TOOL_PREFIX.$frameToolName
            || $blockToolName === $frameToolName;
    }

    /**
     * Decode a tool call's arguments from the text its deltas carried.
     *
     * The bridge sends them as one delta carrying the complete JSON object, so
     * this normally parses. When it does not — a turn truncated mid-arguments,
     * or a value that is not an object — the text is kept under a SIBLING key
     * rather than inside `parameters`, because "the arguments were cut off" and
     * "the tool was called with none" are different things and a reader should
     * be able to tell them apart.
     *
     * Deliberately not a `_raw` key inside `parameters`: a tool may genuinely
     * take an argument called `_raw`, and a sentinel that can appear in the
     * data is the same mistake as reading failure out of an `Error:` prefix.
     *
     * @return array<string, mixed> the `parameters` / `parameters_raw` pair
     */
    private static function decodeArguments(string $text): array
    {
        $trimmed = trim($text);
        if (strlen($trimmed) > self::MAX_ARGUMENT_BYTES) {
            return [
                'parameters' => [],
                // mb_strcut, NOT substr. substr cuts at a byte offset, so a
                // multi-byte character straddling the boundary leaves invalid
                // UTF-8 — the blocks cast then fails to encode and the ENTIRE
                // assistant message is lost, prose and all, not just this
                // block. A UTF-8 file with one accented character past 64KB is
                // enough, and a Write call's arguments are a whole file.
                'parameters_raw' => mb_strcut($trimmed, 0, self::MAX_ARGUMENT_BYTES, 'UTF-8'),
                'parameters_truncated_bytes' => strlen($trimmed),
            ];
        }

        if ($trimmed === '') {
            return ['parameters' => []];
        }

        $decoded = json_decode($trimmed, true);

        // A JSON list, scalar, or null is valid JSON but not an argument
        // object, and json_decode also returns null past its depth limit. All
        // of those keep the text rather than pretending to a shape they do not
        // have.
        if (is_array($decoded) && ! array_is_list($decoded)) {
            return ['parameters' => $decoded];
        }

        return ['parameters' => [], 'parameters_raw' => $trimmed];
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

        $handler->onBlockStart(function (StreamEvent $event) use (&$blocks, &$current, &$inStreamToolCall) {
            // A block the stream never closed used to be overwritten here and
            // lost outright — flushCurrent only rescues one still open when the
            // TURN ends, so a truncated block followed by any other block
            // vanished from the record, arguments and all.
            self::flushCurrent($blocks, $current);

            $blockType = $event->data['block_type'] ?? 'text';
            if ($blockType === 'tool_call') {
                $toolName = $event->data['tool_name'] ?? null;

                // Recorded now, and reconciled later. A tool the SERVER
                // resolves also arrives as a `tool_call` frame carrying parsed
                // arguments; onToolCall below upgrades this block in place
                // rather than appending a second one.
                //
                // Whether that frame comes cannot be known here — it arrives
                // after the block closes — and it cannot be predicted from the
                // name either, which is what the first version of this tried.
                // A block with no name carries nothing worth showing.
                if ($toolName === null || $toolName === '') {
                    $inStreamToolCall = true;
                    $current = null;

                    return;
                }

                $inStreamToolCall = false;
                $current = [
                    'type' => 'tool_call',
                    'tool_name' => $toolName,
                    'text' => '',
                    '_from_stream' => true,
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
                $current = self::finaliseToolCall($current);
                $blocks[] = $current;
                $current = null;
            }
        });
        $handler->onToolCall(function (string $name, array $params, string $callId) use (&$blocks) {
            // Recorded as-is. Reconciliation against the stream block happens
            // at persist time, where the ordering of the two is irrelevant.
            $blocks[] = [
                'type' => 'tool_call',
                'tool_name' => $name,
                'parameters' => $params,
                'tool_call_id' => $callId,
                '_from_frame' => true,
            ];
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
            // Also on this path, which is the one a TRUNCATED turn takes. A
            // turn that dies mid-arguments never sends block_stop — that is
            // what truncated means — so decoding only there left the block with
            // a stray `text` key, no `parameters`, and a reader shown "called
            // with no arguments": exactly the confusion the raw text exists to
            // prevent.
            $blocks[] = self::finaliseToolCall($current);
            $current = null;
        }
    }

    /**
     * Turn an in-flight block into its stored form.
     *
     * For a tool call that means decoding the arguments its deltas carried.
     * Everything else is stored as it stands.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function finaliseToolCall(array $block): array
    {
        if (($block['type'] ?? '') !== 'tool_call') {
            return $block;
        }

        $text = is_string($block['text'] ?? null) ? $block['text'] : '';
        unset($block['text']);

        return $block + self::decodeArguments($text);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>|null  $usage
     */
    /**
     * Drop each `tool_call` frame that duplicates a stream block, and clear the
     * bookkeeping both carried.
     *
     * A server-resolved tool arrives twice: as a stream block (namespaced name,
     * the CLI's own id, arguments as delta text) and as a WebSocket frame (bare
     * name, parsed arguments). The FRAME is the one dropped, because the block
     * has everything it has and two things it does not:
     *
     *  - The CLI's `toolu_…` id, which is what `tool_result` events are keyed
     *    by. An earlier version replaced it with the frame's `mcp-<rid>-<n>`,
     *    so every result then failed to find its call — the opposite of the fix
     *    it was written as.
     *  - Nothing to mis-attribute. That version copied the frame's arguments
     *    onto a matched block, so two parallel calls to the same tool whose
     *    frames returned out of order swapped arguments with each other. This
     *    file rejects exactly that reasoning a few lines below, about
     *    tool_results, and it was quietly re-adopted here.
     *
     * Reconciling at persist rather than on arrival makes it independent of
     * which of the two turns up first, which PROTOCOL.md does not pin down.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private static function reconcileToolCalls(array $blocks): array
    {
        $claimed = [];

        foreach ($blocks as $index => $block) {
            if (($block['type'] ?? '') !== 'tool_call' || ! ($block['_from_frame'] ?? false)) {
                continue;
            }

            foreach ($blocks as $candidate => $streamBlock) {
                if (($streamBlock['type'] ?? '') !== 'tool_call' || ! ($streamBlock['_from_stream'] ?? false)) {
                    continue;
                }
                if (isset($claimed[$candidate])) {
                    continue;
                }
                if (! self::describesSameCall((string) ($streamBlock['tool_name'] ?? ''), (string) ($block['tool_name'] ?? ''))) {
                    continue;
                }

                // One frame cancels against one block; a third call with no
                // frame of its own keeps its block rather than being consumed.
                $claimed[$candidate] = true;
                unset($blocks[$index]);
                break;
            }
        }

        return array_values(array_map(static function (array $block): array {
            unset($block['_from_stream'], $block['_from_frame']);

            return $block;
        }, $blocks));
    }

    private static function persist(Conversation $conversation, array $blocks, ?array $usage, bool $incomplete): void
    {
        $blocks = self::reconcileToolCalls($blocks);

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
