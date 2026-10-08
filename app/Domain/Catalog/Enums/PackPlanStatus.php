<?php

namespace App\Domain\Catalog\Enums;

enum PackPlanStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Suspended = 'suspended';
    case Retired = 'retired';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Suspended, self::Retired],
            self::Suspended => [self::Published, self::Retired],
            self::Retired => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isSubscribable(): bool
    {
        return $this === self::Published;
    }
}
