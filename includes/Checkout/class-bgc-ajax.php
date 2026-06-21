<?php
defined('ABSPATH') || exit;

class BGC_Ajax {
    public function __construct() {
        foreach (['search_cities','offices','set_selection'] as $a) {
            add_action("wp_ajax_bgc_{$a}", [$this, $a]);
            add_action("wp_ajax_nopriv_bgc_{$a}", [$this, $a]);
        }
    }
    public static function search_cities_data(): array {
        $courier = sanitize_key($_GET['courier'] ?? 'speedy');
        $term = sanitize_text_field($_GET['term'] ?? '');
        return BGC_Nomenclature::search_cities($courier, $term);
    }
    public function search_cities(): void { wp_send_json(self::search_cities_data()); }
    public function offices(): void {
        $courier = sanitize_key($_GET['courier'] ?? 'speedy');
        $city = (int) ($_GET['city_id'] ?? 0);
        $type = sanitize_key($_GET['type'] ?? '');
        wp_send_json(BGC_Nomenclature::offices($courier, $city, $type));
    }
    public function set_selection(): void {
        check_ajax_referer('bgc_checkout', 'nonce');
        $method = sanitize_key($_POST['method'] ?? 'office');
        WC()->session->set('bgc_method', $method);
        WC()->session->set('bgc_site_id', (int) ($_POST['site_id'] ?? 0));
        WC()->session->set('bgc_office_id', (int) ($_POST['office_id'] ?? 0));
        wp_send_json_success(['ok' => true]);
    }
}
