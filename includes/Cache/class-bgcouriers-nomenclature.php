<?php
defined('ABSPATH') || exit;

// Dedicated data-access layer for the plugin's OWN custom tables (wp_bgcouriers_cities / wp_bgcouriers_offices). Every
// query uses $wpdb->prepare() with $wpdb->prefix table names (interpolated table names cannot be bound as
// placeholders); these tables ARE the local cache of the couriers' nomenclatures (synced, transient-wrapped
// where hot), so object-cache layering adds nothing. Silence the custom-table DB sniffs for this file.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

class BGCouriers_Nomenclature {
    /** ISO-3166 alpha-2, upper case; anything unrecognisable is the home country, as every old row is. */
    private static function iso(string $country): string {
        $c = strtoupper(trim($country));
        return preg_match('/^[A-Z]{2}$/', $c) ? $c : 'BG';
    }

    /**
     * `AND country=%s`, or nothing at all.
     *
     * Every read below takes an OPTIONAL country: '' means "wherever it is", which is what a shop that
     * ships to one country wants and what every existing caller passes. The filter is only ever added
     * for a shop that has switched a second country on.
     *
     * @param string $country ISO alpha-2, or '' for no filter.
     * @param array  $args    Query args, appended to in place.
     * @param string $col     Column reference, prefixed when the query joins.
     */
    private static function country_sql(string $country, array &$args, string $col = 'country'): string {
        if ($country === '') { return ''; }
        $args[] = self::iso($country);
        return " AND {$col}=%s";
    }

