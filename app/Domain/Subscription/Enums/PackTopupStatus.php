<?php

namespace App\Domain\Subscription\Enums;

enum PackTopupStatus: string
{
    case PendingPayment = 'pending_payment';
    case Credited = 'credited';
    case Canceled = 'canceled';
}
