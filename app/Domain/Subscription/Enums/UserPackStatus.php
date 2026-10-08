<?php

namespace App\Domain\Subscription\Enums;

enum UserPackStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case Expired = 'expired';
    case Canceled = 'canceled';

    /**
     * Pending and active packs occupy one of the member's pack slots.
     */
    public function occupiesSlot(): bool
    {
        return in_array($this, [self::PendingPayment, self::Active], true);
    }
}
