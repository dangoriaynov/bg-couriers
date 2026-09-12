<?php
defined('ABSPATH') || exit;

// Data-access layer for the plugin's own custom table (wp_bgcouriers_standard_rates). Queries use $wpdb->prepare()
// with a $wpdb->prefix table name (table names cannot be bound as placeholders); the table is the rate cache
// itself, so object-cache layering adds nothing. Silence the custom-table DB sniffs for this file.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix, not user input; this class IS the cache

class BGCouriers_Rates {
    public static function set(string $courier, string $method, float $price, string $currency): void {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_standard_rates';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (courier,method,price,currency,updated_at) VALUES (%s,%s,%f,%s,NOW())
             ON DUPLICATE KEY UPDATE price=VALUES(price),currency=VALUES(currency),updated_at=NOW()",
            $courier, $method, $price, $currency));
    }
    /**
     * The daily reference price, or null when there is not one IN THIS CURRENCY.
     *
     * The currency has been a column here since the table was created and set() has always written it;
     * nothing ever read it back. A price is a number and the unit it is in, and this table holds one row
     * per courier and method - the unique key says so - so it cannot carry both. A row left over from
     * before a shop changed its currency is therefore not a cheap price or a dear one, it is no
     * reference at all, and saying so lets the caller fall back to a figure the merchant chose instead
     * of showing a lev number with a euro sign on it.
     *
     * The currency is required and not defaulted on purpose: a caller that does not know which currency
     * it is asking about has no business being handed a price.
     */
    public static function get(string $courier, string $method, string $currency): ?float {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT price FROM {$wpdb->prefix}bgcouriers_standard_rates WHERE courier=%s AND method=%s AND currency=%s",
            $courier, $method, $currency));
        return $v === null ? null : (float) $v;
    }
}
