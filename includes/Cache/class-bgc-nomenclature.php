<?php
defined('ABSPATH') || exit;

// Dedicated data-access layer for the plugin's OWN custom tables (wp_bgc_cities / wp_bgc_offices). Every
// query uses $wpdb->prepare() with $wpdb->prefix table names (interpolated table names cannot be bound as
// placeholders); these tables ARE the local cache of the couriers' nomenclatures (synced, transient-wrapped
// where hot), so object-cache layering adds nothing. Silence the custom-table DB sniffs for this file.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

class BGC_Nomenclature {
    public static function upsert_cities(string $courier, array $rows, string $run): int {
        global $wpdb; $t = $wpdb->prefix . 'bgc_cities'; $n = 0;
        foreach ($rows as $r) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$t} (courier,city_id,name,name_lat,post_code,region,sync_run,updated_at)
                 VALUES (%s,%d,%s,%s,%s,%s,%s,NOW())
                 ON DUPLICATE KEY UPDATE name=VALUES(name),name_lat=VALUES(name_lat),
                 post_code=VALUES(post_code),region=VALUES(region),sync_run=VALUES(sync_run),updated_at=NOW()",
                $courier, $r['city_id'], $r['name'], $r['name_lat'], $r['post_code'], $r['region'], $run
            ));
            $n++;
        }
        return $n;
    }
    public static function upsert_offices(string $courier, array $rows, string $run): int {
        global $wpdb; $t = $wpdb->prefix . 'bgc_offices'; $n = 0;
        foreach ($rows as $r) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$t} (courier,office_id,code,city_id,type,name,address,lat,lng,sync_run,updated_at)
                 VALUES (%s,%d,%s,%d,%s,%s,%s,%f,%f,%s,NOW())
                 ON DUPLICATE KEY UPDATE code=VALUES(code),city_id=VALUES(city_id),type=VALUES(type),name=VALUES(name),
                 address=VALUES(address),lat=VALUES(lat),lng=VALUES(lng),sync_run=VALUES(sync_run),updated_at=NOW()",
                $courier, $r['office_id'], (string) ($r['code'] ?? ''), $r['city_id'], $r['type'], $r['name'], $r['address'],
                (float) ($r['lat'] ?? 0), (float) ($r['lng'] ?? 0), $run
            ));
            $n++;
        }
        return $n;
    }
    public static function prune(string $courier, string $run): int {
        global $wpdb;
        $a = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bgc_cities WHERE courier=%s AND sync_run<>%s", $courier, $run));
        $b = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bgc_offices WHERE courier=%s AND sync_run<>%s", $courier, $run));
        return (int) $a + (int) $b;
    }
    public static function count(string $courier): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bgc_cities WHERE courier=%s", $courier));
    }
    public static function search_cities(string $courier, string $term, int $limit = 20): array {
        global $wpdb; $t = $wpdb->prefix . 'bgc_cities';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT city_id,name,name_lat,post_code,region FROM {$t}
             WHERE courier=%s AND (name LIKE %s OR name_lat LIKE %s OR post_code LIKE %s) ORDER BY name LIMIT %d",
            $courier, $wpdb->esc_like($term) . '%', $wpdb->esc_like($term) . '%', $wpdb->esc_like($term) . '%', $limit
        ), ARRAY_A);
    }
    public static function city_by_postcode(string $courier, string $code): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT city_id,name,name_lat,post_code,region FROM {$wpdb->prefix}bgc_cities WHERE courier=%s AND post_code=%s LIMIT 1",
            $courier, $code), ARRAY_A);
        return $row ?: null;
    }
    public static function city_by_id(string $courier, int $city_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT city_id,name,name_lat,post_code,region FROM {$wpdb->prefix}bgc_cities WHERE courier=%s AND city_id=%d LIMIT 1",
            $courier, $city_id), ARRAY_A);
        return $row ?: null;
    }
    public static function office_by_id(string $courier, int $office_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT office_id,code,city_id,type,name,address,lat,lng FROM {$wpdb->prefix}bgc_offices WHERE courier=%s AND office_id=%d LIMIT 1",
            $courier, $office_id), ARRAY_A);
        return $row ?: null;
    }
    public static function offices(string $courier, int $city_id, string $type = ''): array {
        global $wpdb; $t = $wpdb->prefix . 'bgc_offices';
        $sql = "SELECT office_id,code,city_id,type,name,address,lat,lng FROM {$t} WHERE courier=%s AND city_id=%d";
        $args = [$courier, $city_id];
        if ($type !== '') { $sql .= ' AND type=%s'; $args[] = $type; }
        $sql .= ' ORDER BY name';
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    /** First office of a type, in the alphabetically-first city that has one (reference origin for office/automat). */
    public static function first_office(string $courier, string $type): ?array {
        global $wpdb; $o = $wpdb->prefix . 'bgc_offices'; $c = $wpdb->prefix . 'bgc_cities';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT o.office_id,o.code,o.city_id,o.type,o.name,o.address FROM {$o} o
             JOIN {$c} c ON c.courier=o.courier AND c.city_id=o.city_id
             WHERE o.courier=%s AND o.type=%s ORDER BY c.name LIMIT 1",
            $courier, $type), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Compact list of cities that HAVE this courier's offices, per type - for preloading the checkout city
     * dropdown (office/automat) so it needs no AJAX. Rows are [city_id, name, post_code]. Cached (a day).
     * @return array{office:array<int,array>,automat:array<int,array>}
     */
    public static function city_index(string $courier): array {
        $key = 'bgc_cityidx_' . $courier;
        $cached = get_transient($key);
        if (is_array($cached)) { return $cached; }
        global $wpdb; $o = $wpdb->prefix . 'bgc_offices'; $c = $wpdb->prefix . 'bgc_cities';
        $out = ['office' => [], 'automat' => []];
        foreach (['office', 'automat'] as $type) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT c.city_id, c.name, c.post_code FROM {$o} o
                 JOIN {$c} c ON c.courier=o.courier AND c.city_id=o.city_id
                 WHERE o.courier=%s AND o.type=%s ORDER BY c.name", $courier, $type), ARRAY_A);
            foreach ($rows as $r) { $out[$type][] = [(int) $r['city_id'], $r['name'], (string) $r['post_code']]; }
        }
        set_transient($key, $out, DAY_IN_SECONDS);
        return $out;
    }
}
