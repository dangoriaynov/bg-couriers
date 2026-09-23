<?php
defined('ABSPATH') || exit;

// Data-access layer for the plugin's own custom table (wp_bgcouriers_standard_rates). Queries use $wpdb->prepare()
// with a $wpdb->prefix table name (table names cannot be bound as placeholders); the table is the rate cache
// itself, so object-cache layering adds nothing. Silence the custom-table DB sniffs for this file.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix, not user input; this class IS the cache

class BGCouriers_Rates {
    /**
     * A reference price for one courier, method and price zone (BGCouriers_Zones).
     *
     * The zone is required and not defaulted for the same reason the currency is not: a caller that does
     * not know which zone it measured has written down a number nobody can read back safely, and the
     * cheaper of the two zones is exactly the wrong thing to guess.
     */
    public static function set(string $courier, string $method, string $zone, float $price, string $currency): void {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_standard_rates';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (courier,method,zone,price,currency,updated_at) VALUES (%s,%s,%s,%f,%s,NOW())
             ON DUPLICATE KEY UPDATE price=VALUES(price),currency=VALUES(currency),updated_at=NOW()",
            $courier, $method, BGCouriers_Zones::sanitize($zone), $price, $currency));
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
    public static function get(string $courier, string $method, string $zone, string $currency): ?float {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT price FROM {$wpdb->prefix}bgcouriers_standard_rates WHERE courier=%s AND method=%s AND zone=%s AND currency=%s",
            $courier, $method, BGCouriers_Zones::sanitize($zone), $currency));
        // A site that has not re-seeded since the zone column arrived has one row per courier and method,
        // carrying the DEFAULT zone - so a Sofia read finds nothing until the daily run fills it in. The
        // country figure is the honest stand-in for it: quoted for a longer route, so never the cheaper of
        // the two, and it is what this site was already showing for Sofia yesterday.
        if ($v === null && $zone !== BGCouriers_Zones::COUNTRY) {
            return self::get($courier, $method, BGCouriers_Zones::COUNTRY, $currency);
        }
        return $v === null ? null : (float) $v;
    }
}
