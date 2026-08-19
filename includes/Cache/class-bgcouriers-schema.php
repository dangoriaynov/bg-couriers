<?php
defined('ABSPATH') || exit;

class BGCouriers_Schema {
    /**
     * The tables the plugin owns. dbDelta is idempotent, so this doubles as the upgrade path (see the
     * db-version check in bg-couriers.php).
     *
     * `country` (ISO-3166 alpha-2) filters the towns and offices a checkout may show; it is NOT part of
     * any identity. A courier's own ids stay unique across countries on their own - Speedy prefixes a
     * Romanian site id with the country id (642xxxxxxx) - so `UNIQUE KEY (courier, city_id)` and
     * `(courier, office_id)` still hold, and every row that existed before this column is Bulgarian,
     * which is exactly what the DEFAULT gives it.
     */
    public static function create(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;
        dbDelta("CREATE TABLE {$p}bgcouriers_cities (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 courier VARCHAR(20) NOT NULL,
 country CHAR(2) NOT NULL DEFAULT 'BG',
 city_id BIGINT NOT NULL,
 name VARCHAR(190) NOT NULL,
 name_lat VARCHAR(190) NOT NULL DEFAULT '',
 post_code VARCHAR(12) NOT NULL DEFAULT '',
 region VARCHAR(190) NOT NULL DEFAULT '',
 lat DECIMAL(9,6) NULL,
 lng DECIMAL(9,6) NULL,
 sync_run VARCHAR(32) NOT NULL DEFAULT '',
 updated_at DATETIME NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY courier_city (courier, city_id),
 KEY courier_name (courier, name(20)),
 KEY courier_post (courier, post_code),
 KEY courier_country (courier, country)
) {$charset};");
        dbDelta("CREATE TABLE {$p}bgcouriers_offices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 courier VARCHAR(20) NOT NULL,
 country CHAR(2) NOT NULL DEFAULT 'BG',
 office_id BIGINT NOT NULL,
 code VARCHAR(20) NOT NULL DEFAULT '',
 city_id BIGINT NOT NULL,
 type VARCHAR(10) NOT NULL DEFAULT 'office',
 name VARCHAR(190) NOT NULL,
 address VARCHAR(255) NOT NULL DEFAULT '',
 lat DECIMAL(9,6) NULL,
 lng DECIMAL(9,6) NULL,
 sync_run VARCHAR(32) NOT NULL DEFAULT '',
 updated_at DATETIME NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY courier_office (courier, office_id),
 KEY courier_city (courier, city_id),
 KEY courier_country (courier, country)
) {$charset};");
        dbDelta("CREATE TABLE {$p}bgcouriers_standard_rates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 courier VARCHAR(20) NOT NULL,
 method VARCHAR(10) NOT NULL,
 price DECIMAL(10,2) NOT NULL,
 currency VARCHAR(3) NOT NULL DEFAULT 'BGN',
 updated_at DATETIME NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY courier_method (courier, method)
) {$charset};");
    }
}
