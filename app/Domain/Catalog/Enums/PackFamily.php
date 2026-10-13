<?php

namespace App\Domain\Catalog\Enums;

use App\Domain\Identity\Enums\PersonType;

enum PackFamily: string
{
    case Vendeur = 'VENDEUR';
    case Consommateur = 'CONSOMMATEUR';
    case Personnel = 'PERSONNEL';
    case Marchand = 'MARCHAND';

    /**
     * Periodic families grant a quota every period; the others (Personnel) are balance-based, on demand.
     */
    public function isPeriodic(): bool
    {
        return $this !== self::Personnel;
    }

    /**
     * @return list<string>
     */
    public function defaultEligiblePersonTypes(): array
    {
        return $this === self::Marchand
            ? [PersonType::Legal->value]
            : [PersonType::Natural->value, PersonType::Legal->value];
    }

    /**
     * Family-specific keys accepted in the free-form "rules" object, with their validation.
     *
     * @return array<string, list<string>>
     */
    public function extraRules(): array
    {
        return match ($this) {
            self::Consommateur => [
                'first_credit_delay_periods' => ['integer', 'min:0', 'max:12'],
                'reference_value_xof' => ['integer', 'min:1'],
                'rate_setting_key' => ['string', 'in:rates.purchase_xof_per_yem,rates.sale_xof_per_yem,rates.transfer_xof_per_yem'],
                'rate_xof_per_yem' => ['integer', 'min:1'],
            ],
            self::Vendeur => [
                'first_credit_delay_periods' => ['integer', 'min:0', 'max:12'],
                'rate_setting_key' => ['string', 'in:rates.purchase_xof_per_yem,rates.sale_xof_per_yem,rates.transfer_xof_per_yem'],
            ],
            self::Marchand => [
                'first_credit_delay_periods' => ['integer', 'min:0', 'max:12'],
                'rate_setting_key' => ['string', 'in:rates.purchase_xof_per_yem,rates.sale_xof_per_yem,rates.transfer_xof_per_yem'],
            ],
            self::Personnel => [
                'min_topup_xof' => ['integer', 'min:1'],
                'rate_setting_key' => ['string', 'in:rates.purchase_xof_per_yem,rates.sale_xof_per_yem,rates.transfer_xof_per_yem'],
            ],
        };
    }
}
