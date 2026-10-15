<?php
defined('ABSPATH') || exit;

// Own custom tables: table names cannot be bound, and the WHERE/VALUES placeholders live in variables the sniffs cannot see.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

class BGCouriers_Nomenclature {
    /** ISO-3166 alpha-2, upper case; anything unrecognisable is the home country, as every old row is. */
    private static function iso(string $country): string {
        $c = strtoupper(trim($country));
        return preg_match('/^[A-Z]{2}$/', $c) ? $c : 'BG';
    }

    /** `AND country=%s` appended to $args, or nothing for ''. */
    private static function country_sql(string $country, array &$args, string $col = 'country'): string {
        if ($country === '') { return ''; }
        $args[] = self::iso($country);
        return " AND {$col}=%s";
    }

    /** Rows per INSERT; sized for max_allowed_packet (1MB on shared hosts), not for speed. */
    private const UPSERT_CHUNK = 200;

    /** INSERT ... ON DUPLICATE KEY UPDATE in batches; returns the rows the database accepted, which the caller must check before pruning. */
    private static function upsert(string $table, array $cols, string $tuple, array $update, array $rows): int {
        global $wpdb;
        if (!$rows) { return 0; }
        $set  = implode(',', array_map(static function ($c) { return "{$c}=VALUES({$c})"; }, $update));
        $head = "INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES ';
        $tail = " ON DUPLICATE KEY UPDATE {$set},updated_at=NOW()";
        $n = 0;
        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            $args = [];
            foreach ($chunk as $r) { foreach ($r as $v) { $args[] = $v; } }
            $sql = $head . implode(',', array_fill(0, count($chunk), $tuple)) . $tail;
            if ($wpdb->query($wpdb->prepare($sql, ...$args)) === false) {
                BGCouriers_Logger::debug('nomenclature: a batch was refused', [
                    'table' => $table, 'rows' => count($chunk), 'err' => $wpdb->last_error]);
                continue;
            }
            $n += count($chunk);
        }
        return $n;
    }

    /** @return int Rows written; fewer than offered means a batch was refused. Rows without 'country' are Bulgarian. */
    public static function upsert_cities(string $courier, array $rows, string $run): int {
        global $wpdb;
        $args = [];
        foreach ($rows as $r) {
            // Not every courier supplies every column (Sameday city rows have no Latin name or region).
            $args[] = [$courier, self::iso($r['country'] ?? ''), $r['city_id'], $r['name'], $r['name_lat'] ?? '',
                       $r['post_code'] ?? '', $r['region'] ?? '', $run];
        }
        return self::upsert(
            $wpdb->prefix . 'bgcouriers_cities',
            ['courier', 'country', 'city_id', 'name', 'name_lat', 'post_code', 'region', 'sync_run', 'updated_at'],
            '(%s,%s,%d,%s,%s,%s,%s,%s,NOW())',
            ['country', 'name', 'name_lat', 'post_code', 'region', 'sync_run'],
            $args
        );
    }

    /** @return int Rows written - fewer than were offered means a batch was refused. */
    public static function upsert_offices(string $courier, array $rows, string $run): int {
        global $wpdb;
        $args = [];
        foreach ($rows as $r) {
            // city_id 0: a geo-based courier (BOX NOW) has lockers with no city nomenclature.
            $args[] = [$courier, self::iso($r['country'] ?? ''), $r['office_id'], (string) ($r['code'] ?? ''),
                       (int) ($r['city_id'] ?? 0), (string) ($r['type'] ?? ''), (string) ($r['name'] ?? ''),
                       (string) ($r['address'] ?? ''), (float) ($r['lat'] ?? 0), (float) ($r['lng'] ?? 0), $run];
        }
        return self::upsert(
            $wpdb->prefix . 'bgcouriers_offices',
            ['courier', 'country', 'office_id', 'code', 'city_id', 'type', 'name', 'address', 'lat', 'lng', 'sync_run', 'updated_at'],
            '(%s,%s,%d,%s,%d,%s,%s,%s,%f,%f,%s,NOW())',
            ['country', 'code', 'city_id', 'type', 'name', 'address', 'lat', 'lng', 'sync_run'],
            $args
        );
    }
    /** Drop rows this run did not touch, only in tables this run actually refreshed: an empty fetch must not wipe a courier. */
    public static function prune(string $courier, string $run, bool $cities = true, bool $offices = true,
                                array $city_countries = [], array $office_countries = []): int {
        global $wpdb;
        $n = 0;
        if ($cities) {
            $n += self::prune_table($wpdb->prefix . 'bgcouriers_cities', $courier, $run, $city_countries);
        }
        if ($offices) {
            $n += self::prune_table($wpdb->prefix . 'bgcouriers_offices', $courier, $run, $office_countries);
        }
        return $n;
    }

    /** @param string[] $countries Countries this run refreshed, [] = all; the delete is confined to those that answered. */
    private static function prune_table(string $table, string $courier, string $run, array $countries): int {
        global $wpdb;
        $args = [$courier, $run];
        $sql  = "DELETE FROM {$table} WHERE courier=%s AND sync_run<>%s";
        $iso  = array_values(array_unique(array_map([self::class, 'iso'], $countries)));
        if ($iso) {
            $sql .= ' AND country IN (' . implode(',', array_fill(0, count($iso), '%s')) . ')';
            $args = array_merge($args, $iso);
        }
        return (int) $wpdb->query($wpdb->prepare($sql, ...$args));
    }
    public static function count(string $courier): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bgcouriers_cities WHERE courier=%s", $courier));
    }

    /**
     * Synced offices per point-type; 'total' 0 means this courier syncs no points here, so do not prune declared capabilities. Cached 6h.
     * @return array{office:int,automat:int,total:int}
     */
    public static function type_counts(string $courier): array {
        $key    = 'bgcouriers_typecnt_' . $courier;
        $cached = get_transient($key);
        if (is_array($cached)) { return $cached; }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT type, COUNT(*) c FROM {$wpdb->prefix}bgcouriers_offices WHERE courier=%s GROUP BY type", $courier), ARRAY_A);
        $out = ['office' => 0, 'automat' => 0, 'total' => 0];
        foreach ((array) $rows as $r) {
            $out[$r['type']] = (int) $r['c'];
            $out['total']   += (int) $r['c'];
        }
        set_transient($key, $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }
    public static function search_cities(string $courier, string $term, int $limit = 20, string $country = ''): array {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_cities';
        $args = [$courier, $wpdb->esc_like($term) . '%', $wpdb->esc_like($term) . '%', $wpdb->esc_like($term) . '%'];
        $sql  = "SELECT city_id,country,name,name_lat,post_code,region FROM {$t}
             WHERE courier=%s AND (name LIKE %s OR name_lat LIKE %s OR post_code LIKE %s)";
        $sql .= self::country_sql($country, $args);
        $args[] = $limit;
        return $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY name LIMIT %d', ...$args), ARRAY_A);
    }
    public static function city_by_postcode(string $courier, string $code, string $country = ''): ?array {
        global $wpdb;
        // Post codes are only unique inside a country: 1000 is Sofia and also a Bucharest sector.
        $args = [$courier, $code];
        $sql  = "SELECT city_id,country,name,name_lat,post_code,region FROM {$wpdb->prefix}bgcouriers_cities WHERE courier=%s AND post_code=%s";
        $sql .= self::country_sql($country, $args);
        $row = $wpdb->get_row($wpdb->prepare($sql . ' LIMIT 1', ...$args), ARRAY_A);
        return $row ?: null;
    }
    /** The courier's own row for a place: name and post code first (neither is unique alone), then each alone. */
    public static function match_city(string $courier, string $name, string $post_code, string $country = ''): ?array {
        global $wpdb;
        $t = $wpdb->prefix . 'bgcouriers_cities';
        $cols = 'city_id,country,name,name_lat,post_code,region';
        $one = static function (string $where, array $args) use ($wpdb, $t, $cols, $country) {
            $where .= self::country_sql($country, $args);
            return $wpdb->get_row($wpdb->prepare("SELECT {$cols} FROM {$t} WHERE {$where} LIMIT 1", ...$args), ARRAY_A) ?: null;
        };
        if ($name !== '' && $post_code !== '') {
            $row = $one('courier=%s AND name=%s AND post_code=%s', [$courier, $name, $post_code]);
            if ($row) { return $row; }
        }
        if ($name !== '') {
            $row = $one('courier=%s AND name=%s', [$courier, $name]);
            if ($row) { return $row; }
        }
        if ($post_code !== '') {
            $row = $one('courier=%s AND post_code=%s', [$courier, $post_code]);
            if ($row) { return $row; }
        }
        return null;
    }
    /** Most common post code the other couriers list a town under, '' when none does; locker codes (BOX NOW) are often district codes. */
    public static function post_code_by_name(string $name, string $country = '', array $except = []): string {
        global $wpdb;
        $t = $wpdb->prefix . 'bgcouriers_cities';
        $args = [$name];
        $sql  = "SELECT post_code, COUNT(*) n FROM {$t} WHERE name=%s AND post_code<>''";
        if ($except) {
            $sql .= ' AND courier NOT IN (' . implode(',', array_fill(0, count($except), '%s')) . ')';
            $args = array_merge($args, array_values($except));
        }
        $sql .= self::country_sql($country, $args);
        $row = $wpdb->get_row($wpdb->prepare($sql . ' GROUP BY post_code ORDER BY n DESC, post_code ASC LIMIT 1', ...$args), ARRAY_A);
        return (string) ($row['post_code'] ?? '');
    }

    public static function city_by_id(string $courier, int $city_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT city_id,country,name,name_lat,post_code,region FROM {$wpdb->prefix}bgcouriers_cities WHERE courier=%s AND city_id=%d LIMIT 1",
            $courier, $city_id), ARRAY_A);
        return $row ?: null;
    }
    public static function office_by_id(string $courier, int $office_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT office_id,country,code,city_id,type,name,address,lat,lng FROM {$wpdb->prefix}bgcouriers_offices WHERE courier=%s AND office_id=%d LIMIT 1",
            $courier, $office_id), ARRAY_A);
        return $row ?: null;
    }
    /** [office_id, name, city] rows ordered by town then name; home country unless told otherwise. */
    public static function all_offices(string $courier, string $type, string $country = ''): array {
        global $wpdb; $o = $wpdb->prefix . 'bgcouriers_offices'; $c = $wpdb->prefix . 'bgcouriers_cities';
        $args = [$courier, $type];
        $sql  = "SELECT o.office_id,o.name,c.name city FROM {$o} o
             JOIN {$c} c ON c.courier=o.courier AND c.city_id=o.city_id
             WHERE o.courier=%s AND o.type=%s";
        $sql .= self::country_sql($country !== '' ? $country : BGCouriers_Settings::home_country(), $args, 'o.country');
        return (array) $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY c.name, o.name', ...$args), ARRAY_A);
    }
    public static function offices(string $courier, int $city_id, string $type = ''): array {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_offices';
        $sql = "SELECT office_id,code,city_id,type,name,address,lat,lng FROM {$t} WHERE courier=%s AND city_id=%d";
        $args = [$courier, $city_id];
        if ($type !== '') { $sql .= ' AND type=%s'; $args[] = $type; }
        $sql .= ' ORDER BY name';
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    /** First office of a type in the alphabetically-first city; $exclude_city keeps the country zone's reference out of Sofia. */
    public static function first_office(string $courier, string $type, string $country = '', int $exclude_city = 0): ?array {
        global $wpdb; $o = $wpdb->prefix . 'bgcouriers_offices'; $c = $wpdb->prefix . 'bgcouriers_cities';
        $args = [$courier, $type];
        $sql  = "SELECT o.office_id,o.country,o.code,o.city_id,o.type,o.name,o.address FROM {$o} o
             JOIN {$c} c ON c.courier=o.courier AND c.city_id=o.city_id
             WHERE o.courier=%s AND o.type=%s";
        if ($exclude_city > 0) { $sql .= ' AND o.city_id<>%d'; $args[] = $exclude_city; }
        $sql .= self::country_sql($country, $args, 'o.country');
        $row = $wpdb->get_row($wpdb->prepare($sql . ' ORDER BY c.name LIMIT 1', ...$args), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Cities that have offices of each type, [city_id, name, post_code, name_lat] rows, cached a day.
     * @return array{office:array<int,array>,automat:array<int,array>}
     */
    public static function city_index(string $courier, string $country = ''): array {
        $key = 'bgcouriers_cityidx_' . $courier . ($country !== '' ? '_' . strtolower(self::iso($country)) : '');
        $cached = get_transient($key);
        if (is_array($cached)) { return $cached; }
        global $wpdb; $o = $wpdb->prefix . 'bgcouriers_offices'; $c = $wpdb->prefix . 'bgcouriers_cities';
        $out = ['office' => [], 'automat' => []];
        foreach (['office', 'automat'] as $type) {
            $args = [$courier, $type];
            $sql  = "SELECT DISTINCT c.city_id, c.name, c.name_lat, c.post_code FROM {$o} o
                 JOIN {$c} c ON c.courier=o.courier AND c.city_id=o.city_id
                 WHERE o.courier=%s AND o.type=%s";
            $sql .= self::country_sql($country, $args, 'o.country');
            $rows = $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY c.name', ...$args), ARRAY_A);
            foreach ($rows as $r) { $out[$type][] = [(int) $r['city_id'], $r['name'], (string) $r['post_code'], (string) $r['name_lat']]; }
        }
        set_transient($key, $out, DAY_IN_SECONDS);
        return $out;
    }
}
