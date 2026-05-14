<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Enums;

/**
 * Types of content blocks in an AI response stream.
 */
enum BlockType: string
{
    /**
     * Internal reasoning / chain-of-thought (may be hidden from end users).
     */
    case Thinking = 'thinking';

    /**
     * Visible text output.
     */
    case Text = 'text';

    /**
     * A tool/function call the AI wants to execute.
     */
    case ToolCall = 'tool_call';
}
