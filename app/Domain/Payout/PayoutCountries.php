<?php

namespace App\Domain\Payout;

use App\Domain\Settings\SettingsService;
use Illuminate\Validation\ValidationException;

/**
 * Countries in which a member may receive a counter-value, with their dialing code and the national number lengths
 * accepted. The table is the versioned Superadmin setting "payout.countries"; this class turns what the member typed
 * (country, dialing code, number) into one canonical international number (E.164) or refuses it.
 */
class PayoutCountries
{
    private const KEY = 'payout.countries';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<array{code: string, name: string, dial_code: string, national_lengths: list<int>}>
     */
    public function enabled(): array
    {
        return array_values(array_map(
            fn (array $country): array => [
                'code' => strtoupper((string) $country['code']),
                'name' => (string) ($country['name'] ?? $country['code']),
                'dial_code' => ltrim((string) $country['dial_code'], '+'),
                'national_lengths' => array_values(array_map('intval', $country['national_lengths'] ?? [])),
            ],
            array_filter($this->settings->array(self::KEY), fn (mixed $country): bool => is_array($country) && ($country['enabled'] ?? false) === true),
        ));
    }

    /**
     * @return array{code: string, name: string, dial_code: string, national_lengths: list<int>}|null
     */
    public function find(string $code): ?array
    {
        foreach ($this->enabled() as $country) {
            if ($country['code'] === strtoupper($code)) {
                return $country;
            }
        }

        return null;
    }

    public function dialCode(string $code): ?string
    {
        return $this->find($code)['dial_code'] ?? null;
    }

    /**
     * Canonical E.164 number for the typed destination. The number may be national ("90 12 34 56") or already
     * international ("+228 90123456"); the dialing code typed, when there is one, must be the country's.
     *
     * @throws ValidationException
     */
    public function normalize(string $countryCode, ?string $dialCode, string $number, string $field = 'phone'): string
    {
        $country = $this->find($countryCode)
            ?? throw ValidationException::withMessages(['country' => 'Ce pays n’est pas disponible pour la réception des fonds.']);

        $typedDial = $dialCode === null || trim($dialCode) === '' ? null : ltrim(preg_replace('/\s+/', '', $dialCode) ?? '', '+');

        if ($typedDial !== null && $typedDial !== $country['dial_code']) {
            throw ValidationException::withMessages(['dial_code' => "L’indicatif +{$typedDial} ne correspond pas au pays {$country['name']} (+{$country['dial_code']})."]);
        }

        $compact = preg_replace('/[\s().-]+/', '', trim($number)) ?? '';

        if ($compact === '' || preg_match('/^\+?[0-9]+$/', $compact) !== 1) {
            throw ValidationException::withMessages([$field => 'Le numéro ne doit contenir que des chiffres.']);
        }

        if (str_starts_with($compact, '+')) {
            $digits = substr($compact, 1);

            if (! str_starts_with($digits, $country['dial_code'])) {
                throw ValidationException::withMessages([$field => "Le numéro doit commencer par +{$country['dial_code']} pour le pays {$country['name']}."]);
            }

            $national = substr($digits, strlen($country['dial_code']));
        } elseif (str_starts_with($compact, '00'.$country['dial_code'])) {
            $national = substr($compact, 2 + strlen($country['dial_code']));
        } else {
            $national = $compact;
        }

        if ($country['national_lengths'] !== [] && ! in_array(strlen($national), $country['national_lengths'], true)) {
            $expected = implode(' ou ', $country['national_lengths']);

            throw ValidationException::withMessages([$field => "Un numéro {$country['name']} compte {$expected} chiffres (hors indicatif)."]);
        }

        return '+'.$country['dial_code'].$national;
    }
}
