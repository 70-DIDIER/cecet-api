<?php

namespace App\Domain\Membership\Enums;

enum MembershipStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
}
