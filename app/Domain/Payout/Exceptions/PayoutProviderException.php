<?php

namespace App\Domain\Payout\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A payout call failed. When $providerReference is known, the transfer exists at the provider and must be
 * reconciled, never re-created.
 */
class PayoutProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $providerReference = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
