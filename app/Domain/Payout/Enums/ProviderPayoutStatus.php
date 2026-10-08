<?php

namespace App\Domain\Payout\Enums;

enum ProviderPayoutStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
