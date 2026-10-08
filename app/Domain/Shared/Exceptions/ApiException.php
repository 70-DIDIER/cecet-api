<?php

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Base exception for business errors exposed through the API.
 *
 * Every API error carries a stable machine code, a controlled user message,
 * an HTTP status and optional structured details.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
