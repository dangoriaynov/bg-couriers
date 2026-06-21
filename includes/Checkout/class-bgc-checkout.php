<?php
defined('ABSPATH') || exit;

class BGC_Checkout {
    public function __construct() {
        add_action('woocommerce_after_shipping_rate', [$this, 'render_fields'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }
    public function assets(): void {
        if (!function_exists('is_checkout') || !is_checkout()) { return; }
        wp_enqueue_style('bgc-checkout', BGC_URL . 'assets/css/bgc-checkout.css', [], BGC_VERSION);
        wp_enqueue_script('bgc-checkout', BGC_URL . 'assets/js/bgc-checkout.js', ['jquery'], BGC_VERSION, true);
        wp_localize_script('bgc-checkout', 'BGC', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bgc_checkout'),
            'dual'  => BGC_Settings::get('global', 'dual_currency', 'yes') === 'yes',
            'currency' => get_woocommerce_currency(),
            'i18n'  => ['address'=>__('To address','bg-couriers'),'office'=>__('To office','bg-couriers'),'automat'=>__('To automat','bg-couriers')],
        ]);
    }
    public function render_fields($method, $index): void {
        if ($method->get_method_id() !== 'bgc_speedy') { return; }
        echo '<div class="bgc-fields" data-courier="speedy">'
           . '<div class="bgc-types"></div>'
           . '<p class="bgc-row"><label>' . esc_html__('Postal code','bg-couriers') . '</label><input type="text" class="bgc-postcode" autocomplete="off"></p>'
           . '<p class="bgc-row"><label>' . esc_html__('City','bg-couriers') . '</label><input type="text" class="bgc-city" autocomplete="off"><input type="hidden" class="bgc-city-id"></p>'
           . '<p class="bgc-row bgc-office-row"><label>' . esc_html__('Office / Automat','bg-couriers') . '</label><select class="bgc-office"></select></p>'
           . '</div>';
    }
}
