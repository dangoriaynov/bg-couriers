<?php
defined('ABSPATH') || exit;

class BGC_Checkout {
    public function __construct() {
        add_action('woocommerce_after_shipping_rate', [$this, 'render_fields'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 10, 1);
    }

    public function chosen_is_speedy(): bool {
        $chosen = WC()->session ? (array) WC()->session->get('chosen_shipping_methods') : [];
        foreach ($chosen as $m) { if (strpos((string) $m, 'bgc_speedy') === 0) { return true; } }
        return false;
    }

    public function validate($data, $errors): void {
        if (!$this->chosen_is_speedy()) { return; }
        $site = (int) WC()->session->get('bgc_site_id', 0);
        $method = (string) WC()->session->get('bgc_method', 'office');
        $office = (int) WC()->session->get('bgc_office_id', 0);
        if (!$site) { $errors->add('bgc', __('Please choose a city for Speedy delivery.', 'bg-couriers')); }
        if ($method !== 'address' && !$office) { $errors->add('bgc', __('Please choose a Speedy office/automat.', 'bg-couriers')); }
    }

    public function persist(\WC_Order $order): void {
        if (!$this->chosen_is_speedy()) { return; }
        $s = WC()->session; if (!$s) { return; }
        $order->update_meta_data('_bgc_courier', 'speedy');
        $order->update_meta_data('_bgc_method', (string) $s->get('bgc_method', 'office'));
        $order->update_meta_data('_bgc_site_id', (int) $s->get('bgc_site_id', 0));
        $order->update_meta_data('_bgc_office_id', (int) $s->get('bgc_office_id', 0));
        $order->update_meta_data('_bgc_post_code', (string) $s->get('bgc_post_code', ''));
        $order->update_meta_data('_bgc_quote_price', (float) $s->get('bgc_quote_price', 0));
        $order->update_meta_data('_bgc_quote_source', (string) $s->get('bgc_quote_source', ''));
    }
    public function assets(): void {
        if (!function_exists('is_checkout') || !is_checkout()) { return; }
        wp_enqueue_style('select2');
        wp_enqueue_style('bgc-checkout', BGC_URL . 'assets/css/bgc-checkout.css', [], BGC_VERSION);
        wp_enqueue_script('bgc-checkout', BGC_URL . 'assets/js/bgc-checkout.js', ['jquery', 'selectWoo'], BGC_VERSION, true);
        wp_localize_script('bgc-checkout', 'BGC', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bgc_checkout'),
            'currency' => get_woocommerce_currency(),
            'methods' => BGC_Settings::enabled_methods('speedy'),
            'order'   => BGC_Settings::method_order('speedy'),
            'emergency' => BGC_Settings::emergency(),
            'i18n'  => [
                'address'=>__('To address','bg-couriers'),'office'=>__('To office','bg-couriers'),'automat'=>__('To automat','bg-couriers'),
                'emerg_default'=>__('Having trouble placing your order? We can help — call us:','bg-couriers'),
                'close'=>__('Close','bg-couriers'),
                'city_ph' => __('Type a city…','bg-couriers'),
            ],
        ]);

        // Hide configured checkout fields (CSS selectors from settings).
        $hidden = trim(BGC_Settings::hidden_fields());
        if ($hidden !== '') {
            $selectors = implode(',', array_filter(array_map('trim', explode(',', $hidden))));
            if ($selectors !== '') {
                wp_add_inline_style('bgc-checkout', $selectors . '{display:none !important;}');
            }
        }
    }
    public function render_fields($method, $index): void {
        if ($method->get_method_id() !== 'bgc_speedy') { return; }
        echo '<div class="bgc-fields" data-courier="speedy">'
           . '<div class="bgc-types"></div>'
           . '<p class="bgc-row bgc-postcode-row"><label>' . esc_html__('Postal code (optional)','bg-couriers') . '</label><input type="text" class="bgc-postcode" autocomplete="off"></p>'
           . '<p class="bgc-row"><label>' . esc_html__('City','bg-couriers') . '</label><select class="bgc-city" style="width:100%"></select></p>'
           . '<p class="bgc-row bgc-office-row"><label>' . esc_html__('Office / Automat','bg-couriers') . '</label><select class="bgc-office" style="width:100%"></select></p>'
           . '</div>';
    }
}
