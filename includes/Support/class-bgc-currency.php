<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Currency {
    const PEG = 1.95583; // fixed BGN per 1 EUR (BG euro adoption)

    public static function convert(float $amount, string $from, string $to): float {
        if ($from === $to) { return $amount; }
        return $from === 'BGN' ? $amount / self::PEG : $amount * self::PEG;
    }

    private static function symbol(string $cur): string {
        return $cur === 'EUR' ? '€' : 'лв.';
    }

    public static function dual(float $amount, string $primary, bool $enabled): string {
        $main = number_format($amount, 2) . ' ' . self::symbol($primary);
        if (!$enabled) { return $main; }
        $other = $primary === 'BGN' ? 'EUR' : 'BGN';
        $val = number_format(self::convert($amount, $primary, $other), 2);
        return $main . ' (' . $val . ' ' . self::symbol($other) . ')';
    }

    /** Whether dual BGN/EUR display is turned on in the settings. */
    public static function enabled(): bool {
        return get_option('bgc_dual_currency', 'no') === 'yes';
    }

    /**
     * A store-currency amount as WC formats it, plus the pegged other currency in brackets when dual
     * display is on - e.g. "3,99 €  (7,80 лв.)". HTML. Peg applies only to EUR⇄BGN.
     */
    public static function dual_store(float $amount): string {
        $main = function_exists('wc_price') ? wc_price($amount) : (number_format($amount, 2) . ' €');
        if (!self::enabled()) { return $main; }
        $store = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        if ($store !== 'EUR' && $store !== 'BGN') { return $main; }
        $other = $store === 'BGN' ? 'EUR' : 'BGN';
        return $main . ' <span class="bgc-dual">(' . self::fmt(self::convert($amount, $store, $other), $other) . ')</span>';
    }

    /** Format an amount with the store's separators + currency symbol, e.g. "7,80 лв.". */
    public static function fmt(float $amount, string $cur): string {
        $dec = function_exists('wc_get_price_decimal_separator') ? wc_get_price_decimal_separator() : '.';
        $tho = function_exists('wc_get_price_thousand_separator') ? wc_get_price_thousand_separator() : '';
        return number_format($amount, 2, $dec, $tho) . ' ' . self::symbol($cur);
    }
}
