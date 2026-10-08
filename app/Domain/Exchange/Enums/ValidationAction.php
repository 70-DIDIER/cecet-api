<?php

namespace App\Domain\Exchange\Enums;

enum ValidationAction: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Disputed = 'disputed';
}
