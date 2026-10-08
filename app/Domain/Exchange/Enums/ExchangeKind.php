<?php

namespace App\Domain\Exchange\Enums;

enum ExchangeKind: string
{
    case Seller = 'seller';
    case MerchantRedemption = 'merchant_redemption';
}
