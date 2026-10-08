<?php

namespace App\Domain\Payment\Enums;

enum PaymentIntentStatus: string
{
    case Open = 'open';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';
}
