<?php
defined('ABSPATH') || exit;

// Data-access layer for the plugin's own custom table (wp_bgc_standard_rates). Queries use $wpdb->prepare()
// with a $wpdb->prefix table name (table names cannot be bound as placeholders); the table is the rate cache
// itself, so object-cache layering adds nothing. Silence the custom-table DB sniffs for this file.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix, not user input; this class IS the cache

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
