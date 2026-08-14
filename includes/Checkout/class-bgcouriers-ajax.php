<?php
defined('ABSPATH') || exit;

class BGCouriers_Ajax {
    public function __construct() {
        foreach (['search_cities','offices','city_avail','streets','set_selection','geocode','allmap_cities','allmap_offices'] as $a) {
            add_action("wp_ajax_bgcouriers_{$a}", [$this, $a]);
            add_action("wp_ajax_nopriv_bgcouriers_{$a}", [$this, $a]);
        }
    }

    /** Best-effort client IP for rate-limiting only (honours a CDN/proxy header, falls back to REMOTE_ADDR). */
    private static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (empty($_SERVER[$h])) { continue; }
            $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$h])))[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
        }
        return 'unknown';
    }

    /**
     * Lightweight per-IP rate limit for the public endpoints that hit a LIVE courier API. Returns false once
     * the caller exceeds $max requests within $window seconds; the handler then returns an empty result
     * instead of making an outbound call, so anonymous enumeration can't amplify into many courier API calls.
     *
     * The budget is per IP, and "a real checkout makes only a handful of these calls" turned out to be
     * wrong: one checkout load asks availability and offices for every enabled courier, and a customer
     * who picks a city, switches courier and opens the map spends tens of calls on their own. At 90 a
     * minute the plugin's own end-to-end suite exhausted it, and so would two customers sharing an
     * address - an office, or a mobile carrier behind CGNAT, which is most phone traffic here. An
     * emptied map with no error is the worst possible way to find that out. 300 leaves room for several
     * real customers at once while still being far below what scraping a nomenclature would need.
     */
    private static function rate_ok(int $max = 300, int $window = 60): bool {
        $key = 'bgcouriers_rl_' . md5(self::client_ip());
        $n   = (int) get_transient($key);
        if ($n >= $max) { return false; }
        set_transient($key, $n + 1, $window);
        return true;
    }

    /**
     * Reverse-geocode a lat/lng to Bulgarian address parts for the checkout address-map picker.
     * Uses Google when the admin has set a Maps API key (better accuracy), else OpenStreetMap Nominatim.
     * Result cached per rounded coordinate. Returns { city, postcode, street, number }.
     */
    public function geocode(): void {
        if (!self::rate_ok()) { wp_send_json([]); }
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
        if (is_wp_error($r)) { return self::geocode_nominatim($lat, $lng); } // fall back to OSM
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
        $keys = ['street_name','street_no','complex','block','entrance','floor','apartment','address_note'];
        $out = [];
        foreach ($keys as $k) { $out[$k] = sanitize_text_field((string) ($src[$k] ?? '')); }
        return $out;
    }
    public static function search_cities_data(): array {
        $courier = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        // No term -> first N cities alphabetically; with a term -> matches, N max (sorted by name).
        return BGCouriers_Nomenclature::search_cities($courier, $term, BGCouriers_Settings::dropdown_limit());
    }
    public function search_cities(): void { wp_send_json(self::search_cities_data()); }
    public function offices(): void {
        if (!self::rate_ok()) { wp_send_json([]); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $courier = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $type = sanitize_key(wp_unslash($_GET['type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $limit = !empty($_GET['all']) ? 100000 : BGCouriers_Settings::dropdown_limit(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- all=1 -> the full city list, for the client cache
        wp_send_json(self::city_offices($courier, $city, $type, $term, $limit));
    }

    /** Which office types a city has (so the checkout can grey out a delivery option the city lacks). */
    public function city_avail(): void {
        if (!self::rate_ok()) { wp_send_json(['office' => false, 'automat' => false]); }
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        if ($city <= 0) { wp_send_json(['office' => false, 'automat' => false]); }
        // Cache the availability per courier+city - it was uncached, so every call hit the live API.
        $tkey   = 'bgcouriers_avail_' . $courier_id . '_' . $city;
        $cached = get_transient($tkey);
        if (is_array($cached)) { wp_send_json($cached); }
        $office = false; $automat = false;
        $rows = [];
        try { $c = BGCouriers_Couriers::get($courier_id); if ($c) { $rows = $c->fetch_offices($city); } }
        catch (\Exception $e) { $rows = []; }
        if (empty($rows)) { $rows = BGCouriers_Nomenclature::offices($courier_id, $city); } // fallback to cache
        foreach ($rows as $o) {
            $t = $o['type'] ?? '';
            if ($t === 'office') { $office = true; } elseif ($t === 'automat') { $automat = true; }
        }
        $res = ['office' => $office, 'automat' => $automat];
        set_transient($tkey, $res, 6 * HOUR_IN_SECONDS);
        wp_send_json($res);
    }

    /**
     * Office/automat list for one city - fetched LIVE per-city (the country-wide nomenclature
     * is capped by Speedy and misses most cities), filtered by type + search term, sorted by
     * office number, limited to N. Falls back to the cached nomenclature when the API is down.
     */
    public static function city_offices(string $courier_id, int $city, string $type, string $term = '', int $limit = 5): array {
        $rows = [];
        if ($city > 0) {
            // Cache the (live) office list per courier+city - offices change rarely, so this turns the first
            // fetch into an instant response for everyone after, killing the checkout's biggest round-trip.
            $tkey   = 'bgcouriers_off_' . $courier_id . '_' . $city;
            $cached = get_transient($tkey);
            if (is_array($cached)) {
                $rows = $cached;
            } else {
                try {
                    $courier = BGCouriers_Couriers::get($courier_id);
                    if (!$courier) { return []; } // honor the array return type; the AJAX handler sends the empty JSON
                    $rows = $courier->fetch_offices($city);
                    if (!empty($rows)) { set_transient($tkey, $rows, 6 * HOUR_IN_SECONDS); }
                // \Throwable, not \Exception: a courier adapter that hits a TypeError - or any Error -
                // used to walk straight out of here and turn the whole request into a 500, when the
                // synced table beside it could have answered. Nothing about a broken adapter should
                // cost the customer their office list.
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
    /**
     * Places for the combined map's city picker, gathered across EVERY enabled courier rather than one
     * of them, so a town that only one courier lists is still a candidate. Distinct by name + post code,
     * because that pair is what identifies a place to the other couriers.
     *
     * The dedup key is lower-cased: couriers spell the same place with different casing (Speedy's
     * nomenclature is upper-case, e.g. "СОФИЯ" vs another courier's "София"), and comparing the raw
     * name would show the customer the same city twice. The label keeps whichever spelling was seen
     * FIRST, so the list still reads as one real courier's own wording, not a synthetic normalisation.
     *
     * The final sort is case-insensitive for the same reason: plain strcmp() is byte order, so every
     * upper-case spelling would sort as its own block ahead of (or behind) the lower-case ones instead of
     * interleaving alphabetically - and since the result is THEN sliced to 30, a single-courier place
     * could be pushed out of that slice purely by how one courier capitalises it, not because 30 genuinely
     * earlier places exist. This does not raise the 30-item cap itself: with more than 30 real candidates
     * for a term, the alphabetically-last ones are still dropped, same as before.
     */
    public function allmap_cities(): void {
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $seen = [];
        $out  = [];
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes') { continue; }
            foreach (BGCouriers_Nomenclature::search_cities($cid, $term, 30) as $row) {
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

    /**
     * Every enabled courier's pickup points for ONE place, in one request. Each courier is asked with
     * the city id IT issued (see BGCouriers_Nomenclature::match_city) - a shared id does not exist.
     */
    public function allmap_offices(): void {
        // ONE charge for the whole request, deliberately. This does fan out to a lookup per enabled
        // courier, but that count is a small fixed bound - five couriers exist - not something a caller
        // can grow, which is what the limiter is for. Charging per courier instead made a single map
        // opening cost six units of a ninety-per-minute budget shared by everyone behind one IP, and a
        // shop's customers on the same office or mobile network then got an empty map. The client also
        // caches per place, so looking at a city twice costs nothing.
        if (!self::rate_ok()) { wp_send_json([]); }
        $name = sanitize_text_field(wp_unslash($_GET['name'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $code = sanitize_text_field(wp_unslash($_GET['post_code'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $type = sanitize_key(wp_unslash($_GET['type'] ?? 'both')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!in_array($type, ['office', 'automat', 'both'], true)) { wp_send_json([]); }
        // 'both' is the normal case: a customer looking for somewhere to collect from does not care
        // whether the place is a staffed office or a locker, so the map shows both and each point says
        // which it is. Every point carries its own type because that is what the checkout must be set
        // to when the point is chosen - it is per point, not per dialog.
        $types = $type === 'both' ? ['office', 'automat'] : [$type];
        $out = self::allmap_collect($name, $code, $types, false);
        // Nothing at all from the local nomenclature means this shop has never run a sync - not that the
        // place is empty. Only then is the live path worth its cost, and only then does one slow courier
        // matter, because by definition there is nothing to lose.
        if (!$out) { $out = self::allmap_collect($name, $code, $types, true); }
        wp_send_json($out);
    }

    /**
     * Every enabled courier's points for one place.
     *
     * Reads the SYNCED tables, not the couriers' live endpoints. The live path is what
     * BGCouriers_Ajax::city_offices() does, and it is right for a single courier's own picker - but this
     * endpoint fans out across every enabled courier and both delivery types, which is up to eight live
     * API calls inside one request. That reliably killed the request whenever the 6-hour per-city
     * transient was cold: measured on dev, clearing the transients for Пловдив turned this endpoint from
     * 200 in ~2s into a 500 with an empty body in ~6s, and the customer got a blank map with no error.
     * One slow courier should not be able to take down a map of five.
     *
     * The tables carry the same thing: the same run gave 37+50 / 36+3 / 16+0 / 0+90 points for Пловдив
     * against the live 37+50 / 36+3 / 16+0 / 0+91 - a single locker added since the last sync, which the
     * next sync picks up. A day-old office list is the right trade for a map that always answers.
     *
     * @param string $name  Place name as the customer's chosen suggestion spells it.
     * @param string $code  Post code, '' when unknown.
     * @param array  $types Delivery types wanted, e.g. ['office','automat'].
     * @param bool   $live  Ask the couriers directly instead of reading the synced tables.
     * @return array courier id => ['city_id' => int, 'offices' => array]
     */
    private static function allmap_collect(string $name, string $code, array $types, bool $live): array {
        $out = [];
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes') { continue; }
            $carries = BGCouriers_Settings::enabled_methods($cid);
            $wanted = array_values(array_intersect($types, $carries));
            if (!$wanted) { continue; }
            $city = BGCouriers_Nomenclature::match_city($cid, $name, $code);
            if (!$city) { continue; }
            $rows = [];
            foreach ($wanted as $t) {
                $found = $live
                    ? self::city_offices($cid, (int) $city['city_id'], $t, '', 100000)
                    : BGCouriers_Nomenclature::offices($cid, (int) $city['city_id'], $t);
                foreach ($found as $office) {
                    $office['type'] = $t;
                    $rows[] = $office;
                }
            }
            if (!$rows) { continue; }
            // A price PER DELIVERY TYPE, because a courier does not charge one. The map used to label
            // every point of a courier with whatever its rate row happened to be showing, which is the
            // price of the type currently selected: with Speedy on "to office" its lockers were
            // advertised at 2.64 instead of 1.52, and the moment the customer switched to a locker its
            // offices were advertised at 1.52 instead. Whichever tab you were on, the other one lied.
            // estimate() is the same figure the checkout shows before a city is chosen and costs no live
            // call - it reads the fixed price or the daily cached reference.
            $prices = [];
            foreach ($wanted as $t) {
                $v = BGCouriers_Pricing::estimate($cid, $t);
                // Decoded, not merely stripped: wc_price() spells the amount with &nbsp; and &euro;, and
                // the map escapes whatever it is handed before printing it - so the entities would reach
                // the customer as the literal text "1,52&nbsp;&euro;".
                if ($v !== null) {
                    $prices[$t] = html_entity_decode(wp_strip_all_tags(wc_price((float) $v)), ENT_QUOTES, 'UTF-8');
                }
            }
            $out[$cid] = ['city_id' => (int) $city['city_id'], 'prices' => $prices, 'offices' => $rows];
        }
        return $out;
    }
    public function streets(): void {
        if (!self::rate_ok()) { wp_send_json([]); }
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $out = [];
        if ($city > 0 && $term !== '') {
            try {
                $courier = BGCouriers_Couriers::get($courier_id);
                if (!$courier) { wp_send_json([]); }
                if (method_exists($courier, 'search_streets')) {
                    $out = array_slice($courier->search_streets($city, $term), 0, BGCouriers_Settings::dropdown_limit());
                }
            } catch (\Exception $e) { $out = []; }
        }
        wp_send_json($out);
    }
    public function set_selection(): void {
        check_ajax_referer('bgcouriers_checkout', 'nonce');
        $method = sanitize_key(wp_unslash($_POST['method'] ?? 'office'));
        if (!in_array($method, ['address', 'office', 'automat'], true)) { $method = 'office'; }
        WC()->session->set('bgcouriers_method', $method);
        WC()->session->set('bgcouriers_selection_courier', sanitize_key(wp_unslash($_POST['courier'] ?? ''))); // which courier this selection belongs to
        WC()->session->set('bgcouriers_site_id', (int) wp_unslash($_POST['site_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgcouriers_office_id', (int) wp_unslash($_POST['office_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgcouriers_post_code', sanitize_text_field(wp_unslash($_POST['post_code'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        WC()->session->set('bgcouriers_boxnow_name', sanitize_text_field(wp_unslash($_POST['boxnow_name'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- BoxNow locker label
        WC()->session->set('bgcouriers_boxnow_addr', sanitize_text_field(wp_unslash($_POST['boxnow_addr'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        foreach (self::address_fields($_POST) as $k => $v) { WC()->session->set('bgcouriers_addr_' . $k, $v); }
        wp_send_json_success(['ok' => true]);
    }
}
