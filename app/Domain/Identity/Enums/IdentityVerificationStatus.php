<?php

namespace App\Domain\Identity\Enums;

enum IdentityVerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
