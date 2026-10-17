<?php

namespace App\Domain\Payment;

/**
 * Country-aware TEST mobile-money catalogue.
 * UEMOA: https://www.bceao.int/fr/content/etablissements-de-monnaie-electronique
 * Africa: https://www.gsma.com/mobile-money-metrics/
 * Country coverage is not a claim of banking partnerships or real payment rails.
 */
final class AfricanMobileMoneyCatalog
{
    public const UEMOA = ['BJ', 'BF', 'CI', 'GW', 'ML', 'NE', 'SN', 'TG'];
    public const CALLING_CODES = [
        'DZ' => '213',
        'AO' => '244',
        'BJ' => '229',
        'BW' => '267',
        'BF' => '226',
        'BI' => '257',
        'CV' => '238',
        'CM' => '237',
        'CF' => '236',
        'TD' => '235',
        'KM' => '269',
        'CG' => '242',
        'CD' => '243',
        'CI' => '225',
        'DJ' => '253',
        'EG' => '20',
        'GQ' => '240',
        'ER' => '291',
        'SZ' => '268',
        'ET' => '251',
        'GA' => '241',
        'GM' => '220',
        'GH' => '233',
        'GN' => '224',
        'GW' => '245',
        'KE' => '254',
        'LS' => '266',
        'LR' => '231',
        'LY' => '218',
        'MG' => '261',
        'MW' => '265',
        'ML' => '223',
        'MR' => '222',
        'MU' => '230',
        'MA' => '212',
        'MZ' => '258',
        'NA' => '264',
        'NE' => '227',
        'NG' => '234',
        'RW' => '250',
        'ST' => '239',
        'SN' => '221',
        'SC' => '248',
        'SL' => '232',
        'SO' => '252',
        'ZA' => '27',
        'SS' => '211',
        'SD' => '249',
        'TZ' => '255',
        'TG' => '228',
        'TN' => '216',
        'UG' => '256',
        'ZM' => '260',
        'ZW' => '263',
    ];
    public const COUNTRY_NETWORKS = [
        'TG' => ['TMONEY', 'FLOOZ', 'GOZEM_MONEY', 'CORIS_MONEY'],
        'BJ' => ['MTN_MOMO', 'MOOV_MONEY', 'CELTIIS_CASH', 'CORIS_MONEY', 'ID_MONEY', 'BESTCASH'],
        'BF' => ['ORANGE_MONEY', 'MOOV_MONEY', 'WAVE', 'WIZALL', 'CORIS_MONEY', 'TELECEL_MONEY'],
        'CI' => ['ORANGE_MONEY', 'MTN_MOMO', 'MOOV_MONEY', 'WAVE', 'SAMA_MONEY', 'WIZALL', 'CELPAID_MONEY', 'PE_YA_PAY'],
        'GW' => ['ORANGE_MONEY', 'MTN_MOMO'],
        'ML' => ['ORANGE_MONEY', 'MOOV_MONEY', 'SAMA_MONEY', 'WAVE', 'WIZALL', 'CORIS_MONEY'],
        'NE' => ['AIRTEL_MONEY', 'ORANGE_MONEY', 'FLOOZ', 'WAVE', 'CORIS_MONEY', 'ZAMANI_CASH'],
        'SN' => ['ORANGE_MONEY', 'WAVE', 'FREE_MONEY', 'WIZALL', 'QUICKPAY', 'TOUCHPOINT'],
        'AO' => ['UNITEL_MONEY', 'AFRIMONEY'],
        'BW' => ['ORANGE_MONEY', 'POSO_MONEY'],
        'BI' => ['ECOCASH'],
        'CM' => ['MTN_MOMO', 'ORANGE_MONEY'],
        'CF' => ['ORANGE_MONEY'],
        'TD' => ['AIRTEL_MONEY'],
        'KM' => ['MVOLA'],
        'CG' => ['MTN_MOMO', 'AIRTEL_MONEY'],
        'CD' => ['MPESA', 'AIRTEL_MONEY', 'ORANGE_MONEY', 'AFRIMONEY'],
        'DJ' => ['D_MONEY'],
        'EG' => ['ORANGE_MONEY', 'VODAFONE_CASH', 'ETISALAT_CASH'],
        'SZ' => ['MTN_MOMO'],
        'ET' => ['TELEBIRR', 'MPESA'],
        'GA' => ['AIRTEL_MONEY', 'MOOV_MONEY'],
        'GM' => ['AFRIMONEY'],
        'GH' => ['MTN_MOMO', 'TELECEL_CASH', 'ZEEPAY'],
        'GN' => ['MTN_MOMO', 'ORANGE_MONEY'],
        'KE' => ['MPESA', 'AIRTEL_MONEY'],
        'LS' => ['MPESA'],
        'LR' => ['MTN_MOMO', 'ORANGE_MONEY'],
        'MG' => ['AIRTEL_MONEY', 'MVOLA', 'ORANGE_MONEY'],
        'MW' => ['AIRTEL_MONEY', 'TNM_MPAMBA'],
        'MR' => ['BANKILY', 'MASRVI'],
        'MU' => ['MYT_MONEY'],
        'MA' => ['ORANGE_MONEY', 'ALBARID_PAY'],
        'MZ' => ['MPESA', 'ZEEPAY'],
        'NG' => ['MTN_MOMO', 'AIRTEL_MONEY', 'PALMPAY', 'SMARTCASH'],
        'RW' => ['MTN_MOMO', 'AIRTEL_MONEY', 'ZEEPAY'],
        'SC' => ['AIRTEL_MONEY'],
        'SL' => ['AFRIMONEY', 'ORANGE_MONEY', 'ZEEPAY'],
        'SO' => ['EVC_PLUS', 'ZAAD'],
        'ZA' => ['MTN_MOMO'],
        'SS' => ['MTN_MOMO'],
        'TZ' => ['MPESA', 'AIRTEL_MONEY', 'MIXX', 'TPESA'],
        'TN' => ['ORANGE_MONEY'],
        'UG' => ['MTN_MOMO', 'AIRTEL_MONEY'],
        'ZM' => ['MTN_MOMO', 'AIRTEL_MONEY', 'ZEEPAY'],
        'ZW' => ['ECOCASH'],
    ];
    public const LABELS = [
        'TMONEY' => 'Mixx by Yas',
        'FLOOZ' => 'Flooz',
        'GOZEM_MONEY' => 'Gozem Money',
        'CORIS_MONEY' => 'Coris Money',
        'MTN_MOMO' => 'MTN Mobile Money',
        'MOOV_MONEY' => 'Moov Money',
        'CELTIIS_CASH' => 'Celtiis Cash',
        'ID_MONEY' => 'ID Money',
        'BESTCASH' => 'BestCash',
        'ORANGE_MONEY' => 'Orange Money',
        'WAVE' => 'Wave',
        'WIZALL' => 'Wizall',
        'TELECEL_MONEY' => 'Telecel Money',
        'SAMA_MONEY' => 'Sama Money',
        'CELPAID_MONEY' => 'Celpaid Money',
        'PE_YA_PAY' => 'Pe Ya Pay',
        'AIRTEL_MONEY' => 'Airtel Money',
        'ZAMANI_CASH' => 'Zamani Cash',
        'FREE_MONEY' => 'Free Money',
        'QUICKPAY' => 'QuickPay',
        'TOUCHPOINT' => 'TouchPoint',
        'UNITEL_MONEY' => 'Unitel Money',
        'AFRIMONEY' => 'Afrimoney',
        'POSO_MONEY' => 'Poso Money',
        'ECOCASH' => 'EcoCash',
        'MVOLA' => 'MVola',
        'D_MONEY' => 'D-Money',
        'MPESA' => 'M-Pesa',
        'VODAFONE_CASH' => 'Vodafone Cash',
        'ETISALAT_CASH' => 'Etisalat Cash',
        'TELEBIRR' => 'telebirr',
        'TELECEL_CASH' => 'Telecel Cash',
        'ZEEPAY' => 'Zeepay',
        'TNM_MPAMBA' => 'TNM Mpamba',
        'BANKILY' => 'Bankily',
        'MASRVI' => 'Masrvi',
        'MYT_MONEY' => 'my.t money',
        'ALBARID_PAY' => 'Al Barid Pay',
        'PALMPAY' => 'PalmPay',
        'SMARTCASH' => 'SmartCash',
        'EVC_PLUS' => 'EVC Plus',
        'ZAAD' => 'ZAAD',
        'MIXX' => 'Mixx Tanzania',
        'TPESA' => 'T-Pesa',
    ];
    public const CARD_SIM = 'CARD_SIM';

