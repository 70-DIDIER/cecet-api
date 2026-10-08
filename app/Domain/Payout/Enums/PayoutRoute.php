<?php

namespace App\Domain\Payout\Enums;

enum PayoutRoute: string
{
    case MobileMoney = 'mobile_money';
    case Manual = 'manual';
}
