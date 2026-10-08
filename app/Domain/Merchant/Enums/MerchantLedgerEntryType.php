<?php

namespace App\Domain\Merchant\Enums;

enum MerchantLedgerEntryType: string
{
    case Credit = 'credit';
    case Reserve = 'reserve';
    case Release = 'release';
    case Consume = 'consume';
    case Adjust = 'adjust';
}
