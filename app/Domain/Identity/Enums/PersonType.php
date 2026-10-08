<?php

namespace App\Domain\Identity\Enums;

enum PersonType: string
{
    case Natural = 'natural';
    case Legal = 'legal';
}
