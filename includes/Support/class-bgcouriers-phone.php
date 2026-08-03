<?php
defined('ABSPATH') || exit;

/**
 * Phone numbers in the form a courier will accept.
 *
 * Bulgarian shoppers type their mobile the Bulgarian way - 0888 123 456 - and most of the couriers here
 * are domestic and take it as it comes. BOX NOW does not: it wants E.164 (+359888123456) and answers a
 * local number with `{"code":"P405"}` and nothing else. Every BOX NOW order would have failed with that,
 * and the message names neither the field nor the reason.
 *
 * So this converts rather than validates. It is applied only where a courier demands it - the domestic
 * APIs are given the number in the form they already accept, because changing what works is how working
 * couriers break.
 */
class BGCouriers_Phone {

    /** Bulgaria. Numbers with no country information at all are assumed to be Bulgarian. */
    const DEFAULT_CC = '359';

    /**
     * A phone number in E.164 (+359888123456), or '' if there is nothing usable in the input.
     *
     * Returning '' rather than a half-converted string is deliberate: the caller can then say "this
     * order has no usable phone number" instead of the courier rejecting the shipment with a code.
     *
     * @param string $raw        What the customer or the merchant typed.
     * @param string $country_cc Country calling code to assume when the number carries none.
     * @return string
     */
    public static function e164(string $raw, string $country_cc = self::DEFAULT_CC): string {
        // Everything a human might type as separators, plus an internal-extension suffix.
        $s = preg_replace('/[\s\-().\/]|(?:ext|доб)\.?\s*\d+$/iu', '', trim($raw));
        if ($s === null || $s === '') { return ''; }

        $plus = strpos($s, '+') === 0;
        $d    = preg_replace('/\D+/', '', $s);          // digits only, from here on
        if ($d === null || $d === '') { return ''; }

        if ($plus) {
            return self::plausible($d) ? '+' . $d : '';
        }
        if (strpos($d, '00') === 0) {                    // 00359... - the other way of writing +
            $d = substr($d, 2);
            return self::plausible($d) ? '+' . $d : '';
        }
        if (strpos($d, $country_cc) === 0 && strlen($d) > strlen($country_cc) + 6) {
            return self::plausible($d) ? '+' . $d : '';  // already carries the country code, unprefixed
        }
        // A national number: one leading trunk zero, dropped when the country code goes on.
        $d = ltrim($d, '0');
        if ($d === '') { return ''; }
        $full = $country_cc . $d;
        return self::plausible($full) ? '+' . $full : '';
    }

    /** True when a number is at least long enough to be a real subscriber number and not a placeholder. */
    private static function plausible(string $digits): bool {
        $len = strlen($digits);
        return $len >= 8 && $len <= 15 && trim($digits, '0') !== '';   // 15 = the E.164 maximum
    }

    /** True when this string can be turned into a number a courier will accept. */
    public static function usable(string $raw, string $country_cc = self::DEFAULT_CC): bool {
        return self::e164($raw, $country_cc) !== '';
    }
}
