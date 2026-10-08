<?php

namespace App\Domain\Payment\Enums;

enum PaymentAttemptStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Failed = 'failed';
    case Mismatch = 'mismatch';

    /**
     * Final statuses never change again. EXPIRED is a local decision only: the provider may still approve it.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Declined, self::Canceled, self::Failed, self::Mismatch], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this, [self::Created, self::Pending], true);
    }
}
