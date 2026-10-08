<?php

namespace App\Domain\Payment\Enums;

/**
 * Provider-neutral transaction status.
 */
enum ProviderPaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
    case Unknown = 'unknown';
}
