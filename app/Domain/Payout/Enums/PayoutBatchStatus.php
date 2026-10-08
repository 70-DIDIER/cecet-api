<?php

namespace App\Domain\Payout\Enums;

enum PayoutBatchStatus: string
{
    case AwaitingDispatch = 'awaiting_dispatch';
    case Processing = 'processing';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Failed = 'failed';
    case ManualRequired = 'manual_required';
}
