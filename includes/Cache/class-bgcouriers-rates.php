<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix, not user input; this class IS the cache

class BGCouriers_Rates {
    public static function set(string $courier, string $method, string $zone, float $price, string $currency): void {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_standard_rates';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (courier,method,zone,price,currency,updated_at) VALUES (%s,%s,%s,%f,%s,NOW())
             ON DUPLICATE KEY UPDATE price=VALUES(price),currency=VALUES(currency),updated_at=NOW()",
            $courier, $method, BGCouriers_Zones::sanitize($zone), $price, $currency));
    }
    /** The daily reference price, or null when there is none in this currency. */
    public static function get(string $courier, string $method, string $zone, string $currency): ?float {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT price FROM {$wpdb->prefix}bgcouriers_standard_rates WHERE courier=%s AND method=%s AND zone=%s AND currency=%s",
            $courier, $method, BGCouriers_Zones::sanitize($zone), $currency));
        // A table not re-seeded since the zone column arrived has only COUNTRY rows, so Sofia falls back to it.
        if ($v === null && $zone !== BGCouriers_Zones::COUNTRY) {
            return self::get($courier, $method, BGCouriers_Zones::COUNTRY, $currency);
        }
        return $v === null ? null : (float) $v;
    }
}
