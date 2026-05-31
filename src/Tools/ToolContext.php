<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Tools;

/**
 * The execution context surrounding a single tool call — currently the
 * conversation the call belongs to.
 *
 * Tool handlers receive only their own parameters, by design: the AI should never
 * have to (and must not be trusted to) restate which conversation it is in. But a
 * server-side handler often needs that binding to scope its work — e.g. to resolve
 * the campaign/session a game tool acts on. The bridge already knows the
 * conversation for every tool call ({@see StreamHandler::getConversationId()}); it
 * sets it here for the duration of the execution, without the AI being involved.
 *
 * Registered as a singleton. Set/forgotten by the runtime around each tool call;
 * handlers read it. In the long-lived `ai-bridge:serve` process tool calls run one
 * at a time on the event loop, so a single current-conversation slot is safe.
 */
class ToolContext
{
    private ?string $conversationId = null;

    public function setConversationId(?string $conversationId): void
    {
        $this->conversationId = $conversationId !== '' ? $conversationId : null;
    }

    public function conversationId(): ?string
    {
        return $this->conversationId;
    }

    public function forget(): void
    {
        $this->conversationId = null;
    }
}
