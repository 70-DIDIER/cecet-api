<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * YEM quantities as fixed-point decimal strings (6 decimals). Never floats: sums must be exact.
 */
final class YemQuantity
{
    public const SCALE = 6;

    private const PATTERN = '/^\d{1,22}(\.\d{1,6})?$/';

    private const SIGNED_PATTERN = '/^-?\d{1,22}(\.\d{1,6})?$/';

    public static function isValid(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && preg_match(self::PATTERN, self::asString($value)) === 1;
    }

    /**
     * A non-negative quantity, normalized to 6 decimals.
     */
    public static function of(string|int|float $value): string
    {
        $text = self::asString($value);

        if (preg_match(self::PATTERN, $text) !== 1) {
            throw new InvalidArgumentException("Invalid YEM quantity: {$text}");
        }

        return bcadd(self::numeric($text), '0', self::SCALE);
    }

    /**
     * A quantity that may be negative (manual adjustments), normalized to 6 decimals.
     */
    public static function signed(string $value): string
    {
        if (preg_match(self::SIGNED_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid YEM quantity: {$value}");
        }

        return bcadd(self::numeric($value), '0', self::SCALE);
    }

    public static function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::numeric($a), self::numeric($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::numeric($a), self::numeric($b), self::SCALE);
    }

    public static function times(string $quantity, int $factor): string
    {
        return bcmul(self::numeric($quantity), (string) $factor, self::SCALE);
    }

    /**
     * Counter-value in whole francs, truncated: a fraction of a franc is never owed.
     */
    public static function toXof(string $quantity, int $ratePerYem): int
    {
        return (int) bcmul(self::numeric($quantity), (string) $ratePerYem, 0);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::numeric($a), self::numeric($b), self::SCALE);
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isZero(string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function negate(string $value): string
    {
        return bcmul(self::numeric($value), '-1', self::SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function numeric(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Not a number: {$value}");
        }

        return $value;
    }

    private static function asString(string|int|float $value): string
    {
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, self::SCALE, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}
