<?php
defined('ABSPATH') || exit;

class BGC_Ajax {
    public function __construct() {
        foreach (['search_cities','offices','city_avail','streets','set_selection','geocode'] as $a) {
            add_action("wp_ajax_bgc_{$a}", [$this, $a]);
            add_action("wp_ajax_nopriv_bgc_{$a}", [$this, $a]);
        }
    }

    /**
     * Reverse-geocode a lat/lng to Bulgarian address parts for the checkout address-map picker.
     * Uses Google when the admin has set a Maps API key (better accuracy), else OpenStreetMap Nominatim.
     * Result cached per rounded coordinate. Returns { city, postcode, street, number }.
     */
    public function geocode(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $lat = round((float) wp_unslash($_GET['lat'] ?? 0), 5); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float-cast, no state change
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $lng = round((float) wp_unslash($_GET['lng'] ?? 0), 5); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float-cast, no state change
        if ($lat === 0.0 && $lng === 0.0) { wp_send_json([]); }
        $tkey = 'bgc_geo_' . str_replace(['.', '-'], ['', 'm'], $lat . '_' . $lng);
        $cached = get_transient($tkey);
        if (is_array($cached)) { wp_send_json($cached); }
        $key = trim((string) get_option('bgc_google_maps_key', ''));
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
        return BGC_Nomenclature::search_cities($courier, $term, BGC_Settings::dropdown_limit());
    }
    public function search_cities(): void { wp_send_json(self::search_cities_data()); }
    public function offices(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $courier = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $type = sanitize_key(wp_unslash($_GET['type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $limit = !empty($_GET['all']) ? 100000 : BGC_Settings::dropdown_limit(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- all=1 -> the full city list, for the client cache
        wp_send_json(self::city_offices($courier, $city, $type, $term, $limit));
    }

    /** Which office types a city has (so the checkout can grey out a delivery option the city lacks). */
    public function city_avail(): void {
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $office = false; $automat = false;
        if ($city > 0) {
            $rows = [];
            try { $c = BGC_Couriers::get($courier_id); if ($c) { $rows = $c->fetch_offices($city); } }
            catch (\Exception $e) { $rows = []; }
            if (empty($rows)) { $rows = BGC_Nomenclature::offices($courier_id, $city); } // fallback to cache
            foreach ($rows as $o) {
                $t = $o['type'] ?? '';
                if ($t === 'office') { $office = true; } elseif ($t === 'automat') { $automat = true; }
            }
        }
        wp_send_json(['office' => $office, 'automat' => $automat]);
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
            $tkey   = 'bgc_off_' . $courier_id . '_' . $city;
            $cached = get_transient($tkey);
            if (is_array($cached)) {
                $rows = $cached;
            } else {
                try {
                    $courier = BGC_Couriers::get($courier_id);
                    if (!$courier) { return []; } // honor the array return type; the AJAX handler sends the empty JSON
                    $rows = $courier->fetch_offices($city);
                    if (!empty($rows)) { set_transient($tkey, $rows, 6 * HOUR_IN_SECONDS); }
                } catch (\Exception $e) { $rows = BGC_Nomenclature::offices($courier_id, $city); }
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
    public function streets(): void {
        $courier_id = sanitize_key(wp_unslash($_GET['courier'] ?? 'speedy')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only nomenclature endpoint, no state change
        $city = (int) wp_unslash($_GET['city_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast, no state change
        $term = sanitize_text_field(wp_unslash($_GET['term'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- public read-only nomenclature endpoint, no state change
        $out = [];
        if ($city > 0 && $term !== '') {
            try {
                $courier = BGC_Couriers::get($courier_id);
                if (!$courier) { wp_send_json([]); }
                if (method_exists($courier, 'search_streets')) {
                    $out = array_slice($courier->search_streets($city, $term), 0, BGC_Settings::dropdown_limit());
                }
            } catch (\Exception $e) { $out = []; }
        }
        wp_send_json($out);
    }
    public function set_selection(): void {
        check_ajax_referer('bgc_checkout', 'nonce');
        $method = sanitize_key($_POST['method'] ?? 'office');
        if (!in_array($method, ['address', 'office', 'automat'], true)) { $method = 'office'; }
        WC()->session->set('bgc_method', $method);
        WC()->session->set('bgc_selection_courier', sanitize_key($_POST['courier'] ?? '')); // which courier this selection belongs to
        WC()->session->set('bgc_site_id', (int) wp_unslash($_POST['site_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgc_office_id', (int) wp_unslash($_POST['office_id'] ?? 0)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- int-cast
        WC()->session->set('bgc_post_code', sanitize_text_field(wp_unslash($_POST['post_code'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        WC()->session->set('bgc_boxnow_name', sanitize_text_field(wp_unslash($_POST['boxnow_name'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- BoxNow locker label
        WC()->session->set('bgc_boxnow_addr', sanitize_text_field(wp_unslash($_POST['boxnow_addr'] ?? ''))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        foreach (self::address_fields($_POST) as $k => $v) { WC()->session->set('bgc_addr_' . $k, $v); }
        wp_send_json_success(['ok' => true]);
    }
}
