<?php

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case PendingEmailVerification = 'pending_email_verification';
    case PendingKyc = 'pending_kyc';
    case PendingPayment = 'pending_payment';
    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * Whether an account in this status may obtain an access token.
     */
    public function canAuthenticate(): bool
    {
        return in_array($this, [self::PendingKyc, self::PendingPayment, self::Active], true);
    }
}
