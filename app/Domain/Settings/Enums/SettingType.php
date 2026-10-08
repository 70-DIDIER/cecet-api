<?php

namespace App\Domain\Settings\Enums;

enum SettingType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case String = 'string';
    case Boolean = 'boolean';
    case Json = 'json';

    /**
     * Validation rules implied by the type, applied before the definition's own rules.
     *
     * @return list<string>
     */
    public function baseRules(): array
    {
        return match ($this) {
            self::Integer => ['integer'],
            self::Decimal => ['numeric'],
            self::String => ['string'],
            self::Boolean => ['boolean'],
            self::Json => ['array'],
        };
    }

    /**
     * Normalizes a validated value to its canonical stored form.
     * Decimals are kept as strings to avoid binary floating point.
     */
    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Integer => (int) $value,
            self::Decimal => (string) $value,
            self::String => (string) $value,
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::Json => $value,
        };
    }
}
