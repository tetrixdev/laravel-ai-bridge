<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Enums;

/**
 * Describes how an AI response stream ended.
 *
 * Used by the StreamCompleted event so analytics/billing listeners can
 * distinguish cancellations from errors without fragile string parsing.
 *
 * - Success:   The stream completed normally.
 * - Error:     The stream failed (HTTP error, provider error, etc.).
 * - Cancelled: The stream was cancelled before completion.
 */
enum TerminatedBy: string
{
    case Success = 'success';
    case Error = 'error';
    case Cancelled = 'cancelled';
}
