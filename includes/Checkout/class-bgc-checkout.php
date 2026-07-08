<?php
defined('ABSPATH') || exit;

class BGC_Checkout {
    public function __construct() {
        add_action('woocommerce_after_shipping_rate', [$this, 'render_fields'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 10, 1);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'package_hash']);
        add_filter('woocommerce_package_rates', [$this, 'sort_rates'], 20);
        add_action('woocommerce_after_cart_totals', [$this, 'cart_estimate']); // shipping estimate on the cart page
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'dual_shipping_label'], 20, 2); // dual BGN/EUR on the rate
        add_filter('woocommerce_checkout_fields', [$this, 'simplify_fields']);
        // Free-shipping progress notice: render it in the checkout notice area + refresh it on every
        // recalculation via WC's fragment mechanism (server computes the remaining; no DOM parsing).
        add_action('woocommerce_before_checkout_form', [$this, 'render_free_notice'], 5);
        add_filter('woocommerce_update_order_review_fragments', [$this, 'free_notice_fragment']);
        add_filter('woocommerce_shipping_chosen_method', [$this, 'default_courier'], 10, 3);
    }

    /** Pre-select the configured default courier when the customer hasn't chosen a shipping method yet. */
    public function default_courier($default, $rates, $chosen_method) {
        if (!empty($chosen_method)) { return $default; } // respect an explicit choice
        $pref = (string) get_option('bgc_default_courier', '');
        if ($pref !== '') {
            foreach ((array) $rates as $id => $rate) {
                if (is_object($rate) && method_exists($rate, 'get_method_id') && $rate->get_method_id() === 'bgc_' . $pref) { return $id; }
            }
        }
        return $default;
    }

    public function render_free_notice(): void { echo wp_kses_post(self::free_notice_html()); }
    public function free_notice_fragment($fragments) { $fragments['.bgc-free-notice'] = self::free_notice_html(); return $fragments; }

    /** Append the pegged BGN/EUR equivalent to a shipping-method rate label when dual display is on. */
    public function dual_shipping_label($label, $method) {
        if (!BGC_Currency::enabled()) { return $label; }
        $store = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        if ($store !== 'EUR' && $store !== 'BGN') { return $label; }
        $cost = (float) $method->get_cost();
        if ($cost <= 0) { return $label; } // free / no cost — nothing to convert
        $taxes   = $method->get_taxes();
        $tax     = is_array($taxes) ? array_sum($taxes) : 0.0;
        $display = (get_option('woocommerce_tax_display_cart') === 'incl') ? ($cost + $tax) : $cost;
        $other   = $store === 'BGN' ? 'EUR' : 'BGN';
        return $label . ' <span class="bgc-dual">(' . BGC_Currency::fmt(BGC_Currency::convert($display, $store, $other), $other) . ')</span>';
    }

    /**
     * Shipping-cost estimate on the cart page (per enabled courier + delivery option), so the customer
     * sees prices before checkout. No-API (cached reference / configured default) — the exact, address-
     * specific price is computed at checkout. Re-renders with the cart totals (WooCommerce refreshes them).
     */
    public function cart_estimate(): void {
        if (get_option('bgc_cart_estimate_enabled', 'no') !== 'yes') { return; }
        if (!function_exists('WC') || !WC()->cart) { return; }
        $labels = ['office' => __('office', 'bg-couriers'), 'address' => __('address', 'bg-couriers'), 'automat' => __('APS', 'bg-couriers')];
        $names  = BGC_Couriers::all();
        $rows   = [];
        foreach (BGC_Settings::courier_order() as $cid) {
            if (BGC_Settings::courier_config($cid) === null) { continue; } // enabled + configured only
            $parts = [];
            foreach (BGC_Settings::enabled_methods($cid) as $m) {
                $est = BGC_Pricing::estimate($cid, $m);
                if ($est === null) { continue; }
                $parts[] = esc_html($labels[$m] ?? $m) . ' ' . wp_kses_post(BGC_Currency::dual_store($est));
            }
            if ($parts) {
                $rows[] = '<div class="bgc-cart-est-row"><strong>' . esc_html($names[$cid] ?? ucfirst($cid)) . '</strong> — ' . implode(' · ', $parts) . '</div>';
            }
        }
        if (!$rows) { return; }
        echo '<div class="bgc-cart-estimate"><div class="bgc-cart-est-title">' . esc_html__('Estimated shipping (exact price at checkout)', 'bg-couriers') . '</div>' . implode('', $rows) . '</div>';
        echo '<style>.bgc-cart-estimate{margin-top:12px;padding:12px 14px;border:1px solid #e2e6ea;border-radius:10px;background:#fafbfc;font-size:.92em;}'
            . '.bgc-cart-est-title{font-weight:600;margin-bottom:6px;}.bgc-cart-est-row{margin:3px 0;color:#555;}</style>';
    }

    /** Amount still needed to reach the Speedy free-shipping threshold (0 if disabled or already met). */
    public static function free_remaining(float $subtotal, array $cfg): float {
        if (empty($cfg['enabled']) || (float) ($cfg['threshold'] ?? 0) <= 0) { return 0.0; }
        return max(0.0, (float) $cfg['threshold'] - $subtotal);
    }

    /** The progress notice HTML (always the .bgc-free-notice element so the fragment can swap it). */
    public static function free_notice_html(): string {
        $courier = self::chosen_courier();
        if (!$courier) { return '<div class="bgc-free-notice"></div>'; } // no bgc courier chosen
        $cfg = BGC_Settings::free_shipping($courier);
        $subtotal = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->get_subtotal() : 0.0;
        if (empty($cfg['enabled']) || (float) ($cfg['threshold'] ?? 0) <= 0) {
            return '<div class="bgc-free-notice"></div>';
        }
        $couriers = BGC_Couriers::all();
        $label = isset($couriers[$courier]) ? $couriers[$courier] : ucfirst($courier);
        $remaining = self::free_remaining($subtotal, $cfg);
        if ($remaining <= 0) {
            /* translators: %s is the courier name. */
            $msg = sprintf(esc_html__('You have free %s delivery! 🎉', 'bg-couriers'), esc_html($label));
        } else {
            /* translators: 1: a formatted price, 2: the courier name. */
            $msg = sprintf(esc_html__('Add %1$s more for free %2$s delivery', 'bg-couriers'), BGC_Currency::dual_store($remaining), esc_html($label));
        }
        return '<div class="bgc-free-notice woocommerce-info" style="margin-bottom:1em;">' . $msg . '</div>';
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
        // When the country field is hidden (BG-only store), pin it to BG so the hidden field still submits.
        if (get_option('bgc_hide_country', 'no') === 'yes') {
            foreach (['billing', 'shipping'] as $g) {
                if (isset($fields[$g][$g . '_country'])) { $fields[$g][$g . '_country']['default'] = 'BG'; }
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

    /** Order the courier shipping rates at checkout by the configured courier order (General settings). */
    public function sort_rates($rates) {
        if (!is_array($rates) || count($rates) < 2) { return $rates; }
        $pos = array_flip(BGC_Settings::courier_order());
        $key = static function ($r) use ($pos) {
            $mid = (is_object($r) && method_exists($r, 'get_method_id')) ? (string) $r->get_method_id() : '';
            return strpos($mid, 'bgc_') === 0 ? ($pos[substr($mid, 4)] ?? 900) : 1000; // non-bgc rates keep to the end
        };
        uasort($rates, static function ($a, $b) use ($key) { return $key($a) <=> $key($b); });
        return $rates;
    }

    /** The courier id of the chosen bgc_<id> shipping method, or null. */
    public static function chosen_courier(): ?string {
        $chosen = (function_exists('WC') && WC()->session) ? (array) WC()->session->get('chosen_shipping_methods') : [];
        foreach ($chosen as $m) {
            if (preg_match('/^bgc_([a-z0-9]+)/', (string) $m, $mm)) { return $mm[1]; }
        }
        return null;
    }

    public function chosen_is_speedy(): bool {
        return $this->chosen_courier() !== null;
    }

    public function validate($data, $errors): void {
        $courier = $this->chosen_courier();
        if (!$courier) { return; } // a non-bgc shipping method — not ours to validate
        $names = BGC_Couriers::all();
        $label = $names[$courier] ?? ucfirst($courier);
        $s = WC()->session;
        // The saved selection must belong to the courier actually chosen — switching couriers voids the old pick.
        if ((string) $s->get('bgc_selection_courier', '') !== $courier) {
            $errors->add('bgc', sprintf(__('Please choose your %s delivery point before placing the order.', 'bg-couriers'), $label));
            return;
        }
        // BoxNow — a locker picked on the map widget (no city).
        if ($courier === 'boxnow') {
            if ((int) $s->get('bgc_office_id', 0) <= 0) {
                $errors->add('bgc', __('Please choose a BOX NOW locker before placing the order.', 'bg-couriers'));
            }
            return;
        }
        // City/office couriers (Speedy, Econt, Pigeon).
        $method = (string) $s->get('bgc_method', '');
        if ((int) $s->get('bgc_site_id', 0) <= 0) {
            $errors->add('bgc', sprintf(__('Please choose a city for %s delivery.', 'bg-couriers'), $label));
        }
        if ($method === 'address') {
            $street = (string) $s->get('bgc_addr_street_name', '');
            $no     = (string) $s->get('bgc_addr_street_no', '');
            if ($street === '' || $no === '') {
                $errors->add('bgc', sprintf(__('Please enter a street and number for %s address delivery.', 'bg-couriers'), $label));
            }
        } elseif ((int) $s->get('bgc_office_id', 0) <= 0) {
            $errors->add('bgc', sprintf(__('Please choose an office/APS for %s.', 'bg-couriers'), $label));
        }
    }

    public function persist(\WC_Order $order): void {
        $courier = self::chosen_courier(); if (!$courier) { return; }
        $s = WC()->session; if (!$s) { return; }
        $order->update_meta_data('_bgc_courier', $courier);
        $order->update_meta_data('_bgc_method', (string) ($s->get('bgc_method', '') ?: (BGC_Settings::enabled_methods($courier)[0] ?? 'office')));
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
        $city   = (int) $s->get('bgc_site_id', 0) ? BGC_Nomenclature::city_by_id($courier, (int) $s->get('bgc_site_id', 0)) : null;
        $name   = (string) ($city['name'] ?? '');
        $post   = (string) $s->get('bgc_post_code', '') ?: (string) ($city['post_code'] ?? '');
        $region = (string) ($city['region'] ?? '');
        if ($method === 'address') {
            $line1 = trim((string) $s->get('bgc_addr_street_name', '') . ' ' . (string) $s->get('bgc_addr_street_no', ''));
            $line2 = trim((string) $s->get('bgc_addr_complex', ''));
        } else {
            $o = (int) $s->get('bgc_office_id', 0) ? BGC_Nomenclature::office_by_id($courier, (int) $s->get('bgc_office_id', 0)) : null;
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
        // Leaflet (bundled locally — no CDN, WP.org-safe) powers the office/APS map picker.
        wp_enqueue_style('bgc-leaflet', BGC_URL . 'assets/vendor/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('bgc-leaflet', BGC_URL . 'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('bgc-checkout', BGC_URL . 'assets/css/bgc-checkout.css', ['bgc-leaflet'], is_file($css) ? (string) filemtime($css) : BGC_VERSION);
        wp_enqueue_script('bgc-checkout', BGC_URL . 'assets/js/bgc-checkout.js', ['jquery', 'selectWoo', 'bgc-leaflet'], is_file($js) ? (string) filemtime($js) : BGC_VERSION, true);
        wp_localize_script('bgc-checkout', 'BGC', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bgc_checkout'),
            'currency' => get_woocommerce_currency(),
            'leaflet_images' => BGC_URL . 'assets/vendor/leaflet/images/', // bundled Leaflet marker icons
            'emergency' => BGC_Settings::emergency(),
            'boxnow' => [
                'widget'    => 'https://map.boxnow.bg/iframe.html', // BoxNow map widget (has built-in GPS)
                'partnerId' => (string) get_option('bgc_boxnow_partner_id', ''),
                'country'   => 'bg',
                'gps'       => 'yes',
            ],
            'i18n'  => [
                'address'=>__('To address','bg-couriers'),'office'=>__('To office','bg-couriers'),'automat'=>__('To APS','bg-couriers'),
                'office_label'=>__('Office','bg-couriers'),'automat_label'=>__('APS (locker)','bg-couriers'),
                'emerg_default'=>__('Having trouble placing your order? We can help — call us:','bg-couriers'),
                'close'=>__('Close','bg-couriers'),
                'city_ph' => __('Type a city…','bg-couriers'),'office_ph'=>__('Search…','bg-couriers'),'street_ph'=>__('Type a street…','bg-couriers'),
                'na_city' => __('Not available in this city','bg-couriers'),
                'office_need_city' => __('Select a city first','bg-couriers'),
                'boxnow_pick' => __('Choose a BOX NOW locker','bg-couriers'),
                'boxnow_change' => __('Change locker','bg-couriers'),
                'map_open' => __('View on map','bg-couriers'),
                'map_title' => __('Pick from the map','bg-couriers'),
                'map_choose' => __('Choose this location','bg-couriers'),
                'map_locate' => __('Show my location','bg-couriers'),
                'map_none' => __('No offices with a map location for this city yet — use the list.','bg-couriers'),
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
        // Hide the Country/Region field (BG-only store) when enabled in settings.
        if (get_option('bgc_hide_country', 'no') === 'yes') {
            wp_add_inline_style('bgc-checkout', '#billing_country_field,#shipping_country_field{display:none !important;}');
        }
    }
    public function render_fields($method, $index): void {
        if (strpos((string) $method->get_method_id(), 'bgc_') !== 0) { return; }
        // The interactive pickers belong to checkout (their JS/CSS only load there). On the cart page keep
        // the rate row clean — the customer picks the destination at checkout (the cart shows the estimate).
        if (function_exists('is_cart') && is_cart()) { return; }
        $courier = substr((string) $method->get_method_id(), 4); // 'bgc_speedy' -> 'speedy'
        if (!BGC_Couriers::get($courier)) { return; }
        if ($courier === 'boxnow') { $this->render_boxnow_fields(WC()->session); return; } // locker chosen on the map widget
        // Stateful: re-render the session selection so update_checkout recalcs don't wipe the fields.
        // Only render a selection that was made for THIS courier — switching couriers must not show a
        // stale city/office from another courier (whose ids are invalid here).
        $s = WC()->session;
        $mine = $s && (string) $s->get('bgc_selection_courier', '') === $courier;
        $sel_method = $mine ? (string) $s->get('bgc_method', '') : '';
        $site_id    = $mine ? (int) $s->get('bgc_site_id', 0) : 0;
        $office_id  = $mine ? (int) $s->get('bgc_office_id', 0) : 0;
        $post_code  = $mine ? (string) $s->get('bgc_post_code', '') : '';

        $city_option = '';
        if ($site_id) {
            $city = BGC_Nomenclature::city_by_id($courier, $site_id);
            if ($city) {
                $city_option = '<option value="' . esc_attr($site_id) . '" selected>' . esc_html($city['name']) . '</option>';
            }
        }
        $office_option = '';
        if ($office_id) {
            $office = BGC_Nomenclature::office_by_id($courier, $office_id);
            if ($office) {
                $office_option = '<option value="' . esc_attr($office_id) . '" selected>' . esc_html($office['name'] . ' — ' . $office['address']) . '</option>';
            }
        }
        // Office/automat picker shows for office+automat methods, hides for address.
        $office_style = ($sel_method === 'address') ? ' style="display:none;"' : '';

        $av = function ($k) use ($s, $mine) { return $mine ? esc_attr((string) $s->get('bgc_addr_' . $k, '')) : ''; };
        $sn = $mine ? (string) $s->get('bgc_addr_street_name', '') : '';
        $street_option = $sn !== '' ? '<option value="' . esc_attr($sn) . '" selected>' . esc_html($sn) . '</option>' : '';
        $addr_style = ($sel_method === 'address') ? '' : ' style="display:none;"';

        $office_label = ($sel_method === 'automat')
            ? esc_html__('APS (locker)', 'bg-couriers')
            : esc_html__('Office', 'bg-couriers');

        echo '<div class="bgc-fields" data-courier="' . esc_attr($courier) . '" data-method="' . esc_attr($sel_method) . '"'
           . ' data-methods="' . esc_attr(implode(',', BGC_Settings::enabled_methods($courier))) . '"'
           . ' data-order="' . esc_attr(implode(',', BGC_Settings::method_order($courier))) . '">'
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
           . '<div class="bgc-office-pick"><select class="bgc-office">' . $office_option . '</select>'
           . '<button type="button" class="button bgc-map-btn" title="' . esc_attr__('View on map', 'bg-couriers') . '"><span class="bgc-map-pin">📍</span> ' . esc_html__('Map', 'bg-couriers') . '</button></div></div>'
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

    /** BOX NOW checkout: a locker chosen on the BoxNow map widget (no city/office dropdowns). */
    private function render_boxnow_fields($s): void {
        // Only treat the saved locker as ours if the selection was actually made for BoxNow — otherwise a
        // stale office id from a previously-chosen courier would render an empty "selected locker" box.
        $mine   = $s && (string) $s->get('bgc_selection_courier', '') === 'boxnow';
        $locker = $mine ? (int) $s->get('bgc_office_id', 0) : 0;
        $name   = $mine ? (string) $s->get('bgc_boxnow_name', '') : '';
        $addr   = $mine ? (string) $s->get('bgc_boxnow_addr', '') : '';
        $has    = $locker > 0;
        echo '<div class="bgc-fields bgc-boxnow" data-courier="boxnow" data-method="automat" data-methods="automat" data-order="automat">'
           . '<div class="bgc-panel">'
           . '<button type="button" class="button bgc-boxnow-pick">' . esc_html__('Choose a BOX NOW locker', 'bg-couriers') . '</button>'
           . '<div class="bgc-boxnow-selected"' . ($has ? '' : ' style="display:none;"') . '>'
           . '<strong class="bgc-boxnow-name">' . esc_html($name) . '</strong>'
           . '<span class="bgc-boxnow-addr"> ' . esc_html($addr) . '</span>'
           . '</div>'
           . '<input type="hidden" class="bgc-boxnow-id" value="' . esc_attr($has ? (string) $locker : '') . '">'
           . '</div></div>';
    }
}
