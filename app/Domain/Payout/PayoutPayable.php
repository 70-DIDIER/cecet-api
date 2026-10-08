<?php

namespace App\Domain\Payout;

use App\Domain\Payout\Models\PayoutBatch;

/**
 * An operation that generated a payout and must react once it is fully paid (e.g. the exchange is completed).
 */
interface PayoutPayable
{
    public function onPayoutPaid(PayoutBatch $batch): void;
}
