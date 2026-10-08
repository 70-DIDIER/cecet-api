<?php

namespace App\Domain\Exchange\Enums;

enum ExchangeStatus: string
{
    case AwaitingTransfer = 'awaiting_transfer';
    case DeclaredSent = 'declared_sent';
    case YemReceived = 'yem_received';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Disputed = 'disputed';

    /**
     * The quota is still reserved and the Validator can still decide.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingTransfer, self::DeclaredSent, self::Disputed], true);
    }

    /**
     * The Validator confirmed the arrival of the YEM: the counter-value is owed.
     */
    public function isYemConfirmed(): bool
    {
        return in_array($this, [self::YemReceived, self::Completed], true);
    }
}
