<?php
defined('ABSPATH') || exit;

class BGC_Ajax {
    public function __construct() {
        foreach (['search_cities','offices','city_avail','streets','set_selection'] as $a) {
            add_action("wp_ajax_bgc_{$a}", [$this, $a]);
            add_action("wp_ajax_nopriv_bgc_{$a}", [$this, $a]);
        }
    }
    public static function address_fields(array $src): array {
        $keys = ['street_name','street_no','complex','block','entrance','floor','apartment','address_note'];
        $out = [];
        foreach ($keys as $k) { $out[$k] = sanitize_text_field((string) ($src[$k] ?? '')); }
        return $out;
    }
    public static function search_cities_data(): array {
        $courier = sanitize_key($_GET['courier'] ?? 'speedy');
        $term = sanitize_text_field($_GET['term'] ?? '');
        // No term -> first N cities alphabetically; with a term -> matches, N max (sorted by name).
        return BGC_Nomenclature::search_cities($courier, $term, BGC_Settings::dropdown_limit());
    }
    public function search_cities(): void { wp_send_json(self::search_cities_data()); }
    public function offices(): void {
        $courier = sanitize_key($_GET['courier'] ?? 'speedy');
        $city = (int) ($_GET['city_id'] ?? 0);
        $type = sanitize_key($_GET['type'] ?? '');
        $term = sanitize_text_field($_GET['term'] ?? '');
        $limit = !empty($_GET['all']) ? 100000 : BGC_Settings::dropdown_limit(); // all=1 -> the full city list, for the client cache
        wp_send_json(self::city_offices($courier, $city, $type, $term, $limit));
    }

    /** Which office types a city has (so the checkout can grey out a delivery option the city lacks). */
    public function city_avail(): void {
        $courier_id = sanitize_key($_GET['courier'] ?? 'speedy');
        $city = (int) ($_GET['city_id'] ?? 0);
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
     * Office/automat list for one city — fetched LIVE per-city (the country-wide nomenclature
     * is capped by Speedy and misses most cities), filtered by type + search term, sorted by
     * office number, limited to N. Falls back to the cached nomenclature when the API is down.
     */
    public static function city_offices(string $courier_id, int $city, string $type, string $term = '', int $limit = 5): array {
        $rows = [];
        if ($city > 0) {
            try {
                $courier = BGC_Couriers::get($courier_id);
                if (!$courier) { return []; } // honor the array return type; the AJAX handler sends the empty JSON
                $rows = $courier->fetch_offices($city);
            } catch (\Exception $e) { $rows = BGC_Nomenclature::offices($courier_id, $city); }
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
        $courier_id = sanitize_key($_GET['courier'] ?? 'speedy');
        $city = (int) ($_GET['city_id'] ?? 0);
        $term = sanitize_text_field($_GET['term'] ?? '');
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
        WC()->session->set('bgc_site_id', (int) ($_POST['site_id'] ?? 0));
        WC()->session->set('bgc_office_id', (int) ($_POST['office_id'] ?? 0));
        WC()->session->set('bgc_post_code', sanitize_text_field($_POST['post_code'] ?? ''));
        WC()->session->set('bgc_boxnow_name', sanitize_text_field($_POST['boxnow_name'] ?? '')); // BoxNow locker label
        WC()->session->set('bgc_boxnow_addr', sanitize_text_field($_POST['boxnow_addr'] ?? ''));
        foreach (self::address_fields($_POST) as $k => $v) { WC()->session->set('bgc_addr_' . $k, $v); }
        wp_send_json_success(['ok' => true]);
    }
}
