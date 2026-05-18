<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Logging helper for the bridge relay path.
 *
 * Centralises two concerns so call sites stay terse:
 *
 *  - Channel resolution. `ai-bridge.logging.channel` may point the package's
 *    logs at a dedicated channel (e.g. one with its own retention). If that
 *    channel is not defined in the host app, resolving it would throw — so we
 *    fall back to the default channel instead. A misconfigured log channel
 *    must never break streaming.
 *
 *  - Verbosity. `info()` always logs; `verbose()` only logs when
 *    `ai-bridge.logging.verbose` is true, for per-event / payload-level
 *    detail that is helpful in development but noisy in production.
 *
 * This is the first place to look when the chat UI hangs on "Thinking":
 * a healthy bridge turn logs "relaying message to bridge" followed by a
 * terminal "stream finished" / "stream error".
 */
final class BridgeLog
{
    /**
     * Resolve the configured log channel, falling back to the default channel
     * when it is unset or not defined in the host application.
     */
    public static function channel(): LoggerInterface
    {
        $name = config('ai-bridge.logging.channel');

        if (! is_string($name) || $name === '') {
            return Log::getFacadeRoot();
        }

        try {
            return Log::channel($name);
        } catch (\Throwable) {
            // Named channel missing/misconfigured — never let logging itself
            // break the request; degrade to the default channel.
            return Log::getFacadeRoot();
        }
    }

    /**
     * Whether per-event / payload-level debug logging is enabled.
     */
    public static function isVerbose(): bool
    {
        return (bool) config('ai-bridge.logging.verbose', false);
    }

    /**
     * Log an info-level relay-path message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * Log a warning-level relay-path message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * Log an error-level relay-path message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * Log a debug-level message — emitted only when verbose logging is on.
     *
     * @param  array<string, mixed>  $context
     */
    public static function verbose(string $message, array $context = []): void
    {
        if (self::isVerbose()) {
            self::write('debug', $message, $context);
        }
    }

    /**
     * Write a single log line, swallowing any failure.
     *
     * Logging must never break the bridge: an unwritable log file, a
     * misconfigured channel, a full disk — losing a log line is always
     * preferable to failing the request that was trying to log it. (Without
     * this guard, an unwritable channel would throw out of the relay path and
     * surface to the browser as a 500.)
     *
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        try {
            self::channel()->{$level}('AI Bridge: '.$message, $context);
        } catch (\Throwable) {
            // Intentionally swallowed — see the method docblock.
        }
    }
}