    public static function methods(bool $simulation): array
    {
        $byNetwork = [];
        foreach (self::COUNTRY_NETWORKS as $country => $networks) {
            foreach ($networks as $code) {
                $byNetwork[$code] ??= [];
                $byNetwork[$code][] = $country;
            }
        }
        $methods = [];
        foreach ($byNetwork as $code => $countries) {
            $methods[] = [
                'code' => $code, 'label' => self::LABELS[$code].($simulation ? ' (simulation)' : ''),
                'channel' => 'mobile_money', 'countries' => $countries,
                'enabled' => $simulation || in_array($code, ['TMONEY', 'FLOOZ'], true),
            ];
        }
        if ($simulation) {
            $methods[] = [
                'code' => self::CARD_SIM,
                'label' => 'Carte bancaire (simulation uniquement)',
                'channel' => 'card',
                // The router constrains it to countries outside African MM coverage.
                'countries' => [], 'enabled' => true,
            ];
        }
        $methods[] = [
            'code' => 'BANK_TRANSFER', 'label' => 'Virement bancaire'.($simulation ? ' (simulation)' : ''),
            'channel' => 'manual', 'countries' => [], 'enabled' => true,
        ];
        $methods[] = [
            'code' => 'CASH', 'label' => 'Espèces'.($simulation ? ' (simulation)' : ''),
            'channel' => 'manual', 'countries' => [], 'enabled' => true,
        ];
        return $methods;
    }

    public static function hasMobileMoney(?string $country): bool
    {
        return $country !== null && ! empty(self::COUNTRY_NETWORKS[$country] ?? []);
    }

    public static function countryFromPhone(?string $phone): ?string
    {
        if (! is_string($phone) || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
            return null;
        }
        $result = null;
        $length = 0;
        foreach (self::CALLING_CODES as $country => $callingCode) {
            if (strlen($callingCode) > $length && str_starts_with($phone, '+'.$callingCode)) {
                $result = $country;
                $length = strlen($callingCode);
            }
        }
        return $result;
    }

    public static function mobileCollectionModes(): array
    {
        $modes = [];
        foreach (self::LABELS as $code => $_label) {
            $modes[$code] = 'psp';
        }
        $modes[self::CARD_SIM] = 'psp';
        $modes['BANK_TRANSFER'] = 'manual';
        $modes['CASH'] = 'manual';
        return $modes;
    }
}
