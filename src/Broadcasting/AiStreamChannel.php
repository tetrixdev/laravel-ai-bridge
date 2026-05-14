<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Broadcasting;

use Illuminate\Broadcasting\Channel;

/**
 * Broadcastable channel for AI stream events.
 *
 * Wraps the channel name so it can be used with Laravel's broadcasting system.
 * Channel names are typically in the format "game.{id}" or "conversation.{id}".
 */
class AiStreamChannel extends Channel
{
    /**
     * Create a new channel instance.
     *
     * @param  string  $name  The channel name (e.g. "game.123", "conversation.456").
     */
    public function __construct(string $name)
    {
        parent::__construct($name);
    }
}
