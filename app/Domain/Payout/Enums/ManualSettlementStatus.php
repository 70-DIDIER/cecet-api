<?php

namespace App\Domain\Payout\Enums;

enum ManualSettlementStatus: string
{
    case PendingApproval = 'pending_approval';
    case Applied = 'applied';
    case Rejected = 'rejected';
}
