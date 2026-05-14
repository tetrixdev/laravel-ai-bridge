<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Enums;

/**
 * Defines the active AI provider mode.
 *
 * - Bridge:  Responses stream through a CLI bridge connected via WebSocket.
 * - Byok:    Bring Your Own Key — calls Chat Completions API with user-supplied key.
 * - Managed: Calls Chat Completions API with an application-managed key.
 */
enum ProviderMode: string
{
    case Bridge = 'bridge';
    case Byok = 'byok';
    case Managed = 'managed';

    /**
     * Determine whether this mode uses the Chat Completions API.
     */
    public function usesChatCompletions(): bool
    {
        return match ($this) {
            self::Bridge => false,
            self::Byok, self::Managed => true,
        };
    }

    /**
     * Determine whether this mode requires a WebSocket bridge connection.
     */
    public function requiresBridge(): bool
    {
        return $this === self::Bridge;
    }
}
