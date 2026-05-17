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

        $handler->onBlockStart(function (StreamEvent $event) use (&$current) {
            $current = ['type' => $event->data['block_type'] ?? 'text', 'text' => ''];
        });
        $handler->onBlockDelta(function (StreamEvent $event) use (&$current) {
            if ($current !== null) {
                $current['text'] .= $event->data['content'] ?? '';
            }
        });
        $handler->onBlockStop(function () use (&$blocks, &$current) {
            if ($current !== null) {
                $blocks[] = $current;
                $current = null;
            }
        });
        $handler->onToolCall(function (string $name, array $params, string $callId) use (&$blocks) {
            $blocks[] = ['type' => 'tool_call', 'tool_name' => $name, 'parameters' => $params, 'tool_call_id' => $callId];
        });
        $handler->onToolResult(function (string $callId, mixed $result) use (&$blocks) {
            $blocks[] = ['type' => 'tool_result', 'tool_call_id' => $callId, 'result' => $result];
        });
        $handler->onDone(function (?array $usage) use (&$blocks, &$current, $conversation) {
            self::flushCurrent($blocks, $current);
            self::persist($conversation, $blocks, $usage, false);
        });

        $persistPartial = function () use (&$blocks, &$current, $conversation) {
            self::flushCurrent($blocks, $current);
            if (config('ai-bridge.persistence.persist_partial_on_error', true) && self::hasContent($blocks)) {
                self::persist($conversation, $blocks, null, true);
            }
        };
        $handler->onError(fn () => $persistPartial());
        $handler->onCancelled(fn () => $persistPartial());
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
