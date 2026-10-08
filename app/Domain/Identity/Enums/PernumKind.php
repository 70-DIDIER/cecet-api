<?php

namespace App\Domain\Identity\Enums;

enum PernumKind: string
{
    case Personal = 'personal';
    case Business = 'business';
}
