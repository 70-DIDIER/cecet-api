<?php

namespace App\Domain\Audit\Enums;

enum SecuritySeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
