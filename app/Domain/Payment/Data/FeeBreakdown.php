<?php

namespace App\Domain\Payment\Data;

/**
 * What a customer pays so that the cooperative receives its net amount.
 */
final readonly class FeeBreakdown
{
    /**
     * @param  int  $net  amount the CECT must receive (XOF)
     * @param  int  $fee  provider fees added on top, borne by the customer (XOF)
     * @param  int  $total  amount charged to the customer (XOF) = net + fee
     * @param  array<string, mixed>  $rule  snapshot of the fee rule that produced this breakdown
     */
    public function __construct(
        public int $net,
        public int $fee,
        public int $total,
        public array $rule,
    ) {}
}
