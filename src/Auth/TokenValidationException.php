<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Auth;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when a bridge connection token fails validation.
 */
class TokenValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
