<?php

namespace App\Domain\Identity\Enums;

enum PernumStatus: string
{
    case Declared = 'declared';
    case Verified = 'verified';
    case Disputed = 'disputed';
    case Revoked = 'revoked';
}
