<?php

namespace App\Domain\Payment\Enums;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
