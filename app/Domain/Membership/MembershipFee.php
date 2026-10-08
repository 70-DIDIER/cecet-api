<?php

namespace App\Domain\Membership;

use App\Domain\Identity\Enums\PersonType;
use App\Domain\Settings\SettingsService;
use App\Models\User;
use DateTimeInterface;

/**
 * Computes the initial membership fee server-side from the configured, versioned settings.
 */
class MembershipFee
{
    public const CURRENCY = 'XOF';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{amount: int, setting_key: string}
     */
    public function for(User $member, ?DateTimeInterface $at = null): array
    {
        $key = $member->person_type === PersonType::Legal ? 'membership.fee.legal_person' : 'membership.fee.natural_person';

        return ['amount' => $this->settings->integer($key, $at), 'setting_key' => $key];
    }
}
