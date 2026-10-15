<?php
defined('ABSPATH') || exit;

class BGCouriers_Ajax {
    public function __construct() {
        foreach (['search_cities','offices','city_avail','streets','set_selection','geocode','allmap_cities','allmap_offices','allmap_prices','sameday_availability'] as $a) {
            add_action("wp_ajax_bgcouriers_{$a}", [$this, $a]);
            add_action("wp_ajax_nopriv_bgcouriers_{$a}", [$this, $a]);
        }
    }

    private static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (empty($_SERVER[$h])) { continue; }
            $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$h])))[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
        }
        return 'unknown';
    }

    /** Per-IP limit for endpoints that hit a live courier API. Fixed window from the first request, never extended per call. */
    private static function rate_ok(int $max = 300, int $window = 60): bool {
        $key  = 'bgcouriers_rl_' . md5(self::client_ip());
        $now  = time();
        $slot = get_transient($key);
        if (!is_array($slot) || (int) ($slot['until'] ?? 0) <= $now) {
            $slot = ['n' => 0, 'until' => $now + $window];
        }
        if ((int) $slot['n'] >= $max) { return false; }
        $slot['n'] = (int) $slot['n'] + 1;
        set_transient($key, $slot, max(1, (int) $slot['until'] - $now));
        return true;
    }

    /** A refusal is a 429, never a 200 with an empty body: jQuery skips the success handler, so the browser does not cache it as "no offices". */
    private static function busy(): void {
        wp_send_json_error(['bgc_busy' => true], 429);
    }

    /** Reverse-geocode lat/lng to {city, postcode, street, number}: Google with a key, else Nominatim. */
    public function geocode(): void {
        if (!self::rate_ok()) { self::busy(); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $lat = round((float) wp_unslash($_GET['lat'] ?? 0), 5); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float-cast, no state change
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $lng = round((float) wp_unslash($_GET['lng'] ?? 0), 5); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float-cast, no state change
        if ($lat === 0.0 && $lng === 0.0) { wp_send_json([]); }
        $tkey = 'bgcouriers_geo_' . str_replace(['.', '-'], ['', 'm'], $lat . '_' . $lng);
        $cached = get_transient($tkey);
        if (is_array($cached)) { wp_send_json($cached); }
        $key = trim((string) get_option('bgcouriers_google_maps_key', ''));
        $out = $key !== '' ? self::geocode_google($lat, $lng, $key) : self::geocode_nominatim($lat, $lng);
        if (!empty($out)) { set_transient($tkey, $out, WEEK_IN_SECONDS); }
        wp_send_json($out);
    }

    private static function geocode_nominatim(float $lat, float $lng): array {
        $url = add_query_arg([
            'lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => 'bg',
        ], 'https://nominatim.openstreetmap.org/reverse');
        $r = wp_remote_get($url, ['timeout' => 12, 'headers' => ['User-Agent' => 'bg-couriers WooCommerce plugin']]);
        if (is_wp_error($r)) { return []; }
        $a = (array) (json_decode((string) wp_remote_retrieve_body($r), true)['address'] ?? []);
        if (empty($a)) { return []; }
        return [
            'city'     => (string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? ''),
            'postcode' => (string) ($a['postcode'] ?? ''),
            'street'   => (string) ($a['road'] ?? ''),
            'number'   => (string) ($a['house_number'] ?? ''),
        ];
    }

    private static function geocode_google(float $lat, float $lng, string $key): array {
        $url = add_query_arg([
            'latlng' => $lat . ',' . $lng, 'language' => 'bg', 'key' => $key,
        ], 'https://maps.googleapis.com/maps/api/geocode/json');
        $r = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($r)) { return self::geocode_nominatim($lat, $lng); }
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        $comp = (array) ($data['results'][0]['address_components'] ?? []);
        if (empty($comp)) { return []; }
        $pick = static function ($type) use ($comp) {
            foreach ($comp as $c) { if (in_array($type, (array) ($c['types'] ?? []), true)) { return (string) ($c['long_name'] ?? ''); } }
            return '';
        };
        return [
            'city'     => $pick('locality') ?: $pick('administrative_area_level_2'),
            'postcode' => $pick('postal_code'),
            'street'   => $pick('route'),
            'number'   => $pick('street_number'),
        ];
    }
    public static function address_fields(array $src): array {
        $keys = ['street_name','street_type','street_no','complex','block','entrance','floor','apartment','address_note'];
        $out = [];
        foreach ($keys as $k) { $out[$k] = sanitize_text_field((string) ($src[$k] ?? '')); }
        $out['street_id'] = max(0, (int) ($src['street_id'] ?? 0));
        return $out;
    }
    /** Country from the request (the browser knows it before the session does), honoured only if the courier serves it. */
    private static function request_country(string $courier): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $c = strtoupper(sanitize_text_field(wp_unslash($_REQUEST['country'] ?? '')));
        return in_array($c, BGCouriers_Settings::delivery_countries($courier), true)
            ? $c
            : BGCouriers_Pricing::destination_country();
    }

    public static function search_cities_data(): array {
        $courier = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        return BGCouriers_Nomenclature::search_cities($courier, $term, BGCouriers_Settings::dropdown_limit(),
                                                      self::request_country($courier));
    }
    public function search_cities(): void { wp_send_json(self::search_cities_data()); }
    public function offices(): void {
        if (!self::rate_ok()) { self::busy(); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $courier = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $type = sanitize_key(wp_unslash($_GET['type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $limit = !empty($_GET['all']) ? 100000 : BGCouriers_Settings::dropdown_limit(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- all=1 -> the full city list, for the client cache
        wp_send_json(self::city_offices($courier, $city, $type, $term, $limit, self::request_country($courier)));
    }

    public function city_avail(): void {
        if (!self::rate_ok()) { self::busy(); }
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        if ($city <= 0) { wp_send_json(['office' => false, 'automat' => false]); }
        wp_send_json(self::city_avail_data($courier_id, $city, self::request_country($courier_id)));
    }

    /** @return array{office:bool,automat:bool} read off the office dropdown's own cached list, so there is no second cache to disagree */
    public static function city_avail_data(string $courier_id, int $city, string $country): array {
        $office = false; $automat = false;
        foreach (self::city_offices($courier_id, $city, '', '', 100000, $country) as $o) {
            $t = $o['type'] ?? '';
            if ($t === 'office') { $office = true; } elseif ($t === 'automat') { $automat = true; }
        }
        return ['office' => $office, 'automat' => $automat];
    }

    public static function nomenclature_generation(string $courier_id): string {
        return (string) get_option('bgcouriers_nomgen_' . $courier_id, '');
    }

    /** @return array<int,array<string,mixed>>|null the dropdown's cached office list, null while cold; never calls the courier */
    public static function cached_city_offices(string $courier_id, int $city, string $country = ''): ?array {
        if ($city <= 0) { return null; }
        $tkey   = 'bgcouriers_off_' . $courier_id . '_' . $country . '_' . $city . '_' . self::nomenclature_generation($courier_id);
        $cached = get_transient($tkey);
        return is_array($cached) ? $cached : null;
    }

    public static function city_offices(string $courier_id, int $city, string $type, string $term = '', int $limit = 5, string $country = ''): array {
        $rows = [];
        if ($city > 0) {
            // Key carries the nomenclature generation: transients cannot be deleted by prefix, so a sync retires every cached list at once.
            $tkey   = 'bgcouriers_off_' . $courier_id . '_' . $country . '_' . $city . '_' . self::nomenclature_generation($courier_id);
            $cached = get_transient($tkey);
            if (is_array($cached)) {
                $rows = $cached;
            } else {
                try {
                    $courier = BGCouriers_Couriers::get($courier_id);
                    if (!$courier) { return []; }
                    $rows = $courier->fetch_offices($city, $country);
                    if (!empty($rows)) { set_transient($tkey, $rows, 6 * HOUR_IN_SECONDS); }
                // \Throwable, not \Exception: a broken adapter must fall back to the synced table, not 500.
                } catch (\Throwable $e) { $rows = BGCouriers_Nomenclature::offices($courier_id, $city); }
            }
        }
        if ($type !== '') {
            $rows = array_filter($rows, static function ($o) use ($type) { return ($o['type'] ?? '') === $type; });
        }
        if ($term !== '') {
            $t = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
            $rows = array_filter($rows, static function ($o) use ($t) {
                $name = function_exists('mb_strtolower') ? mb_strtolower((string) ($o['name'] ?? '')) : strtolower((string) ($o['name'] ?? ''));
                return strpos($name, $t) !== false || strpos((string) ($o['office_id'] ?? ''), $t) !== false;
            });
        }
        $rows = array_values($rows);
        usort($rows, static function ($a, $b) { return ((int) ($a['office_id'] ?? 0)) <=> ((int) ($b['office_id'] ?? 0)); });
        return array_slice($rows, 0, max(1, $limit));
    }

    /** Sameday-only: which easyBox lockers have a free compartment for the cart's parcel; {lockers:{id:bool}}. */
    public function sameday_availability(): void {
        if (!self::rate_ok()) { self::busy(); }
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $map = self::sameday_availability_map();
        if ($city > 0 && $map) {
            $here = [];
            foreach (self::city_offices('sameday', $city, 'automat', '', 100000, self::request_country('sameday')) as $o) {
                $id = (int) ($o['office_id'] ?? 0);
                if ($id && array_key_exists($id, $map)) { $here[$id] = $map[$id]; }
            }
            $map = $here;
        }
        wp_send_json(['lockers' => $map]);
    }

    /** @return array<int,bool> lockerId => fits; cached one minute, since compartments fill and empty */
    private static function sameday_availability_map(): array {
        if (!class_exists('BGCouriers_Sameday')) { return []; }
        $courier = BGCouriers_Couriers::get('sameday');
        if (!$courier || !method_exists($courier, 'fetch_locker_availability')
            || !BGCouriers_Settings::courier_offerable('sameday')) { return []; }
        $free = get_transient('bgcouriers_sameday_avail');
        if (!is_array($free)) {
            try { $free = $courier->fetch_locker_availability(); }
            catch (\Throwable $e) { return []; }
            set_transient('bgcouriers_sameday_avail', $free, MINUTE_IN_SECONDS);
        }
        // The cart's parcel, not the shop default: the compartment shown must be the one later booked.
        $required = BGCouriers_Sameday::box_size_for(BGCouriers_Settings::cart_box_dims());
        $out = [];
        foreach ($free as $id => $boxes) {
            $out[(int) $id] = BGCouriers_Sameday::locker_fits((array) $boxes, $required);
        }
        return $out;
    }
    /** Places across every enabled courier, distinct by lower-cased name + post code; sorted case-insensitively before the slice to 30. */
    public function allmap_cities(): void {
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $seen = [];
        $out  = [];
        $country = BGCouriers_Pricing::destination_country();
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes') { continue; }
            foreach (BGCouriers_Nomenclature::search_cities($cid, $term, 30, $country) as $row) {
                $lower = function_exists('mb_strtolower') ? mb_strtolower($row['name'], 'UTF-8') : strtolower($row['name']);
                $key = $lower . '|' . $row['post_code'];
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;
                $out[] = ['name' => $row['name'], 'post_code' => $row['post_code'], 'region' => $row['region'] ?? '', 'sort' => $lower];
            }
        }
        usort($out, static function ($a, $b) { return strcmp($a['sort'], $b['sort']); });
        wp_send_json(array_slice(array_map(static function ($r) {
            unset($r['sort']);
            return $r;
        }, $out), 0, 30));
    }

    /** Every enabled courier's points for one place, each asked with its own city id (a shared id does not exist). */
    public function allmap_offices(): void {
        // One rate-limit charge for the whole request: the fan-out is bounded by the courier count, not by the caller.
        if (!self::rate_ok()) { self::busy(); }
        $name = sanitize_text_field(wp_unslash($_GET['name'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $code = sanitize_text_field(wp_unslash($_GET['post_code'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $type = sanitize_key(wp_unslash($_GET['type'] ?? 'both')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!in_array($type, ['office', 'automat', 'both'], true)) { wp_send_json([]); }
        $types = $type === 'both' ? ['office', 'automat'] : [$type];
        $out = self::allmap_collect($name, $code, $types, false);
        // An empty synced table means no sync has run yet; only then is the live path worth its cost.
        if (!$out) { $out = self::allmap_collect($name, $code, $types, true); }
        wp_send_json($out);
    }

    private static function money(float $v): string {
        return html_entity_decode(wp_strip_all_tags(wc_price($v)), ENT_QUOTES, 'UTF-8');
    }

    /** Live price for one courier in one town, asked after the map is on screen; one quote per delivery type, since couriers price by city pair. */
    public function allmap_prices(): void {
        if (!self::rate_ok()) { self::busy(); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only price lookup, no state change
        $cid  = isset($_GET['courier']) ? sanitize_key(wp_unslash($_GET['courier'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only price lookup, no state change
        $name = isset($_GET['name']) ? sanitize_text_field(wp_unslash($_GET['name'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only price lookup, no state change
        $code = isset($_GET['post_code']) ? sanitize_text_field(wp_unslash($_GET['post_code'])) : '';
        $obj  = $cid !== '' ? BGCouriers_Couriers::get($cid) : null;
        if (!$obj || !BGCouriers_Settings::courier_offerable($cid)) { wp_send_json_success(['prices' => [], 'saves' => []]); }
        // A town name and post code are only unique inside a country: "1000" is Sofia and Bucharest.
        $country = BGCouriers_Pricing::destination_country();
        $city = BGCouriers_Nomenclature::match_city($cid, $name, $code, $country);
        if (!$city) { wp_send_json_success(['prices' => [], 'saves' => []]); }

        $packed   = BGCouriers_Pricing::cart_parcel();
        $in_total = BGCouriers_Settings::ship_in_total($cid);
        $res = self::map_prices($cid, BGCouriers_Settings::enabled_methods($cid), $country, function ($t) use ($obj, $city, $packed, $country, $in_total) {
            try {
                $q = BGCouriers_Pricing::checkout_quote($obj, $t, (int) $city['city_id'], 0, $packed, get_woocommerce_currency(), $country);
            } catch (\Throwable $e) { return null; }   // one unreachable courier must not empty the map
            return BGCouriers_Pricing::map_office_price($q, $in_total);
        });
        wp_send_json_success(['courier' => $cid, 'prices' => $res['prices'], 'saves' => $res['saves']]);
    }

    /**
     * Prices and office-vs-address savings the way the shipping row shows them: the free rule is applied here, the paid figure comes from $paid_value.
     * @param string[] $types
     * @param callable $paid_value fn(string $type): ?float, null drops the option
     * @return array{prices: array<string,string>, saves: array<string,string>}
     */
    private static function map_prices(string $cid, array $types, string $country, callable $paid_value): array {
        $abroad   = BGCouriers_Settings::is_intl($country);
        $has_cart = function_exists('WC') && WC() && WC()->cart;
        $prices = []; $raw = [];
        foreach ($types as $t) {
            $free = !$abroad && $has_cart
                && BGCouriers_Abstract_Method::is_free((float) WC()->cart->get_subtotal(), BGCouriers_Settings::free_shipping($cid, $t));
            if ($free) {
                $label      = BGCouriers_Settings::free_shipping_label();
                $raw[$t]    = 0.0;
                $prices[$t] = $label !== '' ? $label : self::money(0.0);
                continue;
            }
            $v = $paid_value($t);
            if ($v === null || $v <= 0.0) { continue; }
            $raw[$t]    = $v;
            $prices[$t] = self::money($v);
        }
        $saves = [];
        if (isset($raw['address'])) {
            foreach (['office', 'automat'] as $t) {
                if (isset($raw[$t]) && $raw['address'] > $raw[$t]) {
                    $saves[$t] = self::money($raw['address'] - $raw[$t]);
                }
            }
        }
        return ['prices' => $prices, 'saves' => $saves];
    }

    /** @return array courier id => ['city_id','prices','saves','offices']; reads the synced tables unless $live, since eight live calls in one request killed the map on a cold cache */
    private static function allmap_collect(string $name, string $code, array $types, bool $live): array {
        $out = [];
        $country = BGCouriers_Pricing::destination_country();
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes') { continue; }
            $carries = BGCouriers_Settings::enabled_methods($cid);
            $wanted = array_values(array_intersect($types, $carries));
            if (!$wanted) { continue; }
            $city = BGCouriers_Nomenclature::match_city($cid, $name, $code, $country);
            if (!$city) { continue; }
            $rows = [];
            foreach ($wanted as $t) {
                $found = $live
                    ? self::city_offices($cid, (int) $city['city_id'], $t, '', 100000, $country)
                    : BGCouriers_Nomenclature::offices($cid, (int) $city['city_id'], $t);
                foreach ($found as $office) {
                    $office['type'] = $t;
                    $rows[] = $office;
                }
            }
            if (!$rows) { continue; }
            // A reference price per delivery type (couriers do not charge one price), from the cache so the map never waits on a courier.
            // Every method the courier offers, not only the plotted two: the address price is what the map is compared against.
            $res = self::map_prices($cid, BGCouriers_Settings::enabled_methods($cid), $country, function ($t) use ($cid) {
                $est = BGCouriers_Pricing::estimate($cid, $t);
                return $est !== null ? (float) $est : null;
            });
            $out[$cid] = ['city_id' => (int) $city['city_id'], 'prices' => $res['prices'],
                          'saves' => $res['saves'], 'offices' => $rows];
        }
        return $out;
    }
    public function streets(): void {
        if (!self::rate_ok()) { self::busy(); }
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        // exact=1: match the geocoded text to a listed street (id and type), since some couriers refuse free text at label time.
        $exact = !empty($_GET['exact']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $out = [];
        if ($city > 0 && $term !== '') {
            try {
                $courier = BGCouriers_Couriers::get($courier_id);
                if (!$courier) { wp_send_json([]); }
                if (method_exists($courier, 'search_streets')) {
                    $rows = $courier->search_streets($city, $exact ? BGCouriers_Street::split($term)['name'] : $term, self::request_country($courier_id));
                    $out  = $exact ? BGCouriers_Street::exact($rows, $term) : self::rank_streets($rows, $term);
                    $out  = array_slice($out, 0, BGCouriers_Settings::dropdown_limit());
                }
            } catch (\Throwable $e) { $out = []; }
        }
        wp_send_json($out);
    }

    /** Streets beginning with the term first, then word-start matches, then the rest; the courier's own order is not a ranking. */
    public static function rank_streets(array $rows, string $term): array {
        $t = self::fold($term);
        if ($t === '') { return $rows; }
        $ranked = [];
        foreach (array_values($rows) as $i => $r) {
            $n = self::fold((string) ($r['name'] ?? ''));
            // Character offsets, not byte offsets: the names are Cyrillic.
            $p = function_exists('mb_strpos') ? mb_strpos($n, $t, 0, 'UTF-8') : strpos($n, $t);
            if ($p === false) { $rank = 3; }
            elseif ($p === 0) { $rank = 0; }
            elseif (preg_match('/[\s\-.,()\/"\']/u', function_exists('mb_substr') ? mb_substr($n, $p - 1, 1, 'UTF-8') : substr($n, $p - 1, 1))) { $rank = 1; }
            else { $rank = 2; }
            $ranked[] = [$rank, $i, $r];
        }
        usort($ranked, static function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
        return array_map(static function ($x) { return $x[2]; }, $ranked);
    }

    private static function fold(string $s): string {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
    public function set_selection(): void {
        check_ajax_referer('bgcouriers_checkout', 'nonce');
        $method = sanitize_key(wp_unslash($_POST['method'] ?? 'office'));
        if (!in_array($method, ['address', 'office', 'automat'], true)) { $method = 'office'; }
        WC()->session->set('bgcouriers_method', $method);
        WC()->session->set('bgcouriers_selection_courier', sanitize_key(wp_unslash($_POST['courier'] ?? '')));
        $country = strtoupper(sanitize_text_field(wp_unslash($_POST['country'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $allowed = BGCouriers_Settings::delivery_countries(sanitize_key(wp_unslash($_POST['courier'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $country = in_array($country, $allowed, true) ? $country : BGCouriers_Settings::home_country();
        WC()->session->set('bgcouriers_country', $country);
        // WooCommerce picks the shipping zone from the customer's shipping country, so it must follow ours.
        if (function_exists('WC') && WC()->customer
            && strtoupper((string) WC()->customer->get_shipping_country()) !== $country) {
            WC()->customer->set_shipping_country($country);
            WC()->customer->save();
        }
        WC()->session->set('bgcouriers_site_id', (int) wp_unslash($_POST['site_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgcouriers_office_id', (int) wp_unslash($_POST['office_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgcouriers_post_code', sanitize_text_field(wp_unslash($_POST['post_code'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        foreach (self::address_fields($_POST) as $k => $v) { WC()->session->set('bgcouriers_addr_' . $k, $v); }
        self::remember_for_courier(
            sanitize_key(wp_unslash($_POST['courier'] ?? '')), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            $method,
            (int) wp_unslash($_POST['site_id'] ?? 0), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
            (int) wp_unslash($_POST['office_id'] ?? 0) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        );
        wp_send_json_success(['ok' => true]);
    }

    /** Per-courier memory of the selection, beside (never instead of) the session's current one, so switching courier does not reprice the first. */
    public static function remember_for_courier(string $courier, string $method, int $site_id, int $office_id): void {
        if ($courier === '' || !function_exists('WC') || !WC()->session) { return; }
        $all = (array) WC()->session->get('bgcouriers_sel_by_courier', []);
        $all[$courier] = ['method' => $method, 'site_id' => $site_id, 'office_id' => $office_id];
        WC()->session->set('bgcouriers_sel_by_courier', $all);
    }
}
