<?php

namespace App\Domain\Payout\Enums;

enum PayoutItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
