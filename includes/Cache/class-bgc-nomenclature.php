<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

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
                "INSERT INTO {$t} (courier,office_id,city_id,type,name,address,sync_run,updated_at)
                 VALUES (%s,%d,%d,%s,%s,%s,%s,NOW())
                 ON DUPLICATE KEY UPDATE city_id=VALUES(city_id),type=VALUES(type),name=VALUES(name),
                 address=VALUES(address),sync_run=VALUES(sync_run),updated_at=NOW()",
                $courier, $r['office_id'], $r['city_id'], $r['type'], $r['name'], $r['address'], $run
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
            "SELECT office_id,city_id,type,name,address FROM {$wpdb->prefix}bgc_offices WHERE courier=%s AND office_id=%d LIMIT 1",
            $courier, $office_id), ARRAY_A);
        return $row ?: null;
    }
    public static function offices(string $courier, int $city_id, string $type = ''): array {
        global $wpdb; $t = $wpdb->prefix . 'bgc_offices';
        $sql = "SELECT office_id,city_id,type,name,address FROM {$t} WHERE courier=%s AND city_id=%d";
        $args = [$courier, $city_id];
        if ($type !== '') { $sql .= ' AND type=%s'; $args[] = $type; }
        $sql .= ' ORDER BY name';
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }
}
