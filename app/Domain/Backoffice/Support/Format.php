<?php

namespace App\Domain\Backoffice\Support;

use App\Support\YemQuantity;
use Carbon\CarbonInterface;

/**
 * Display formats of the back-office (French conventions). Amounts are never converted through floats.
 */
final class Format
{
    private const NBSP = "\u{00A0}";

    public static function xof(int|string|null $amount): string
    {
        return self::group((string) ($amount ?? 0)).self::NBSP.'FCFA';
    }

    public static function number(int|string|null $value): string
    {
        return self::group((string) ($value ?? 0));
    }

    /**
     * A YEM quantity (6 decimals in the ledger) shown with at most $decimals decimals.
     */
    public static function yem(?string $quantity, int $decimals = 2): string
    {
        $value = bcadd(YemQuantity::numeric($quantity ?? '0'), '0', $decimals);
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return self::group($integer).($fraction !== '' ? ','.$fraction : '').self::NBSP.'YEM';
    }

    /**
     * Basis points as a percentage (1500 -> 15 %).
     */
    public static function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 1, ',', ''), '0'), ',').self::NBSP.'%';
    }

    public static function dateTime(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->format('d/m/Y H:i');
    }

    public static function date(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->format('d/m/Y');
    }

    private static function group(string $digits): string
    {
        $negative = str_starts_with($digits, '-');
        $digits = ltrim($digits, '-');

        // Groups of three digits from the right; each group is turned back individually (the separator is multi-byte).
        $groups = array_map('strrev', str_split(strrev($digits), 3));

        return ($negative ? '-' : '').implode(self::NBSP, array_reverse($groups));
    }
}
