<?php
defined('ABSPATH') || exit;

/** Phone numbers in E.164, for the couriers that demand it (BOX NOW). */
class BGCouriers_Phone {

    const DEFAULT_CC = '359';

    const CC = ['BG' => '359', 'RO' => '40'];

    public static function cc_for(string $iso): string {
        return self::CC[strtoupper(trim($iso))] ?? self::DEFAULT_CC;
    }

    /** E.164 (+359888123456), or '' when nothing usable is in the input. */
    public static function e164(string $raw, string $country_cc = self::DEFAULT_CC): string {
        $s = preg_replace('/[\s\-().\/]|(?:ext|доб)\.?\s*\d+$/iu', '', trim($raw));
        if ($s === null || $s === '') { return ''; }

        $plus = strpos($s, '+') === 0;
        $d    = preg_replace('/\D+/', '', $s);
        if ($d === null || $d === '') { return ''; }

        if ($plus) {
            return self::plausible($d) ? '+' . $d : '';
        }
        if (strpos($d, '00') === 0) {
            $d = substr($d, 2);
            return self::plausible($d) ? '+' . $d : '';
        }
        if (strpos($d, $country_cc) === 0 && strlen($d) > strlen($country_cc) + 6) {
            return self::plausible($d) ? '+' . $d : '';
        }
        $d = ltrim($d, '0');
        if ($d === '') { return ''; }
        $full = $country_cc . $d;
        return self::plausible($full) ? '+' . $full : '';
    }

    private static function plausible(string $digits): bool {
        $len = strlen($digits);
        return $len >= 8 && $len <= 15 && trim($digits, '0') !== '';
    }

    public static function usable(string $raw, string $country_cc = self::DEFAULT_CC): bool {
        return self::e164($raw, $country_cc) !== '';
    }
}
