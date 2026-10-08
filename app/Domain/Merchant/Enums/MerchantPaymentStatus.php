<?php

namespace App\Domain\Merchant\Enums;

enum MerchantPaymentStatus: string
{
    case Reserved = 'reserved';
    case DeclaredSent = 'declared_sent';
    case Confirmed = 'confirmed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Disputed = 'disputed';
    case Rejected = 'rejected';

    /**
     * The payer's quantity is still set aside: the payment can still be confirmed or released.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Reserved, self::DeclaredSent, self::Disputed], true);
    }
}
