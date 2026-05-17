<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Tetrix\AiBridge\Models\Conversation;

/**
 * Fired after a conversation is created through the package HTTP API.
 *
 * Consuming apps listen for this to link the new (unlinked) conversation to
 * their own owner/session model — e.g. attach it to a pivot table.
 *
 * Dispatched synchronously; the $request is the live request that created it.
 */
class ConversationCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Request $request,
    ) {}
}
