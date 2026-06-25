<?php
defined('ABSPATH') || exit;

class BGC_Checkout {
    public function __construct() {
        add_action('woocommerce_after_shipping_rate', [$this, 'render_fields'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 10, 1);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'package_hash']);
        add_filter('woocommerce_checkout_fields', [$this, 'simplify_fields']);
    }

    /**
     * The plugin collects the delivery address in its own fields, so the standard WC address
     * fields are redundant. The documented way to drop checkout fields is to unset() them from
     * the woocommerce_checkout_fields filter (classic checkout) — they are then never rendered
     * and never validated (no flicker, no hidden DOM). persist() sets the order's address from
     * our selection, and billing_country is kept so the Bulgaria shipping zone still matches.
     */
    public function simplify_fields($fields) {
        foreach (['billing', 'shipping'] as $g) {
            foreach (['address_1', 'address_2', 'city', 'state', 'postcode'] as $f) {
                unset($fields[$g][$g . '_' . $f]);
            }
        }
        return $fields;
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
        if ($method === 'address') {
            $street = (string) WC()->session->get('bgc_addr_street_name', '');
            $no     = (string) WC()->session->get('bgc_addr_street_no', '');
            if ($street === '' || $no === '') {
                $errors->add('bgc', __('Please enter a street and number for Speedy address delivery.', 'bg-couriers'));
            }
        }
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
        $order->update_meta_data('_bgc_street_name', (string) $s->get('bgc_addr_street_name', ''));
        $order->update_meta_data('_bgc_street_no',   (string) $s->get('bgc_addr_street_no', ''));
        $order->update_meta_data('_bgc_complex',     (string) $s->get('bgc_addr_complex', ''));
        $order->update_meta_data('_bgc_block',       (string) $s->get('bgc_addr_block', ''));
        $order->update_meta_data('_bgc_entrance',    (string) $s->get('bgc_addr_entrance', ''));
        $order->update_meta_data('_bgc_floor',       (string) $s->get('bgc_addr_floor', ''));
        $order->update_meta_data('_bgc_apartment',   (string) $s->get('bgc_addr_apartment', ''));
        $order->update_meta_data('_bgc_address_note',(string) $s->get('bgc_addr_address_note', ''));

        // Fill the WC order address from our selection (the standard WC address fields are
        // hidden/optional on checkout); the shipping label still uses the _bgc_* meta above.
        $method = (string) $s->get('bgc_method', 'office');
        $city   = (int) $s->get('bgc_site_id', 0) ? BGC_Nomenclature::city_by_id('speedy', (int) $s->get('bgc_site_id', 0)) : null;
        $name   = (string) ($city['name'] ?? '');
        $post   = (string) $s->get('bgc_post_code', '') ?: (string) ($city['post_code'] ?? '');
        $region = (string) ($city['region'] ?? '');
        if ($method === 'address') {
            $line1 = trim((string) $s->get('bgc_addr_street_name', '') . ' ' . (string) $s->get('bgc_addr_street_no', ''));
            $line2 = trim((string) $s->get('bgc_addr_complex', ''));
        } else {
            $o = (int) $s->get('bgc_office_id', 0) ? BGC_Nomenclature::office_by_id('speedy', (int) $s->get('bgc_office_id', 0)) : null;
            $line1 = (string) ($o['name'] ?? '');
            $line2 = (string) ($o['address'] ?? '');
        }
        foreach (['billing', 'shipping'] as $g) {
            $order->{"set_{$g}_country"}('BG');
            $order->{"set_{$g}_city"}($name);
            $order->{"set_{$g}_state"}($region);
            $order->{"set_{$g}_postcode"}($post);
            $order->{"set_{$g}_address_1"}($line1);
            $order->{"set_{$g}_address_2"}($line2);
        }
        $order->set_shipping_first_name($order->get_billing_first_name());
        $order->set_shipping_last_name($order->get_billing_last_name());
    }
    public function assets(): void {
        if (!function_exists('is_checkout') || !is_checkout()) { return; }
        wp_enqueue_style('select2');
        // Version by file mtime so every asset change busts the browser cache automatically.
        $css = BGC_PATH . 'assets/css/bgc-checkout.css';
        $js  = BGC_PATH . 'assets/js/bgc-checkout.js';
        wp_enqueue_style('bgc-checkout', BGC_URL . 'assets/css/bgc-checkout.css', [], is_file($css) ? (string) filemtime($css) : BGC_VERSION);
        wp_enqueue_script('bgc-checkout', BGC_URL . 'assets/js/bgc-checkout.js', ['jquery', 'selectWoo'], is_file($js) ? (string) filemtime($js) : BGC_VERSION, true);
        wp_localize_script('bgc-checkout', 'BGC', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bgc_checkout'),
            'currency' => get_woocommerce_currency(),
            'methods' => BGC_Settings::enabled_methods('speedy'),
            'order'   => BGC_Settings::method_order('speedy'),
            'emergency' => BGC_Settings::emergency(),
            'i18n'  => [
                'address'=>__('To address','bg-couriers'),'office'=>__('To office','bg-couriers'),'automat'=>__('To automat','bg-couriers'),
                'office_label'=>__('Office','bg-couriers'),'automat_label'=>__('Automat (locker)','bg-couriers'),
                'emerg_default'=>__('Having trouble placing your order? We can help — call us:','bg-couriers'),
                'close'=>__('Close','bg-couriers'),
                'city_ph' => __('Type a city…','bg-couriers'),'office_ph'=>__('Search…','bg-couriers'),'street_ph'=>__('Type a street…','bg-couriers'),
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
        // Office/automat picker shows for office+automat methods, hides for address.
        $office_style = ($sel_method === 'address') ? ' style="display:none;"' : '';

        $av = function ($k) use ($s) { return $s ? esc_attr((string) $s->get('bgc_addr_' . $k, '')) : ''; };
        $sn = $s ? (string) $s->get('bgc_addr_street_name', '') : '';
        $street_option = $sn !== '' ? '<option value="' . esc_attr($sn) . '" selected>' . esc_html($sn) . '</option>' : '';
        $addr_style = ($sel_method === 'address') ? '' : ' style="display:none;"';

        $office_label = ($sel_method === 'automat')
            ? esc_html__('Automat (locker)', 'bg-couriers')
            : esc_html__('Office', 'bg-couriers');

        echo '<div class="bgc-fields" data-courier="speedy" data-method="' . esc_attr($sel_method) . '">'
           . '<div class="bgc-loader" aria-hidden="true"><span class="bgc-spinner"></span></div>'
           . '<div class="bgc-tabs" role="tablist"></div>'
           . '<div class="bgc-panel">'
           . '<div class="bgc-grid">'
           . '<div class="bgc-field bgc-city-field"><label>' . esc_html__('City', 'bg-couriers') . '</label>'
           . '<select class="bgc-city"><option value=""></option>' . $city_option . '</select></div>'
           . '<div class="bgc-field bgc-postcode-field"><label>' . esc_html__('Postcode', 'bg-couriers') . '</label>'
           . '<input type="text" class="bgc-postcode" autocomplete="off" inputmode="numeric" maxlength="4" placeholder="' . esc_attr__('opt.', 'bg-couriers') . '" value="' . esc_attr($post_code) . '"></div>'
           . '</div>'
           . '<div class="bgc-field bgc-office-row"' . $office_style . '><label class="bgc-office-label">' . $office_label . '</label>'
           . '<select class="bgc-office">' . $office_option . '</select></div>'
           . '<div class="bgc-address-rows"' . $addr_style . '>'
           . '<div class="bgc-grid">'
           . '<div class="bgc-field bgc-street-field"><label>' . esc_html__('Street', 'bg-couriers') . ' *</label><select class="bgc-street"><option value=""></option>' . $street_option . '</select></div>'
           . '<div class="bgc-field bgc-streetno-field"><label>' . esc_html__('No.', 'bg-couriers') . ' *</label><input type="text" class="bgc-street-no" autocomplete="off" value="' . $av('street_no') . '"></div>'
           . '</div>'
           . '<div class="bgc-field"><label>' . esc_html__('Quarter / complex', 'bg-couriers') . '</label><input type="text" class="bgc-complex" autocomplete="off" value="' . $av('complex') . '"></div>'
           . '<div class="bgc-grid bgc-grid-4">'
           . '<div class="bgc-field"><label>' . esc_html__('Block', 'bg-couriers') . '</label><input type="text" class="bgc-block" value="' . $av('block') . '"></div>'
           . '<div class="bgc-field"><label>' . esc_html__('Entr.', 'bg-couriers') . '</label><input type="text" class="bgc-entrance" value="' . $av('entrance') . '"></div>'
           . '<div class="bgc-field"><label>' . esc_html__('Floor', 'bg-couriers') . '</label><input type="text" class="bgc-floor" value="' . $av('floor') . '"></div>'
           . '<div class="bgc-field"><label>' . esc_html__('Apt.', 'bg-couriers') . '</label><input type="text" class="bgc-apartment" value="' . $av('apartment') . '"></div>'
           . '</div>'
           . '<div class="bgc-field"><label>' . esc_html__('Note', 'bg-couriers') . '</label><input type="text" class="bgc-note" autocomplete="off" value="' . $av('address_note') . '"></div>'
           . '</div>'
           . '</div>'
           . '</div>';
    }
}
