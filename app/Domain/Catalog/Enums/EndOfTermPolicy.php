<?php

namespace App\Domain\Catalog\Enums;

/**
 * What happens to the unused quota when the last period of a pack ends (open question PO-05).
 */
enum EndOfTermPolicy: string
{
    case Expire = 'expire';
    case Keep = 'keep';
}
