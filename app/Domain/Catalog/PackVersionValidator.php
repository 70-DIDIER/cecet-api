<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Enums\EndOfTermPolicy;
use App\Domain\Catalog\Enums\PackFamily;
use App\Domain\Catalog\Enums\PeriodUnit;
use App\Domain\Identity\Enums\PersonType;
use App\Domain\Settings\SettingsService;
use App\Support\YemQuantity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes the terms of a pack version according to its family.
 *
 * Periodic families (Vendeur, Consommateur, Marchand) require a period and a quota per period; the Personnel
 * pack is balance-based and refuses any period or quota. The end-of-term policy has no default on purpose:
 * the cooperative must decide it (open question PO-05).
 */
class PackVersionValidator
{
    private const BASIS_POINTS = 10000;

    private const DEFAULT_SALE_RATE_KEY = 'rates.sale_xof_per_yem';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> attributes ready to persist on a PackVersion
     *
     * @throws ValidationException
     */
    public function validate(PackFamily $family, array $input): array
    {
        $periodic = $family->isPeriodic();
        $input = $this->deriveConsumerTerms($family, $input);

        $rules = [
            'price_xof' => ['required', 'integer', 'min:0'],
            'cumulative' => [$periodic ? 'required' : 'prohibited', 'boolean'],
            'end_of_term' => [$periodic ? 'required' : 'prohibited', Rule::enum(EndOfTermPolicy::class)],
            'period' => [$periodic ? 'required' : 'prohibited', 'array'],
            'period.unit' => [$periodic ? 'required' : 'prohibited', Rule::enum(PeriodUnit::class)],
            'period.every' => [$periodic ? 'required' : 'prohibited', 'integer', 'min:1', 'max:366'],
            'period.cycles' => [$periodic ? 'required' : 'prohibited', 'integer', 'min:1', 'max:240'],
            'quota_per_period' => [$periodic ? 'required' : 'prohibited', 'regex:'.'/^\d{1,22}(\.\d{1,6})?$/'],
            'min_operation' => ['nullable', 'regex:'.'/^\d{1,22}(\.\d{1,6})?$/'],
            'max_operation' => ['nullable', 'regex:'.'/^\d{1,22}(\.\d{1,6})?$/'],
            'eligible_person_types' => ['nullable', 'array', 'min:1'],
            'eligible_person_types.*' => ['string', Rule::enum(PersonType::class), 'distinct'],
            'rules' => ['nullable', 'array'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];

        foreach ($family->extraRules() as $key => $keyRules) {
            $rules["rules.{$key}"] = ['sometimes', ...$keyRules];
        }

        $validator = Validator::make($this->stringifyDecimals($input), $rules);

        $validator->after(function ($validator) use ($family, $input): void {
            $unknown = array_diff(array_keys($input['rules'] ?? []), array_keys($family->extraRules()));

            foreach ($unknown as $key) {
                $validator->errors()->add("rules.{$key}", "La règle « {$key} » n'existe pas pour la famille {$family->value}.");
            }

            $min = $input['min_operation'] ?? null;
            $max = $input['max_operation'] ?? null;

            if ($min !== null && $max !== null && YemQuantity::isValid($min) && YemQuantity::isValid($max)
                && YemQuantity::compare(YemQuantity::of($min), YemQuantity::of($max)) > 0) {
                $validator->errors()->add('max_operation', 'Le maximum d\'une opération doit être supérieur ou égal au minimum.');
            }

            $quota = $input['quota_per_period'] ?? null;

            if ($quota !== null && YemQuantity::isValid($quota) && ! YemQuantity::isPositive(YemQuantity::of($quota))) {
                $validator->errors()->add('quota_per_period', 'Le quota par période doit être strictement positif.');
            }

            if ($max !== null && $quota !== null && YemQuantity::isValid($max) && YemQuantity::isValid($quota)
                && YemQuantity::compare(YemQuantity::of($max), YemQuantity::of($quota)) > 0) {
                $validator->errors()->add('max_operation', 'Le maximum d\'une opération ne peut pas dépasser le quota d\'une période.');
            }
        });

        $data = $validator->validate();

        return [
            'price_xof' => (int) $data['price_xof'],
            'period_unit' => $periodic ? $data['period']['unit'] : null,
            'period_every' => $periodic ? (int) $data['period']['every'] : null,
            'period_cycles' => $periodic ? (int) $data['period']['cycles'] : null,
            'quota_per_period' => $periodic ? YemQuantity::of($data['quota_per_period']) : null,
            'cumulative' => $periodic ? (bool) $data['cumulative'] : false,
            'end_of_term' => $periodic ? $data['end_of_term'] : null,
            'min_operation' => isset($data['min_operation']) ? YemQuantity::of($data['min_operation']) : null,
            'max_operation' => isset($data['max_operation']) ? YemQuantity::of($data['max_operation']) : null,
            'eligible_person_types' => array_values($data['eligible_person_types'] ?? $family->defaultEligiblePersonTypes()),
            'rules' => $data['rules'] ?? [],
            'reason' => trim($data['reason']),
        ];
    }

    /**
     * A Consommateur pack can be described as on the former site: a reference value and a discount. The member then
     * pays the reference value minus the discount, and receives the reference value in YEM at the sale rate, spread
     * evenly over the periods (C1: 300 000 F at 15 % = 255 000 F paid, 300 000 / 650 / 12 = 38.461538 YEM a month).
     * The rate used is snapshotted in the rules; explicit price or quota values still take precedence.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function deriveConsumerTerms(PackFamily $family, array $input): array
    {
        $rules = is_array($input['rules'] ?? null) ? $input['rules'] : [];
        $reference = $rules['reference_value_xof'] ?? null;
        $discount = $rules['discount_bps'] ?? 0;

        if ($family !== PackFamily::Consommateur || ! is_int($reference) || $reference < 1 || ! is_int($discount) || $discount < 0 || $discount >= self::BASIS_POINTS) {
            return $input;
        }

        if (! isset($input['price_xof'])) {
            $input['price_xof'] = intdiv($reference * (self::BASIS_POINTS - $discount) + self::BASIS_POINTS / 2, self::BASIS_POINTS);
        }

        $cycles = $input['period']['cycles'] ?? null;

        if (! isset($input['quota_per_period']) && is_int($cycles) && $cycles > 0) {
            $rateKey = is_string($rules['rate_setting_key'] ?? null) ? $rules['rate_setting_key'] : self::DEFAULT_SALE_RATE_KEY;
            $rate = $this->settings->integer($rateKey);

            $input['quota_per_period'] = bcdiv((string) $reference, (string) ($rate * $cycles), YemQuantity::SCALE);
            $input['rules'] = [...$rules, 'rate_setting_key' => $rateKey, 'rate_xof_per_yem' => $rate];
        }

        return $input;
    }

    /**
     * JSON numbers such as 125.5 arrive as floats; the validator works on their exact decimal text.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function stringifyDecimals(array $input): array
    {
        foreach (['quota_per_period', 'min_operation', 'max_operation'] as $key) {
            if (isset($input[$key]) && (is_int($input[$key]) || is_float($input[$key]))) {
                $input[$key] = YemQuantity::isValid($input[$key]) ? YemQuantity::of($input[$key]) : (string) $input[$key];
            }
        }

        return $input;
    }
}