    /**
     * @param array  $rows Each row may carry 'country' (ISO alpha-2); rows without one are Bulgarian,
     *                     which is what every row was before the plugin could ship anywhere else.
     */
    public static function upsert_cities(string $courier, array $rows, string $run): int {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_cities'; $n = 0;
        foreach ($rows as $r) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$t} (courier,country,city_id,name,name_lat,post_code,region,sync_run,updated_at)
                 VALUES (%s,%s,%d,%s,%s,%s,%s,%s,NOW())
                 ON DUPLICATE KEY UPDATE country=VALUES(country),name=VALUES(name),name_lat=VALUES(name_lat),
                 post_code=VALUES(post_code),region=VALUES(region),sync_run=VALUES(sync_run),updated_at=NOW()",
                // Not every courier supplies every column - Sameday's city rows carry no Latin name and
                // no region, and reading them unguarded filled the sync log with PHP warnings.
                $courier, self::iso($r['country'] ?? ''), $r['city_id'], $r['name'], $r['name_lat'] ?? '',
                $r['post_code'] ?? '', $r['region'] ?? '', $run
            ));
            $n++;
        }
        return $n;
    }
    public static function upsert_offices(string $courier, array $rows, string $run): int {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_offices'; $n = 0;
        foreach ($rows as $r) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$t} (courier,country,office_id,code,city_id,type,name,address,lat,lng,sync_run,updated_at)
                 VALUES (%s,%s,%d,%s,%d,%s,%s,%s,%f,%f,%s,NOW())
                 ON DUPLICATE KEY UPDATE country=VALUES(country),code=VALUES(code),city_id=VALUES(city_id),type=VALUES(type),name=VALUES(name),
                 address=VALUES(address),lat=VALUES(lat),lng=VALUES(lng),sync_run=VALUES(sync_run),updated_at=NOW()",
                // city_id defaults to 0: a geo-based courier (BOX NOW) has lockers but no city
                // nomenclature to tie them to, and its rows carry no city_id at all.
                $courier, self::iso($r['country'] ?? ''), $r['office_id'], (string) ($r['code'] ?? ''), (int) ($r['city_id'] ?? 0),
                (string) ($r['type'] ?? ''), (string) ($r['name'] ?? ''), (string) ($r['address'] ?? ''),
                (float) ($r['lat'] ?? 0), (float) ($r['lng'] ?? 0), $run
            ));
            $n++;
        }
        return $n;
    }
    /**
     * Drop rows this sync run did not touch.
     *
     * Each table is pruned only if this run actually refreshed it. Pruning a table whose fetch returned
     * nothing deletes everything the courier has - which is what would happen to BOX NOW's lockers on
     * every single run, since it has no cities to refresh, and to any courier the one time one of its
     * two endpoints times out.
     */
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

    /**
     * @param string[] $countries Countries this run actually refreshed; [] = all of them.
     *
     * A country is the same half-failure the flags above guard against, one level down. A sync that
     * refreshed Bulgaria and then had Romania time out has a full, current Bulgarian table and a stale
     * Romanian one - and pruning everything the run did not touch would delete every Romanian town the
     * shop has. So the delete is confined to the countries that answered.
     */
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
     * How many offices of each point-type this courier has synced - drives which delivery options to OFFER
     * (a type with zero points is not shown). Returns ['office'=>N,'automat'=>M,'total'=>sum]; 'total' 0 means
     * this courier syncs no points here (BOX NOW widget / un-synced Sameday), so callers should NOT prune off
     * declared capabilities. Cached 6h and cleared by a sync run.
     *
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
        // Post codes are only unique inside a country: 1000 is Sofia in Bulgaria and a Bucharest sector
        // in Romania. Without the filter the first row wins, which is how a Romanian order would be
        // quoted against a Bulgarian town.
        $args = [$courier, $code];
        $sql  = "SELECT city_id,country,name,name_lat,post_code,region FROM {$wpdb->prefix}bgcouriers_cities WHERE courier=%s AND post_code=%s";
        $sql .= self::country_sql($country, $args);
        $row = $wpdb->get_row($wpdb->prepare($sql . ' LIMIT 1', ...$args), ARRAY_A);
        return $row ?: null;
    }
    /**
     * One PLACE, as a given courier numbers it. City ids in this plugin belong to the courier that
     * issued them, so the combined office map carries a name and a post code and asks each courier what
     * it calls that. Name AND post code first because neither is unique on its own - about a thousand
     * cities per courier share a post code with another, and names repeat across regions - then each
     * alone, because a courier that spells or codes a small town differently should still be found.
     *
     * @param string $courier   Courier id.
     * @param string $name      City name as another courier spells it.
     * @param string $post_code Post code, '' when unknown.
     * @return array|null The courier's own row, or null when it does not list the place.
     */
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
    public static function offices(string $courier, int $city_id, string $type = ''): array {
        global $wpdb; $t = $wpdb->prefix . 'bgcouriers_offices';
        $sql = "SELECT office_id,code,city_id,type,name,address,lat,lng FROM {$t} WHERE courier=%s AND city_id=%d";
        $args = [$courier, $city_id];
        if ($type !== '') { $sql .= ' AND type=%s'; $args[] = $type; }
        $sql .= ' ORDER BY name';
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    /**
     * First office of a type, in the alphabetically-first city that has one (reference origin for
     * office/automat). With a country, the first one THERE - a reference price for Romania quoted
     * against a Bulgarian office is a Bulgarian price with a Romanian label on it.
     */
    public static function first_office(string $courier, string $type, string $country = ''): ?array {
        global $wpdb; $o = $wpdb->prefix . 'bgcouriers_offices'; $c = $wpdb->prefix . 'bgcouriers_cities';
        $args = [$courier, $type];
        $sql  = "SELECT o.office_id,o.country,o.code,o.city_id,o.type,o.name,o.address FROM {$o} o
             JOIN {$c} c ON c.courier=o.courier AND c.city_id=o.city_id
             WHERE o.courier=%s AND o.type=%s";
        $sql .= self::country_sql($country, $args, 'o.country');
        $row = $wpdb->get_row($wpdb->prepare($sql . ' ORDER BY c.name LIMIT 1', ...$args), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Compact list of cities that HAVE this courier's offices, per type - for preloading the checkout city
     * dropdown (office/automat) so it needs no AJAX. Rows are [city_id, name, post_code]. Cached (a day).
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
            // Row: [city_id, name, post_code, name_lat] - name_lat lets the checkout match a Latin-typed search.
            foreach ($rows as $r) { $out[$type][] = [(int) $r['city_id'], $r['name'], (string) $r['post_code'], (string) $r['name_lat']]; }
        }
        set_transient($key, $out, DAY_IN_SECONDS);
        return $out;
    }
}
