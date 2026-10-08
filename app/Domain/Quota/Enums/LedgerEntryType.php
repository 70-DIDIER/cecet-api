<?php

namespace App\Domain\Quota\Enums;

enum LedgerEntryType: string
{
    case Credit = 'credit';
    case Reserve = 'reserve';
    case Release = 'release';
    case Consume = 'consume';
    case Expire = 'expire';
    case Adjust = 'adjust';
}
