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
        // `[]` is both — array_is_list() reports true for an empty array, so a
        // tool called with `{}` was being recorded as "could not be parsed"
        // with a stray parameters_raw of '{}'.
        // The TEXT decides, not the decoded shape: json_decode(assoc) turns
        // {"0":"a"} and ["a"] into the same PHP array, so array_is_list alone
        // recorded a genuine object with numeric keys as "could not be parsed"
        // — a false statement about the data.
        // The TEXT decides. `{}` is covered by this test; adding `$decoded === []`
        // would also swallow `[]`, which is a list and which the component keeps
        // as raw — two answers for one input.
        if (is_array($decoded) && $trimmed[0] === '{') {
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
        /**
         * Frames that arrived while a block was open, waiting for it to close.
         *
         * @var array<int, array<string, mixed>>
         */
        $pendingFrames = [];

        $handler->onBlockStart(function (StreamEvent $event) use (&$blocks, &$current, &$inStreamToolCall, &$pendingFrames) {
            // A block the stream never closed used to be overwritten here and
            // lost outright — flushCurrent only rescues one still open when the
            // TURN ends, so a truncated block followed by any other block
            // vanished from the record, arguments and all.
            self::flushCurrent($blocks, $current, $pendingFrames);

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
            // Reset here too. A nameless tool block sets this and is dropped;
            // without clearing it, the next block's deltas were swallowed and
            // its block discarded by onBlockStop's early return — so the
            // assistant's prose disappeared from the record while a hollow
            // empty block took its place.
            $inStreamToolCall = false;
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
        $handler->onBlockStop(function () use (&$blocks, &$current, &$inStreamToolCall, &$pendingFrames) {
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

            // Frames that arrived while this block was open now follow it.
            foreach ($pendingFrames as $frame) {
                $blocks[] = $frame;
            }
            $pendingFrames = [];
        });
        $handler->onToolCall(function (string $name, array $params, string $callId) use (&$blocks, &$current, &$pendingFrames) {
            $frame = ['type' => 'tool_call', 'tool_name' => $name]
                + self::capParameters($params)
                + ['tool_call_id' => $callId, '_from_frame' => true];

            // Behind the block still open, not in front of it — and WAITING for
            // it, not closing it. A frame is recorded the moment it arrives
            // while an open block is only recorded at block_stop, so a tool call
            // announced mid-sentence was stored before the prose introducing it
            // and drawn after; 150 of 400 randomly generated streams diverged on
            // order alone. Flushing the open block instead would end it early
            // and drop the deltas still to come.
            if ($current !== null) {
                $pendingFrames[] = $frame;

                return;
            }

            $blocks[] = $frame;
        });
        $handler->onToolResult(function (string $callId, mixed $result, ?bool $isError = null) use (&$blocks, &$current, &$pendingFrames) {
            // Attach to the call it belongs to, wherever that call currently
            // is. There are three places, and each was found by a divergence
            // against the chat component rather than by reading:
            //
            //   - the block still OPEN, for a result that arrives before its
            //     own block_stop;
            //   - the closed blocks;
            //   - a frame still QUEUED behind an open block, which is in
            //     neither of the other two.
            //
            // A standalone block is the last resort, not the default. The
            // comment that used to sit here argued a mismatch of ids was
            // harmless "because the chat UI renders tool_result blocks
            // STANDALONE" — that stopped being true earlier on this branch, and
            // nothing noticed until the two implementations were run against
            // one corpus.
            //
            // Ids are never remapped by arrival order. That assumes CLIs emit
            // results in invocation order, which is not guaranteed under
            // parallel tool use.
            if (($current['type'] ?? '') === 'tool_call'
                && ($current['tool_call_id'] ?? null) === $callId
                && ! array_key_exists('result', $current)) {
                $current['result'] = $result;
                if ($isError !== null) {
                    $current['is_error'] = $isError;
                }

                return;
            }

            foreach ($blocks as $i => $candidate) {
                if (($candidate['type'] ?? '') !== 'tool_call') {
                    continue;
                }
                if (($candidate['tool_call_id'] ?? null) !== $callId || array_key_exists('result', $candidate)) {
                    continue;
                }

                $blocks[$i]['result'] = $result;
                if ($isError !== null) {
                    $blocks[$i]['is_error'] = $isError;
                }

                return;
            }

            // A frame still waiting behind an open block is in neither place
            // searched above, so its result was detached from the call it
            // belongs to — and the carry-over at reconciliation then had
            // nothing to carry.
            foreach ($pendingFrames as $i => $frame) {
                if (($frame['tool_call_id'] ?? null) === $callId && ! array_key_exists('result', $frame)) {
                    $pendingFrames[$i]['result'] = $result;
                    if ($isError !== null) {
                        $pendingFrames[$i]['is_error'] = $isError;
                    }

                    return;
                }
            }

            $block = ['type' => 'tool_result', 'tool_call_id' => $callId, 'result' => $result];
            // Only when the provider actually said. Absent must not be read as
            // success — a tool printing "Error: no matches" is not a failure,
            // and a failure that reported nothing is not a success.
            if ($isError !== null) {
                $block['is_error'] = $isError;
            }

            // Behind the block still open, for the same reason a tool_call
            // frame is: appended now it would sit in front of the prose that
            // introduces it. The queue was given to frames and not to their
            // sibling emitter here.
            if ($current !== null) {
                $pendingFrames[] = $block;

                return;
            }

            $blocks[] = $block;
        });
        $handler->onDone(function (?array $usage) use (&$blocks, &$current, &$inStreamToolCall, &$pendingFrames, $conversation) {
            $inStreamToolCall = false;
            self::flushCurrent($blocks, $current, $pendingFrames);
            self::persist($conversation, $blocks, $usage, false);
            self::clearStreamingRequestId($conversation);
        });

        $persistPartial = function () use (&$blocks, &$current, &$inStreamToolCall, &$pendingFrames, $conversation) {
            // A truncated stream may have left $inStreamToolCall set without a
            // matching block_stop. Clearing it isn't strictly necessary here
            // (this is a terminal — no more events arrive), but resetting
            // keeps the closure state consistent if a future refactor reuses
            // the recorder across turns.
            $inStreamToolCall = false;
            self::flushCurrent($blocks, $current, $pendingFrames);
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
    private static function flushCurrent(array &$blocks, ?array &$current, array &$pendingFrames = []): void
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

        // Anything that arrived while it was open now follows it.
        foreach ($pendingFrames as $frame) {
            $blocks[] = $frame;
        }
        $pendingFrames = [];
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
     * Is there exactly one call of this name in the turn from each side?
     *
     * Guards the one place arguments move between records. With two parallel
     * calls to the same tool there is no way to tell which frame belongs to
     * which block — this file rejects that same guess about tool_results — so
     * where it is ambiguous nothing is copied and the block keeps what it has.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function isUnambiguous(array $blocks, string $frameToolName): bool
    {
        $streamCalls = 0;
        $frameCalls = 0;

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'tool_call') {
                continue;
            }
            if ($block['_from_stream'] ?? false) {
                $streamCalls += self::describesSameCall((string) ($block['tool_name'] ?? ''), $frameToolName) ? 1 : 0;
            } elseif ($block['_from_frame'] ?? false) {
                // The same predicate as the stream side. Two different tests
                // could report "unambiguous" for a set that is not.
                $frameCalls += self::describesSameCall($frameToolName, (string) ($block['tool_name'] ?? '')) ? 1 : 0;
            }
        }

        return $streamCalls === 1 && $frameCalls === 1;
    }

    /**
     * Apply the argument ceiling to a frame's already-parsed parameters.
     *
     * The cap existed only on the stream path, so a frame carrying a 300KB
     * argument was persisted whole — and in BYOK and Managed modes EVERY tool
     * call is a frame, so the growth path the cap exists to close was open in
     * its entirety there.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function capParameters(array $params): array
    {
        // The same flags JavaScript's JSON.stringify uses, so the two
        // implementations produce the same bytes for the same arguments.
        // Without them PHP writes "\u00e9" where JS writes "é", and the pair
        // then disagree about both the size of the thing they are reporting and
        // how much of it they kept.
        $encoded = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Written the other way round the first time, which put the one case
        // that must NEVER be stored verbatim — parameters that cannot be
        // encoded at all — on the "small enough, keep them" branch. The blocks
        // cast then fails and the ENTIRE assistant turn is lost, prose
        // included, exactly as an invalid byte-offset cut would.
        if ($encoded === false) {
            return ['parameters' => [], 'parameters_raw' => '[arguments could not be encoded]'];
        }

        if (strlen($encoded) <= self::MAX_ARGUMENT_BYTES) {
            return ['parameters' => $params];
        }

        return [
            'parameters' => [],
            'parameters_raw' => mb_strcut($encoded, 0, self::MAX_ARGUMENT_BYTES, 'UTF-8'),
            'parameters_truncated_bytes' => strlen($encoded),
        ];
    }

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

                // Carry over anything attached to the frame before dropping it.
                // The component does this explicitly; without it here a result
                // keyed to the frame's id was destroyed outright.
                foreach (['result', 'is_error'] as $carried) {
                    if (array_key_exists($carried, $block) && ! array_key_exists($carried, $blocks[$candidate])) {
                        $blocks[$candidate][$carried] = $block[$carried];
                    }
                }

                // The block is USUALLY richer — it has the id results are keyed
                // by — but not always: its arguments are raw delta text, which
                // can arrive truncated, unparsed, or not at all, while the
                // frame carries them already parsed. Take them from the frame in
                // exactly that case, and only when the pairing is unambiguous,
                // so nothing can be attributed to the wrong call.
                if (isset($blocks[$candidate]['parameters_raw']) || ($blocks[$candidate]['parameters'] ?? []) === []) {
                    // Only when the frame actually has something better. A
                    // genuinely empty frame would otherwise erase the block's
                    // record of having been cut off and replace it with
                    // "called with no arguments" — a false statement about the
                    // data, which is the thing this whole area is careful about.
                    $frameHasArguments = ($block['parameters'] ?? []) !== [] || isset($block['parameters_raw']);

                    if ($frameHasArguments && self::isUnambiguous($blocks, (string) ($block['tool_name'] ?? ''))) {
                        unset($blocks[$candidate]['parameters_raw'], $blocks[$candidate]['parameters_truncated_bytes']);
                        $blocks[$candidate] = $blocks[$candidate] + ['parameters' => []];
                        $blocks[$candidate]['parameters'] = $block['parameters'] ?? [];
                        if (isset($block['parameters_raw'])) {
                            $blocks[$candidate]['parameters_raw'] = $block['parameters_raw'];
                        }
                        if (isset($block['parameters_truncated_bytes'])) {
                            $blocks[$candidate]['parameters_truncated_bytes'] = $block['parameters_truncated_bytes'];
                        }
                    }
                }

                // A result the frame carried that could NOT be merged (the
                // surviving block already has one of its own) becomes a block
                // in its own right rather than dying with the frame. Two
                // results for one call should not happen, and when something
                // that should not happen does, keeping the evidence beats
                // discarding half of it — the rule the component follows.
                if (array_key_exists('result', $block) && array_key_exists('result', $blocks[$candidate])
                    && $block['result'] !== $blocks[$candidate]['result']) {
                    $orphaned = ['type' => 'tool_result', 'tool_call_id' => $block['tool_call_id'] ?? '', 'result' => $block['result']];
                    if (array_key_exists('is_error', $block)) {
                        $orphaned['is_error'] = $block['is_error'];
                    }
                    $blocks[$index] = $orphaned;

                    break;
                }

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
            // tool_result counts too: a turn cancelled after a tool ran and
            // returned is precisely a turn worth keeping.
            if (in_array($block['type'] ?? '', ['tool_call', 'tool_result'], true) || ($block['text'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
