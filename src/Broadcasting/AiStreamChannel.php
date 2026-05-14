<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Broadcastable channel for AI stream events.
 *
 * Uses PrivateChannel to ensure channel authorization is enforced by
 * Laravel's broadcasting system (Reverb). Clients must authenticate
 * to subscribe to the channel.
 *
 * Channel names follow the pattern "private-user.{userId}.conversation.{conversationId}".
 */
class AiStreamChannel extends PrivateChannel
{
    /**
     * Create a new channel instance.
     *
     * @param  string  $name  The channel name (e.g. "private-user.1.conversation.456").
     */
    public function __construct(string $name)
    {
        parent::__construct($name);
    }
}
