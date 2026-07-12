<?php
defined('ABSPATH') || exit;

class BGC_Rates {
    public static function set(string $courier, string $method, float $price, string $currency): void {
        global $wpdb; $t = $wpdb->prefix . 'bgc_standard_rates';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (courier,method,price,currency,updated_at) VALUES (%s,%s,%f,%s,NOW())
             ON DUPLICATE KEY UPDATE price=VALUES(price),currency=VALUES(currency),updated_at=NOW()",
            $courier, $method, $price, $currency));
    }
    public static function get(string $courier, string $method): ?float {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT price FROM {$wpdb->prefix}bgc_standard_rates WHERE courier=%s AND method=%s", $courier, $method));
        return $v === null ? null : (float) $v;
    }
}
