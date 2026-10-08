<?php

namespace App\Domain\Identity\Enums;

enum UserKind: string
{
    case Member = 'member';
    case Staff = 'staff';
}
