<?php

namespace App\Domain\Quota\Enums;

enum LedgerEntryType: string
{
    /** YEM bought (PURCHASE_CREDIT): period allowance or top-up. */
    case Credit = 'credit';
    /** YEM offered as cashback (CASHBACK_CREDIT), never mixed with the YEM bought. */
    case CashbackCredit = 'cashback_credit';
    case Reserve = 'reserve';
    case Release = 'release';
    case Consume = 'consume';
    case Expire = 'expire';
    case Adjust = 'adjust';
}
