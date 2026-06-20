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
}
