<?php
defined('ABSPATH') || exit;

class BGC_Checkout {
    public function __construct() {
        add_action('woocommerce_after_shipping_rate', [$this, 'render_fields'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 10, 1);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'package_hash']);
    }

    /**
     * Our shipping cost depends on the session selection (method/city/office), which is NOT part
     * of the package WC hashes to cache shipping rates. Inject it so the cache busts when the
     * customer changes courier office/city — otherwise WC serves a stale rate from the first calc.
     */
    public function package_hash($packages) {
        $s = WC()->session;
        if (!$s) { return $packages; }
        $key = (string) $s->get('bgc_method', '') . ':' . (int) $s->get('bgc_site_id', 0) . ':' . (int) $s->get('bgc_office_id', 0);
        foreach ($packages as $i => $pkg) { $packages[$i]['bgc_selection'] = $key; }
        return $packages;
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
        // Stateful: re-render the session selection so update_checkout recalcs don't wipe the fields.
        $s = WC()->session;
        $sel_method = $s ? (string) $s->get('bgc_method', '') : '';
        $site_id    = $s ? (int) $s->get('bgc_site_id', 0) : 0;
        $office_id  = $s ? (int) $s->get('bgc_office_id', 0) : 0;
        $post_code  = $s ? (string) $s->get('bgc_post_code', '') : '';

        $city_option = '';
        if ($site_id) {
            $city = BGC_Nomenclature::city_by_id('speedy', $site_id);
            if ($city) {
                $city_option = '<option value="' . esc_attr($site_id) . '" selected>' . esc_html($city['name']) . '</option>';
            }
        }
        $office_option = '';
        if ($office_id) {
            $office = BGC_Nomenclature::office_by_id('speedy', $office_id);
            if ($office) {
                $office_option = '<option value="' . esc_attr($office_id) . '" selected>' . esc_html($office['name'] . ' — ' . $office['address']) . '</option>';
            }
        }
        $office_style = $office_option ? '' : ' style="display:none;"';

        echo '<div class="bgc-fields" data-courier="speedy" data-method="' . esc_attr($sel_method) . '">'
           . '<div class="bgc-types"></div>'
           . '<p class="bgc-row bgc-postcode-row"><label>' . esc_html__('Postal code (optional)','bg-couriers') . '</label><input type="text" class="bgc-postcode" autocomplete="off" value="' . esc_attr($post_code) . '"></p>'
           . '<p class="bgc-row"><label>' . esc_html__('City','bg-couriers') . '</label><select class="bgc-city" style="width:100%"><option value=""></option>' . $city_option . '</select></p>'
           . '<p class="bgc-row bgc-office-row"' . $office_style . '><label>' . esc_html__('Office / Automat','bg-couriers') . '</label><select class="bgc-office" style="width:100%">' . $office_option . '</select></p>'
           . '</div>';
    }
}
