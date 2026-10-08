<?php

namespace App\Domain\Payment\Enums;

enum PaymentPurpose: string
{
    case Membership = 'membership';
    case PackSubscription = 'pack_subscription';
    case PackTopup = 'pack_topup';
}
