<?php
defined('ABSPATH') || exit;

class BGCouriers_Checkout {
    /** Shipping-method id prefix; the courier id is what follows it in a rate id like "bgcouriers_speedy:12". */
    const METHOD_PREFIX = 'bgcouriers_';
    private const PREFIX_LEN = 11;

    /** @var array<int,string> What WooCommerce itself called each shipping package, before any other plugin had a say. */
    private $wc_package_names = [];

    public function __construct() {
        add_action('woocommerce_after_shipping_rate', [$this, 'render_fields'], 10, 2);
        add_action('woocommerce_review_order_before_shipping', [$this, 'render_allmap_button']);
        add_action('woocommerce_after_checkout_billing_form', [$this, 'render_delivery_host'], 5);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 10, 1);
        // persist() runs before WooCommerce adds the shipping items, so the shipping line is named here.
        add_action('woocommerce_checkout_create_order_shipping_item', [$this, 'name_shipping_item'], 10, 4);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'package_hash']);
        add_filter('woocommerce_package_rates', [$this, 'sort_rates'], 20);
        // The pair must bracket every other listener on this filter, hence the extreme priorities.
        add_filter('woocommerce_shipping_package_name', [$this, 'capture_package_name'], PHP_INT_MIN, 3);
        add_filter('woocommerce_shipping_package_name', [$this, 'package_name'], PHP_INT_MAX, 3);
        add_action('woocommerce_after_cart_totals', [$this, 'cart_estimate']);
        add_filter('pre_option_woocommerce_enable_shipping_calc', [$this, 'hide_calculator_option']);
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'info_price_label'], 4, 2);
        add_filter('woocommerce_get_order_item_totals', [$this, 'order_shipping_row'], 10, 2);
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'logo_shipping_label'], 5, 2);
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'info_tip_label'], 30, 2);
        add_filter('woocommerce_checkout_fields', [$this, 'simplify_fields']);
        add_action('woocommerce_before_checkout_form', [$this, 'render_free_notice'], 5);
        add_filter('woocommerce_update_order_review_fragments', [$this, 'free_notice_fragment']);
        add_filter('woocommerce_shipping_chosen_method', [$this, 'default_courier'], 10, 3);
        add_filter('woocommerce_available_payment_gateways', [$this, 'cod_filter_gateways']);
        add_filter('woocommerce_package_rates', [$this, 'ppp_filter_rates'], 25, 2);
        add_filter('woocommerce_no_shipping_available_html', [$this, 'no_shipping_reason']);
        add_filter('woocommerce_cart_no_shipping_available_html', [$this, 'no_shipping_reason_cart']);
        add_filter('woocommerce_no_available_payment_methods_message', [$this, 'no_payment_reason']);
        add_action('template_redirect', [$this, 'reset_country']);
        add_filter('rocket_delay_js_exclusions', [$this, 'no_delayed_js']);
        add_filter('perfmatters_delay_js_exclusions', [$this, 'no_delayed_js']);
    }

    /** Say why a foreign address has no delivery options, and offer the way back home. */
    public function no_shipping_reason($html, $cart = false) {
        if (!function_exists('WC') || !class_exists('BGCouriers_Pricing')) { return $html; }
        $country = BGCouriers_Pricing::destination_country();
        if (!BGCouriers_Settings::is_intl($country)) { return $html; }
        $names = WC()->countries ? (array) WC()->countries->get_countries() : [];
        $there = (string) ($names[$country] ?? $country);
        if (BGCouriers_Settings::cod_fiscalization() === 'ppp' && !BGCouriers_Settings::has_prepaid_gateway()) {
            /* translators: %s: country name */
            $msg = sprintf(__('Delivery to %s can only be paid in advance, and this shop has no prepaid payment method at the moment.', 'bg-couriers'), $there);
        } else {
            /* translators: %s: country name */
            $msg = sprintf(__('No courier can deliver to %s at the moment.', 'bg-couriers'), $there);
        }
        return '<span class="bgc-no-shipping">' . esc_html($msg) . ' ' . $this->home_link($cart) . '</span>';
    }

    /** The link back to the home country, built off a named page: inside the AJAX refresh the current URL is /?wc-ajax=... */
    private function home_link(bool $cart = false): string {
        $home  = BGCouriers_Settings::home_country();
        $names = WC()->countries ? (array) WC()->countries->get_countries() : [];
        /* translators: %s: the shop's own country */
        $back = sprintf(__('Deliver in %s instead', 'bg-couriers'), (string) ($names[$home] ?? $home));
        $base = $cart ? wc_get_cart_url() : wc_get_checkout_url();
        $url  = add_query_arg('bgcouriers_home', wp_create_nonce('bgcouriers_home'), $base);
        return '<a href="' . esc_url($url) . '">' . esc_html($back) . '</a>';
    }

    /** Say why the payment box is empty when this plugin emptied it: PPP abroad, or a courier that collects no cash at this kind. */
    public function no_payment_reason($msg) {
        if (!function_exists('WC') || !class_exists('BGCouriers_Pricing')) { return $msg; }
        $chosen = self::chosen_bgc_courier();
        if ($chosen !== '') {
            $kind = self::chosen_method($chosen);
            if (in_array($kind, BGCouriers_Settings::no_cod_methods($chosen), true)) {
                $names = BGCouriers_Couriers::all();
                return '<span class="bgc-no-payment">' . esc_html(sprintf(
                    /* translators: 1: courier name, 2: the delivery option, e.g. "an APS (locker)" */
                    __('%1$s does not collect cash on delivery to %2$s, and this shop offers no other way to pay at the moment. Choose another delivery option above.', 'bg-couriers'),
                    (string) ($names[$chosen] ?? $chosen), self::kind_label($kind))) . '</span>';
            }
        }
        if (BGCouriers_Settings::cod_fiscalization() !== 'ppp') { return $msg; }
        $country = BGCouriers_Pricing::destination_country();
        if (!BGCouriers_Settings::is_intl($country)) { return $msg; }
        $names = WC()->countries ? (array) WC()->countries->get_countries() : [];
        /* translators: %s: country name */
        $there = sprintf(__('No payment method is available for delivery to %s.', 'bg-couriers'),
            (string) ($names[$country] ?? $country));
        return '<span class="bgc-no-payment">' . esc_html($there) . ' ' . $this->home_link() . '</span>';
    }

    public function no_shipping_reason_cart($html) {
        return $this->no_shipping_reason($html, true);
    }

    /** The way back out of a country the shop cannot deliver to: country, town and office are all reset. */
    public function reset_country(): void {
        if (!isset($_GET['bgcouriers_home']) || !function_exists('WC') || !WC()->session) { return; }
        $nonce = sanitize_text_field(wp_unslash($_GET['bgcouriers_home']));
        if (!wp_verify_nonce($nonce, 'bgcouriers_home')) { return; }
        $home = BGCouriers_Settings::home_country();
        WC()->session->set('bgcouriers_country', $home);
        WC()->session->set('bgcouriers_site_id', 0);
        WC()->session->set('bgcouriers_office_id', 0);
        WC()->session->set('bgcouriers_post_code', '');
        WC()->session->set('bgcouriers_sel_by_courier', []);
        if (WC()->customer) {
            WC()->customer->set_shipping_country($home);
            // Billing only when the plugin owns the address fields; otherwise it is the customer's own.
            if (BGCouriers_Settings::own_address_fields()) { WC()->customer->set_billing_country($home); }
            WC()->customer->save();
        }
        wp_safe_redirect(remove_query_arg('bgcouriers_home'));
        exit;
    }

    /** Take cash on delivery off the checkout when the delivery cannot carry it: PPP abroad, or a kind the courier collects no cash at. */
    public function cod_filter_gateways($gateways) {
        if (!is_array($gateways)) { return $gateways; }
        $country = BGCouriers_Pricing::destination_country();
        $courier = self::chosen_bgc_courier();
        // Abroad the destination alone decides: with every rate refused there may be no chosen courier to ask.
        $strip = (BGCouriers_Settings::cod_fiscalization() === 'ppp' && BGCouriers_Settings::is_intl($country))
            || ($courier !== '' && !BGCouriers_Settings::cod_allowed_for($courier, self::chosen_method($courier)));
        if ($strip) {
            foreach ($gateways as $gid => $gw) {
                if (BGCouriers_Settings::is_cod_gateway((string) $gid, $gw)) { unset($gateways[$gid]); }
            }
        }
        return $gateways;
    }

    /** Under PPP with no prepaid gateway, a courier whose PPP cannot reach the destination is unusable, so its rates go. */
    public function ppp_filter_rates($rates, $package) {
        if (BGCouriers_Settings::cod_fiscalization() !== 'ppp' || BGCouriers_Settings::has_prepaid_gateway()) { return $rates; }
        $country = BGCouriers_Pricing::destination_country($package);
        foreach ($rates as $id => $rate) {
            $courier = self::courier_from_rate_id((string) $id);
            if ($courier !== '' && !BGCouriers_Settings::ppp_payout_reaches($courier, $country)) { unset($rates[$id]); }
        }
        return $rates;
    }

    /** The delivery kind chosen for THIS courier; the bare session key is one value shared by every courier. */
    private static function chosen_method(string $courier): string {
        return (string) (BGCouriers_Pricing::selection_for($courier)['method'] ?? '');
    }

    private static function cod_gateway_ids(): array {
        $wc  = function_exists('WC') ? WC() : null;
        $out = [];
        if (!$wc || !$wc->payment_gateways()) { return $out; }
        foreach ($wc->payment_gateways()->payment_gateways() as $gid => $gw) {
            if (is_object($gw) && $gw->enabled === 'yes' && BGCouriers_Settings::is_cod_gateway((string) $gid, $gw)) {
                $out[] = (string) $gid;
            }
        }
        return $out;
    }

    /** "an office" / "an address" / "an APS (locker)" - a delivery kind as it reads inside a sentence. */
    private static function kind_label(string $m): string {
        $k = [
            'office'  => __('an office', 'bg-couriers'),
            'address' => __('an address', 'bg-couriers'),
            'automat' => __('an APS (locker)', 'bg-couriers'),
        ];
        return $k[$m] ?? $m;
    }

    /** Delivery kinds this courier collects no cash at, only on a shop that offers cash on delivery at all. */
    private static function nocod_for_ui(string $courier): array {
        $list = BGCouriers_Settings::no_cod_methods($courier);
        if (!$list) { return []; }
        $wc = function_exists('WC') ? WC() : null;
        if (!$wc || !$wc->payment_gateways()) { return []; }
        foreach ($wc->payment_gateways()->payment_gateways() as $gid => $gw) {
            if (is_object($gw) && $gw->enabled === 'yes' && BGCouriers_Settings::is_cod_gateway((string) $gid, $gw)) { return $list; }
        }
        return [];
    }

    private static function chosen_bgc_courier(): string {
        $chosen = (function_exists('WC') && WC()->session) ? (array) WC()->session->get('chosen_shipping_methods') : [];
        foreach ($chosen as $m) {
            $c = self::courier_from_rate_id((string) $m);
            if ($c !== '') { return $c; }
        }
        return '';
    }

    private static function courier_from_rate_id(string $id): string {
        if (strpos($id, self::METHOD_PREFIX) !== 0) { return ''; }
        $mid = explode(':', $id)[0];
        return substr($mid, self::PREFIX_LEN);
    }

    public function default_courier($default, $rates, $chosen_method) {
        if (!empty($chosen_method)) { return $default; }
        $pref = (string) get_option('bgcouriers_default_courier', '');
        if ($pref !== '') {
            foreach ((array) $rates as $id => $rate) {
                if (is_object($rate) && method_exists($rate, 'get_method_id') && $rate->get_method_id() === 'bgcouriers_' . $pref) { return $id; }
            }
        }
        return $default;
    }

    private function rate_tip($rate): string {
        $meta = method_exists($rate, 'get_meta_data') ? (array) $rate->get_meta_data() : [];
        if ((float) ($meta['_bgcouriers_info_price'] ?? 0) > 0) {
            return __('Paid to the courier on delivery.', 'bg-couriers');
        }
        $courier_id = substr((string) $rate->get_method_id(), self::PREFIX_LEN);
        $c = BGCouriers_Couriers::get($courier_id);
        if (!$c) { return ''; }
        // On the cart nothing is chosen yet; at checkout the price is already final.
        if (!function_exists('is_cart') || !is_cart()) {
            return __('Delivery is included in the order total.', 'bg-couriers');
        }
        $m = (string) ($meta['bgcouriers_method'] ?? (BGCouriers_Settings::enabled_methods($courier_id)[0] ?? 'office'));
        $types = [
            'office'  => __('to an office', 'bg-couriers'),
            'address' => __('to your address', 'bg-couriers'),
            'automat' => __('to an APS (locker)', 'bg-couriers'),
        ];
        $type = $types[$m] ?? $types['office'];
        if (in_array('live_quote', $c->capabilities(), true)) {
            /* translators: %s = delivery type, e.g. "to an office" */
            return sprintf(__('≈ %s - final price at checkout.', 'bg-couriers'), $type);
        }
        /* translators: %s = delivery type */
        return sprintf(__('Flat price %s.', 'bg-couriers'), $type);
    }

    /** With "Delivery in the order total" off the shipping row costs 0 and WooCommerce prints no amount, so the door price is written under it. */
    public function order_shipping_row($totals, $order) {
        if (!is_array($totals) || !isset($totals['shipping']) || !$order instanceof \WC_Order) { return $totals; }
        $courier = (string) $order->get_meta('_bgcouriers_courier');
        if ($courier === '' || BGCouriers_Settings::ship_in_total($courier)) { return $totals; }
        $info = 0.0;
        foreach ($order->get_items('shipping') as $item) {
            if ($item instanceof \WC_Order_Item_Shipping && strpos((string) $item->get_method_id(), 'bgcouriers_') === 0) {
                $info = max($info, (float) $item->get_meta('_bgcouriers_info_price', true));
            }
        }
        if ($info <= 0) { return $totals; }
        $price = html_entity_decode(wp_strip_all_tags(wc_price($info, ['currency' => $order->get_currency()])), ENT_QUOTES, 'UTF-8');
        /* translators: %s: the delivery price, e.g. "3,06 EUR" - the same sentence the checkout row uses. */
        $note  = sprintf(__('~%s paid to the courier on delivery', 'bg-couriers'), $price);
        $totals['shipping']['value'] .= '<br><small class="bgc-door-price">' . esc_html($note) . '</small>';
        return $totals;
    }

    /** "Delivery in the order total" off: replace the 0-cost label with the estimate paid to the courier. */
    public function info_price_label($label, $method) {
        if (!is_object($method) || !method_exists($method, 'get_meta_data')) { return $label; }
        $meta = (array) $method->get_meta_data();
        $info = (float) ($meta['_bgcouriers_info_price'] ?? 0);
        if ($info <= 0) { return $label; }
        return $method->get_label() . ': ~' . wc_price($info);
    }

    public function info_tip_label($label, $method) {
        if (!is_object($method) || !method_exists($method, 'get_method_id')) { return $label; }
        if (strpos((string) $method->get_method_id(), self::METHOD_PREFIX) !== 0) { return $label; }
        $tip = $this->rate_tip($method);
        if ($tip === '') { return $label; }
        // A CSS background, not inline SVG: rate labels go through wp_kses_post, which strips <svg>.
        $meta = method_exists($method, 'get_meta_data') ? (array) $method->get_meta_data() : [];
        $mode = (float) ($meta['_bgcouriers_info_price'] ?? 0) > 0 ? 'courier' : 'total';
        return $label . ' <span class="bgc-info-tip bgc-pay-' . esc_attr($mode) . '" tabindex="0" role="img"'
            . ' data-tip="' . esc_attr($tip) . '" aria-label="' . esc_attr($tip) . '"></span>';
    }

    public function render_free_notice(): void { echo wp_kses_post(self::free_notice_html()); }
    public function free_notice_fragment($fragments) { $fragments['.bgc-free-notice'] = self::free_notice_html(); return $fragments; }

    /** @return array<string,true> Couriers with a rate in this checkout, which is not the same as the enabled ones. */
    private static function offered_couriers(): array {
        $out = [];
        if (!function_exists('WC') || !WC()->shipping()) { return $out; }
        foreach ((array) WC()->shipping()->get_packages() as $package) {
            foreach ((array) ($package['rates'] ?? []) as $rate_id => $rate) {
                $cid = self::courier_from_rate_id((string) $rate_id);
                if ($cid !== '') { $out[$cid] = true; }
            }
        }
        return $out;
    }

    public function render_allmap_button(): void {
        if (get_option('bgcouriers_allmap', 'yes') !== 'yes') { return; }
        if (!self::has_pickup_courier(array_keys(self::offered_couriers()))) { return; }
        if (function_exists('is_cart') && is_cart()) { return; }
        $marks = '';
        $offered = self::offered_couriers();
        foreach (BGCouriers_Settings::courier_order() as $cid) {
            if (!isset($offered[$cid])) { continue; }
            $methods = BGCouriers_Settings::enabled_methods($cid);
            if (!in_array('office', $methods, true) && !in_array('automat', $methods, true)) { continue; }
            $logo = BGCouriers_Couriers::logo_url($cid);
            if ($logo === '') { continue; }
            $marks .= '<img class="bgc-allmap-mark" src="' . esc_url($logo) . '" alt="">';
        }
        // A <tr>: inside the order-review table a <div> is foster-parented out of it by the HTML parser.
        echo wp_kses(
            '<tr class="bgc-allmap-open"><td colspan="2">' . self::allmap_button_html($marks) . '</td></tr>',
            [
                'tr'     => ['class' => true],
                'td'     => ['class' => true, 'colspan' => true],
                'button' => ['type' => true, 'class' => true],
                'span'   => ['class' => true, 'aria-hidden' => true],
                'img'    => ['class' => true, 'src' => true, 'alt' => true],
            ]
        );
    }

    public static function allmap_button_html(string $marks = ''): string {
        return '<button type="button" class="bgc-allmap-btn">'
            . '<span class="bgc-allmap-marks">' . $marks . '</span>'
            . esc_html__('Interactive map', 'bg-couriers')
            . '</button>';
    }

    /** The same button for the block checkout, which is not a table; '' when there is nothing to map. */
    public static function allmap_button_for_blocks(): string {
        if (get_option('bgcouriers_allmap', 'yes') !== 'yes') { return ''; }
        $offered = self::offered_couriers();
        if (!self::has_pickup_courier(array_keys($offered))) { return ''; }
        $marks = '';
        foreach (BGCouriers_Settings::courier_order() as $cid) {
            if (!isset($offered[$cid])) { continue; }
            $methods = BGCouriers_Settings::enabled_methods($cid);
            if (!in_array('office', $methods, true) && !in_array('automat', $methods, true)) { continue; }
            $logo = BGCouriers_Couriers::logo_url($cid);
            if ($logo === '') { continue; }
            $marks .= '<img class="bgc-allmap-mark" src="' . esc_url($logo) . '" alt="">';
        }
        return '<div class="bgc-allmap-open">' . self::allmap_button_html($marks) . '</div>';
    }

    public function logo_shipping_label($label, $method) {
        if (!is_object($method) || !method_exists($method, 'get_method_id')) { return $label; }
        $mid = (string) $method->get_method_id();
        if (strpos($mid, 'bgcouriers_') !== 0) { return $label; }
        $url = BGCouriers_Couriers::logo_url(substr($mid, self::PREFIX_LEN));
        if ($url === '') { return $label; }
        return '<img class="bgc-ship-logo" src="' . esc_url($url) . '" alt="" aria-hidden="true" width="16" height="16"> ' . $label;
    }

    /** Shipping estimate per courier on the cart page, from cached reference or configured prices. */
    public function cart_estimate(): void {
        if (get_option('bgcouriers_cart_estimate_enabled', 'no') !== 'yes') { return; }
        if (!function_exists('WC') || !WC()->cart) { return; }
        $labels = ['office' => __('office', 'bg-couriers'), 'address' => __('address', 'bg-couriers'), 'automat' => __('APS', 'bg-couriers')];
        $names  = BGCouriers_Couriers::all();
        $rows   = [];
        foreach (BGCouriers_Settings::courier_order() as $cid) {
            if (!BGCouriers_Settings::courier_offerable($cid)) { continue; }
            $parts = [];
            foreach (BGCouriers_Settings::enabled_methods($cid) as $m) {
                $est = BGCouriers_Pricing::estimate($cid, $m);
                if ($est === null) { continue; }
                $parts[] = esc_html($labels[$m] ?? $m) . ' ' . wp_kses_post(wc_price($est));
            }
            if ($parts) {
                $rows[] = '<div class="bgc-cart-est-row"><strong>' . esc_html($names[$cid] ?? ucfirst($cid)) . '</strong> - ' . implode(' · ', $parts) . '</div>';
            }
        }
        if (!$rows) { return; }
        echo wp_kses_post('<div class="bgc-cart-estimate"><div class="bgc-cart-est-title">'
            . esc_html__('Estimated shipping (exact price at checkout)', 'bg-couriers') . '</div>'
            . implode('', $rows) . '</div>');
    }

    public static function free_remaining(float $subtotal, array $cfg): float {
        if (empty($cfg['enabled']) || (float) ($cfg['threshold'] ?? 0) <= 0) { return 0.0; }
        return max(0.0, (float) $cfg['threshold'] - $subtotal);
    }

    /** The progress notice HTML (always the .bgc-free-notice element so the fragment can swap it). */
    public static function free_notice_html(): string {
        $courier = self::chosen_courier();
        if (!$courier) { return '<div class="bgc-free-notice"></div>'; }
        if (!BGCouriers_Settings::ship_in_total($courier)) { return '<div class="bgc-free-notice"></div>'; }
        $sel = BGCouriers_Pricing::selection_for($courier);
        $cfg = BGCouriers_Settings::free_shipping($courier, (string) ($sel['method'] ?? ''));
        $subtotal = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->get_subtotal() : 0.0;
        if (empty($cfg['enabled']) || (float) ($cfg['threshold'] ?? 0) <= 0) {
            return '<div class="bgc-free-notice"></div>';
        }
        $couriers = BGCouriers_Couriers::all();
        $label = isset($couriers[$courier]) ? $couriers[$courier] : ucfirst($courier);
        $remaining = self::free_remaining($subtotal, $cfg);
        if ($remaining <= 0) {
            /* translators: %s is the courier name. */
            $msg = sprintf(esc_html__('You have free %s delivery! 🎉', 'bg-couriers'), esc_html($label));
        } else {
            /* translators: 1: a formatted price, 2: the courier name. */
            $msg = sprintf(esc_html__('Add %1$s more for free %2$s delivery', 'bg-couriers'), wc_price($remaining), esc_html($label));
        }
        return '<div class="bgc-free-notice woocommerce-info" style="margin-bottom:1em;">' . $msg . '</div>';
    }

    /** Drop WooCommerce's own address fields; the plugin's delivery fields are the address. */
    public function simplify_fields($fields) {
        if (!BGCouriers_Settings::own_address_fields()) { return $fields; }
        foreach (['billing', 'shipping'] as $g) {
            foreach (['address_1', 'address_2', 'city', 'state', 'postcode'] as $f) {
                unset($fields[$g][$g . '_' . $f]);
            }
        }
        // A courier label needs a phone; the e-mail is optional unless the shop asks for it.
        if (isset($fields['billing']['billing_phone'])) { $fields['billing']['billing_phone']['required'] = true; }
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['required'] = BGCouriers_Settings::require_email();
        }
        // A hidden country field must still submit the country the delivery box is quoting for.
        if (get_option('bgcouriers_hide_country', 'no') === 'yes') {
            $country = BGCouriers_Pricing::destination_country();
            foreach (['billing', 'shipping'] as $g) {
                if (isset($fields[$g][$g . '_country'])) { $fields[$g][$g . '_country']['default'] = $country; }
            }
        }
        return $fields;
    }

    /** @return string[] Courier ids whose street box may only offer what they list. */
    private static function street_list_only_couriers(): array {
        $out = [];
        foreach (array_keys(BGCouriers_Couriers::all()) as $id) {
            $co = BGCouriers_Couriers::get($id);
            if ($co && method_exists($co, 'street_list_only') && $co->street_list_only()) { $out[] = $id; }
        }
        return $out;
    }

    /** Short-circuit WooCommerce's cart shipping calculator option to 'no'; $pre unchanged means do not short-circuit. */
    public function hide_calculator_option($pre = false) {
        return BGCouriers_Settings::hide_shipping_calculator() ? 'no' : $pre;
    }

    /** First pass over the package heading, before every other listener: note what WooCommerce produced. */
    public function capture_package_name($name, $index = 0, $package = []) {
        $this->wc_package_names[(int) $index] = (string) $name;
        return $name;
    }

    /** Last pass: drop the heading when it is still WooCommerce's own and the cart is one package. */
    public function package_name($name, $index = 0, $package = []) {
        if (($this->wc_package_names[(int) $index] ?? null) !== (string) $name) { return $name; }
        return $this->single_package() ? '' : $name;
    }

    private function single_package(): bool {
        if (!function_exists('WC') || !WC() || !WC()->shipping()) { return true; }
        return count((array) WC()->shipping()->get_packages()) <= 1;
    }

    public function package_hash($packages) {
        $s = WC()->session;
        if (!$s) { return $packages; }
        // The payment method is in the key too: cash on delivery changes the price, and WooCommerce caches rates by this hash.
        $key = (string) $s->get('bgcouriers_method', '') . ':' . (int) $s->get('bgcouriers_site_id', 0)
             . ':' . (int) $s->get('bgcouriers_office_id', 0)
             . ':' . (string) $s->get('chosen_payment_method', '');
        foreach ($packages as $i => $pkg) { $packages[$i]['bgcouriers_selection'] = $key; }
        return $packages;
    }

    public function sort_rates($rates) {
        if (!is_array($rates) || count($rates) < 2) { return $rates; }
        $pos = array_flip(BGCouriers_Settings::courier_order());
        $key = static function ($r) use ($pos) {
            $mid = (is_object($r) && method_exists($r, 'get_method_id')) ? (string) $r->get_method_id() : '';
            return strpos($mid, self::METHOD_PREFIX) === 0 ? ($pos[substr($mid, self::PREFIX_LEN)] ?? 900) : 1000;
        };
        uasort($rates, static function ($a, $b) use ($key) { return $key($a) <=> $key($b); });
        return $rates;
    }

    public static function chosen_courier(): ?string {
        $chosen = (function_exists('WC') && WC()->session) ? (array) WC()->session->get('chosen_shipping_methods') : [];
        foreach ($chosen as $m) {
            if (preg_match('/^bgcouriers_([a-z0-9]+)/', (string) $m, $mm)) { return $mm[1]; }
        }
        return null;
    }

    public function chosen_is_speedy(): bool {
        return $this->chosen_courier() !== null;
    }

    public static function has_pickup_courier(?array $courier_ids = null): bool {
        $ids = $courier_ids ?? array_keys(BGCouriers_Couriers::all());
        foreach ($ids as $cid) {
            if (get_option('bgcouriers_' . $cid . '_enabled', 'no') !== 'yes') { continue; }
            $methods = BGCouriers_Settings::enabled_methods($cid);
            if (in_array('office', $methods, true) || in_array('automat', $methods, true)) { return true; }
        }
        return false;
    }

    public function validate($data, $errors): void {
        $courier = $this->chosen_courier();
        if (!$courier) { return; }
        $names = BGCouriers_Couriers::all();
        $label = $names[$courier] ?? ucfirst($courier);
        // Domestic numbers must match the Bulgarian format; abroad only that a courier will accept the number.
        $phone   = isset($data['billing_phone']) ? (string) $data['billing_phone'] : '';
        $country = BGCouriers_Pricing::destination_country();
        if ($phone !== '' && !BGCouriers_Settings::is_intl($country)) {
            $digits = preg_replace('/[\s\-()]/', '', $phone);
            if (!preg_match('/^(\+?359|0)\d{8,9}$/', (string) $digits)) {
                $errors->add('bgc', __('Please enter a valid Bulgarian phone number (e.g. 0888 123 456) so the courier can reach the recipient.', 'bg-couriers'));
            }
        } elseif ($phone !== '' && !BGCouriers_Phone::usable($phone, BGCouriers_Phone::cc_for($country))) {
            $errors->add('bgc', __('Please enter a valid phone number so the courier can reach the recipient.', 'bg-couriers'));
        }
        // The block checkout does not require the phone itself; skip when WooCommerce already refused an empty one.
        if (trim($phone) === '' && !in_array('billing_phone_required', (array) $errors->get_error_codes(), true)) {
            $errors->add('bgc', __('Please enter a phone number so the courier can reach the recipient.', 'bg-couriers'));
        }
        // Belt to cod_filter_gateways' braces: a form rendered before the locker was picked, or a Store API request.
        $kind = self::chosen_method($courier);
        $pay  = isset($data['payment_method']) ? (string) $data['payment_method']
              : (string) (WC()->session ? WC()->session->get('chosen_payment_method', '') : '');
        if ($pay === 'cod' && in_array($kind, BGCouriers_Settings::no_cod_methods($courier), true)) {
            $errors->add('bgc', sprintf(
                /* translators: 1: courier name, 2: the delivery option, e.g. "an APS (locker)" */
                __('%1$s does not collect cash on delivery to %2$s. Choose another delivery option, or pay in advance.', 'bg-couriers'),
                $label, self::kind_label($kind)));
        }
        $s = WC()->session;
        // Each refusal carries its field's id and its own error code, so WooCommerce links the notice to the field.
        $at = function (string $field) use ($courier): array { return ['id' => self::field_id($courier, $field)]; };
        if ((string) $s->get('bgcouriers_selection_courier', '') !== $courier) {
            /* translators: %s: courier name */
            $errors->add('bgc_point', sprintf(__('Please choose your %s delivery point before placing the order.', 'bg-couriers'), $label),
                $at('city'));
            return;
        }
        $method = (string) $s->get('bgcouriers_method', '');
        $site   = (int) $s->get('bgcouriers_site_id', 0);
        $office = (int) $s->get('bgcouriers_office_id', 0);
        if ($site <= 0) {
            /* translators: %s: courier name */
            $errors->add('bgc_city', sprintf(__('Please choose a city for %s delivery.', 'bg-couriers'), $label), $at('city'));
        } elseif (!self::city_is_listed($courier, $site)) {
            /* translators: %s: courier name */
            $errors->add('bgc_city', sprintf(__('That city is not on %s list any more. Please choose it again.', 'bg-couriers'), $label), $at('city'));
        }
        if ($method === 'address') {
            $street = (string) $s->get('bgcouriers_addr_street_name', '');
            $no     = (string) $s->get('bgcouriers_addr_street_no', '');
            if ($street === '' || $no === '') {
                /* translators: %s: courier name */
                $errors->add('bgc_street', sprintf(__('Please enter a street and number for %s address delivery.', 'bg-couriers'), $label),
                    $at($street === '' ? 'street' : 'streetno'));
            }
        } elseif ($office > 0 && !self::office_is_listed($courier, $site, $office)) {
            /* translators: %s: courier name */
            $errors->add('bgc_office', sprintf(__('That office is not on %s list any more. Please choose it again.', 'bg-couriers'), $label), $at('office'));
        } elseif ($office <= 0) {
            /* translators: %s: courier name */
            $errors->add('bgc_office', sprintf(__('Please choose an office/APS for %s.', 'bg-couriers'), $label), $at('office'));
        }
    }

    /** The id of one field's box in a courier's block, shared by the markup and the refusals so they cannot drift apart. */
    public static function field_id(string $courier, string $field): string {
        return 'bgcouriers-' . sanitize_html_class($field) . '-' . sanitize_html_class($courier);
    }

    /** A courier not yet synced gets a yes, so missing data is not the customer's dead end. */
    private static function city_is_listed(string $courier, int $city_id): bool {
        if (!class_exists('BGCouriers_Nomenclature') || BGCouriers_Nomenclature::count($courier) <= 0) { return true; }
        return BGCouriers_Nomenclature::city_by_id($courier, $city_id) !== null;
    }

    private static function office_is_listed(string $courier, int $city_id, int $office_id): bool {
        if (!class_exists('BGCouriers_Nomenclature')) { return true; }
        $known = BGCouriers_Nomenclature::offices($courier, $city_id);
        if (!$known) { return true; }
        foreach ($known as $o) {
            if ((int) ($o['office_id'] ?? 0) === $office_id) { return true; }
        }
        return false;
    }

    /** The shipping line's name with the delivery type ("Speedy: До офис"), composed from the base name so re-saves do not stack it. */
    public static function shipping_item_name(string $base, string $method): string {
        $base  = trim($base);
        $label = BGCouriers_Icons::method_label($method);
        // method_label() returns the id itself for an unknown method; do not print that.
        if ($base === '' || $label === '' || $label === $method) { return $base; }
        return $base . ': ' . $label;
    }

    /** Name one shipping line, keeping the base name in meta rather than re-deriving it from a translated string. */
    public static function label_shipping_item(\WC_Order_Item_Shipping $item, string $method): void {
        $base = (string) $item->get_meta('_bgcouriers_base_name', true);
        if ($base === '') {
            $base = (string) $item->get_name();
            $item->update_meta_data('_bgcouriers_base_name', $base);
        }
        $item->set_name(self::shipping_item_name($base, $method));
    }

    public function name_shipping_item($item, $package_key = 0, $package = [], $order = null): void {
        if (!$item instanceof \WC_Order_Item_Shipping) { return; }
        $id = (string) $item->get_method_id();
        if (strpos($id, 'bgcouriers_') !== 0) { return; }
        $courier = substr($id, self::PREFIX_LEN);
        $s = WC()->session;
        // BoxNow is locker-only; a stale session method must not name it otherwise.
        $method = $courier === 'boxnow'
            ? 'automat'
            : (string) ($s ? $s->get('bgcouriers_method', '') : '');
        if ($method === '') { $method = BGCouriers_Settings::enabled_methods($courier)[0] ?? ''; }
        if ($method === '') { return; }
        self::label_shipping_item($item, $method);
    }

    public function persist(\WC_Order $order): void {
        $courier = self::chosen_courier(); if (!$courier) { return; }
        $s = WC()->session; if (!$s) { return; }
        self::apply_delivery($order, [
            'courier'      => $courier,
            'country'      => BGCouriers_Pricing::destination_country(),
            'method'       => (string) $s->get('bgcouriers_method', ''),
            'site_id'      => (int) $s->get('bgcouriers_site_id', 0),
            'office_id'    => (int) $s->get('bgcouriers_office_id', 0),
            'post_code'    => (string) $s->get('bgcouriers_post_code', ''),
            // THIS courier's own quote, not whichever one WooCommerce priced last.
            'quote_price'  => (float) $s->get('bgcouriers_quote_price_' . $courier, 0),
            'quote_source' => (string) $s->get('bgcouriers_quote_source_' . $courier, ''),
            'street_name'  => (string) $s->get('bgcouriers_addr_street_name', ''),
            'street_id'    => (int) $s->get('bgcouriers_addr_street_id', 0),
            'street_type'  => (string) $s->get('bgcouriers_addr_street_type', ''),
            'street_no'    => (string) $s->get('bgcouriers_addr_street_no', ''),
            'complex'      => (string) $s->get('bgcouriers_addr_complex', ''),
            'block'        => (string) $s->get('bgcouriers_addr_block', ''),
            'entrance'     => (string) $s->get('bgcouriers_addr_entrance', ''),
            'floor'        => (string) $s->get('bgcouriers_addr_floor', ''),
            'apartment'    => (string) $s->get('bgcouriers_addr_apartment', ''),
            'address_note' => (string) $s->get('bgcouriers_addr_address_note', ''),
        ]);
    }

    /** Write a delivery selection onto an order: the _bgcouriers_* meta plus the WC address; shared by checkout and admin editor. */
    public static function apply_delivery(\WC_Order $order, array $d): void {
        $courier = (string) ($d['courier'] ?? '');
        if ($courier === '') { return; }
        $g = static function ($k, $def = '') use ($d) { return $d[$k] ?? $def; };
        // BoxNow is locker-only; force 'automat' so a stale method can't leak on.
        $method = $courier === 'boxnow'
            ? 'automat'
            : ((string) $g('method') ?: (BGCouriers_Settings::enabled_methods($courier)[0] ?? 'office'));

        // Not defaulted to the shop's country: the admin editor posts none, and defaulting would move a foreign order home.
        $country = strtoupper(trim((string) $g('country')));
        if ($country === '') { $country = BGCouriers_Settings::order_country($order); }

        $order->update_meta_data('_bgcouriers_courier', $courier);
        $order->update_meta_data('_bgcouriers_country', $country);
        $order->update_meta_data('_bgcouriers_method', $method);
        $order->update_meta_data('_bgcouriers_site_id', (int) $g('site_id', 0));
        $order->update_meta_data('_bgcouriers_office_id', (int) $g('office_id', 0));
        $order->update_meta_data('_bgcouriers_post_code', (string) $g('post_code'));
        $order->update_meta_data('_bgcouriers_street_name', (string) $g('street_name'));
        // Neither key is part of label_fingerprint(); adding one would change it for every order at once.
        $order->update_meta_data('_bgcouriers_street_id',   (int) $g('street_id', 0));
        $order->update_meta_data('_bgcouriers_street_type', (string) $g('street_type'));
        $order->update_meta_data('_bgcouriers_street_no',   (string) $g('street_no'));
        $order->update_meta_data('_bgcouriers_complex',     (string) $g('complex'));
        $order->update_meta_data('_bgcouriers_block',       (string) $g('block'));
        $order->update_meta_data('_bgcouriers_entrance',    (string) $g('entrance'));
        $order->update_meta_data('_bgcouriers_floor',       (string) $g('floor'));
        $order->update_meta_data('_bgcouriers_apartment',   (string) $g('apartment'));
        $order->update_meta_data('_bgcouriers_address_note',(string) $g('address_note'));
        if (array_key_exists('quote_price', $d))  { $order->update_meta_data('_bgcouriers_quote_price', (float) $d['quote_price']); }
        if (array_key_exists('quote_source', $d)) { $order->update_meta_data('_bgcouriers_quote_source', (string) $d['quote_source']); }

        $city   = (int) $g('site_id', 0) ? BGCouriers_Nomenclature::city_by_id($courier, (int) $g('site_id', 0)) : null;
        $name   = (string) ($city['name'] ?? '');
        $post   = (string) $g('post_code') ?: (string) ($city['post_code'] ?? '');
        $region = (string) ($city['region'] ?? '');
        if ($method === 'address') {
            // With its type: "ВИТОША 10" is either of two Sofia streets, "ул. ВИТОША 10" is one.
            $line1 = trim(trim((string) $g('street_type') . ' ' . (string) $g('street_name')) . ' ' . (string) $g('street_no'));
            $line2 = trim((string) $g('complex'));
        } else {
            $o = (int) $g('office_id', 0) ? BGCouriers_Nomenclature::office_by_id($courier, (int) $g('office_id', 0)) : null;
            $line1 = (string) ($o['name'] ?? '');
            $line2 = (string) ($o['address'] ?? '');
        }
        foreach (['billing', 'shipping'] as $grp) {
            $order->{"set_{$grp}_country"}($country);
            $order->{"set_{$grp}_city"}($name);
            $order->{"set_{$grp}_state"}($region);
            $order->{"set_{$grp}_postcode"}($post);
            $order->{"set_{$grp}_address_1"}($line1);
            $order->{"set_{$grp}_address_2"}($line2);
        }
        $order->set_shipping_first_name($order->get_billing_first_name());
        $order->set_shipping_last_name($order->get_billing_last_name());

        // The shipping line, when the order already has one (admin editor, block checkout); the classic checkout names it in name_shipping_item().
        foreach ($order->get_items('shipping') as $item) {
            if ($item instanceof \WC_Order_Item_Shipping && strpos((string) $item->get_method_id(), 'bgcouriers_') === 0) {
                self::label_shipping_item($item, $method);
            }
        }
    }
    public function assets(): void {
        $on_cart     = function_exists('is_cart') && is_cart();
        // is_checkout() is false for a checkout block dropped on another page.
        $on_checkout = (function_exists('is_checkout') && is_checkout()) || BGCouriers_Blocks::is_block_checkout();
        if (!$on_cart && !$on_checkout) { return; }
        $rates_css = BGCOURIERS_PATH . 'assets/css/bgc-rates.css';
        wp_enqueue_style('bgc-rates', BGCOURIERS_URL . 'assets/css/bgc-rates.css', [], is_file($rates_css) ? (string) filemtime($rates_css) : BGCOURIERS_VERSION);
        BGCouriers_Tips::enqueue();
        if ($on_cart) {
            $cart_css = BGCOURIERS_PATH . 'assets/css/bgc-cart.css';
            wp_enqueue_style('bgc-cart', BGCOURIERS_URL . 'assets/css/bgc-cart.css', ['bgc-rates'], is_file($cart_css) ? (string) filemtime($cart_css) : BGCOURIERS_VERSION);
            // Themes may render the calculator from their own template regardless of the option.
            if (BGCouriers_Settings::hide_shipping_calculator()) {
                wp_add_inline_style('bgc-cart', '.woocommerce-shipping-calculator{display:none!important;}');
            }
        }
        if (!$on_checkout) { return; }
        wp_enqueue_style('select2');
        $css = BGCOURIERS_PATH . 'assets/css/bgc-checkout.css';
        $js  = BGCOURIERS_PATH . 'assets/js/bgc-checkout.js';
        wp_enqueue_style('bgc-leaflet', BGCOURIERS_URL . 'assets/lib/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('bgc-leaflet', BGCOURIERS_URL . 'assets/lib/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('bgc-checkout', BGCOURIERS_URL . 'assets/css/bgc-checkout.css', ['bgc-leaflet', 'bgc-rates'], is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
        wp_enqueue_script('bgc-checkout', BGCOURIERS_URL . 'assets/js/bgc-checkout.js', ['jquery', 'selectWoo', 'bgc-leaflet'], is_file($js) ? (string) filemtime($js) : BGCOURIERS_VERSION, true);
        if (self::has_pickup_courier()) {
            $allmap_css = BGCOURIERS_PATH . 'assets/css/bgc-allmap.css';
            $allmap_js  = BGCOURIERS_PATH . 'assets/js/bgc-allmap.js';
            wp_enqueue_style('bgc-allmap', BGCOURIERS_URL . 'assets/css/bgc-allmap.css', ['bgc-checkout'], is_file($allmap_css) ? (string) filemtime($allmap_css) : BGCOURIERS_VERSION);
            wp_enqueue_script('bgc-allmap', BGCOURIERS_URL . 'assets/js/bgc-allmap.js', ['bgc-checkout'], is_file($allmap_js) ? (string) filemtime($allmap_js) : BGCOURIERS_VERSION, true);
        }
        $preload = get_option('bgcouriers_preload_cities', 'yes') === 'yes';
        $city_index = [];
        if ($preload) {
            foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
                if (BGCouriers_Settings::courier_config($cid)) {
                    $city_index[$cid] = BGCouriers_Nomenclature::city_index($cid, BGCouriers_Pricing::destination_country());
                }
            }
        }
        wp_localize_script('bgc-checkout', 'BGCOURIERS', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bgcouriers_checkout'),
            'currency' => get_woocommerce_currency(),
            'preloadCities' => $preload,
            'homeCountry' => BGCouriers_Settings::home_country(),
            'cityIndexCountry' => BGCouriers_Pricing::destination_country(),
            // 'yes'/'no', not a boolean: wp_localize_script() casts false to '', indistinguishable from a missing key.
            'allmapNearest' => get_option('bgcouriers_allmap_nearest', 'yes') === 'yes' ? 'yes' : 'no',
            'cityIndex' => $city_index,
            'streetListOnly' => self::street_list_only_couriers(),
            // The server removes the COD gateway, but WooCommerce's payment fragment is not always replaced on the page.
            'codGateways' => self::cod_gateway_ids(),
            'addressMap' => get_option('bgcouriers_address_map', 'no') === 'yes',
            'deliveryPosition' => BGCouriers_Settings::delivery_position(),
            // The Google Maps key is deliberately not sent to the browser; it is used server-side only.
            'leaflet_images' => BGCOURIERS_URL . 'assets/lib/leaflet/images/',
            'icons' => BGCouriers_Icons::map(),
            'emergency' => BGCouriers_Settings::emergency(),
            'i18n'  => [
                'address'=>__('To address','bg-couriers'),'office'=>__('To office','bg-couriers'),'automat'=>__('To APS','bg-couriers'),
                'office_label'=>__('Office','bg-couriers'),'automat_label'=>__('APS (locker)','bg-couriers'),
                'emerg_default'=>__('Having trouble placing your order? We can help - call us:','bg-couriers'),
                'close'=>__('Close','bg-couriers'),
                'city_ph' => __('Type a city…','bg-couriers'),'office_ph'=>__('Search…','bg-couriers'),'street_ph'=>__('Type a street…','bg-couriers'),
                /* translators: 1: courier name, 2: the street as the map found it. */
                'street_unlisted' => __('%1$s does not list a street called "%2$s" here. Pick yours from the list.', 'bg-couriers'),
                'na_city' => __('Not available in this city','bg-couriers'),
                'office_need_city' => __('Select a city first','bg-couriers'),
                // select2's own messages, which ship in English only.
                /* translators: %d is how many characters the search needs before it will look. */
                's2_short' => __('Type at least %d characters', 'bg-couriers'),
                's2_none' => __('Nothing found', 'bg-couriers'),
                's2_searching' => __('Searching…', 'bg-couriers'),
                's2_more' => __('Loading more…', 'bg-couriers'),
                's2_error' => __('The results could not be loaded', 'bg-couriers'),
                'map_open' => __('View on map','bg-couriers'),
                'map_title' => __('Pick from the map','bg-couriers'),
                'map_choose' => __('Choose this location','bg-couriers'),
                'map_locate' => __('Show my location','bg-couriers'),
                'map_none' => __('No offices with a map location for this city yet - use the list.','bg-couriers'),
                'addr_map_title' => __('Choose your address on the map','bg-couriers'),
                'addr_map_hint' => __('Click the map or drag the pin to your address.','bg-couriers'),
                'addr_use' => __('Use this address','bg-couriers'),
                'addr_none' => __('No address found here - try another spot.','bg-couriers'),
                'allmap_title' => __('Interactive map', 'bg-couriers'),
                'allmap_show' => __('Show the offices', 'bg-couriers'),
                'allmap_na' => __('Not available for this order', 'bg-couriers'),
                'allmap_full' => __('Full', 'bg-couriers'),
                /* translators: %d: how many pickup points one bubble on the map stands for */
                'allmap_cluster' => __('%d pickup points here - zoom in to tell them apart', 'bg-couriers'),
                'allmap_choose' => __('Choose', 'bg-couriers'),
                'allmap_city_ph' => __('Choose a city', 'bg-couriers'),
                'allmap_map' => __('Map', 'bg-couriers'),
                'allmap_list' => __('List', 'bg-couriers'),
                'allmap_loading' => __('Loading the pickup points…', 'bg-couriers'),
                'allmap_directions' => __('Directions', 'bg-couriers'),
                'clear' => __('Clear', 'bg-couriers'),
                'ans_near' => __('Closest', 'bg-couriers'),
                'ans_cheap' => __('Cheapest', 'bg-couriers'),
                'ans_both' => __('Closest and cheapest', 'bg-couriers'),
                /* translators: %s: the lowest price of delivery to the customer's address, e.g. "2,93 €" */
                'ans_addr' => __('To your address: from %s', 'bg-couriers'),
                'near_save' => __('you save', 'bg-couriers'),
                'near_straight' => __('as the crow flies', 'bg-couriers'),
                'near_find' => __('Find the closest office', 'bg-couriers'),
                'near_drag' => __('Drag the pin or click the map', 'bg-couriers'),
                'near_m' => __('m', 'bg-couriers'),
                'near_km' => __('km', 'bg-couriers'),
                'near_which' => __('Show this point on the map', 'bg-couriers'),
                'near_from_you' => __('from you', 'bg-couriers'),
            ],
        ]);

        // Merchant-entered selectors go through hidden_field_selectors(), which validates each one.
        $selectors = BGCouriers_Settings::hidden_field_selectors();
        if ($selectors !== '') {
            wp_add_inline_style('bgc-checkout', $selectors . '{display:none !important;}');
        }
        if (get_option('bgcouriers_hide_country', 'no') === 'yes') {
            wp_add_inline_style('bgc-checkout', '#billing_country_field,#shipping_country_field{display:none !important;}');
        }
    }
    /** Keep the checkout's own scripts, and the jQuery/selectWoo they depend on, out of "delay JavaScript until interaction". */
    public function no_delayed_js($excluded) {
        if (!is_array($excluded)) { return $excluded; }
        $on_checkout = (function_exists('is_checkout') && is_checkout())
            || (class_exists('BGCouriers_Blocks') && BGCouriers_Blocks::is_block_checkout());
        if (!$on_checkout) { return $excluded; }
        return array_merge($excluded, [
            'bg-couriers/assets/js/',
            'js/jquery/jquery.min.js',
            'js/jquery/jquery.js',
            'selectWoo',
            'select2',
        ]);
    }

    public function render_delivery_host(): void {
        if (BGCouriers_Settings::delivery_position() !== 'details') { return; }
        if (function_exists('is_checkout') && !is_checkout()) { return; }
        echo '<div class="bgc-delivery-host" id="bgcouriers-delivery-host"></div>';
        // Hidden by script, so with scripts off nothing is hidden; the timer lives here because bgc-checkout.js may never load.
        echo '<script>(function(d){var h=d.documentElement;h.className+=\' bgc-relocating\';'
            . 'setTimeout(function(){var n=d.getElementById(\'bgcouriers-delivery-host\');'
            . 'if(!n||!n.children.length){h.className=h.className.replace(/(^|\\s)bgc-relocating(\\s|$)/,\' \');}'
            . '},3000);})(document);</script>';
    }

    public function render_fields($method, $index): void {
        if (strpos((string) $method->get_method_id(), 'bgcouriers_') !== 0) { return; }
        if (function_exists('is_cart') && is_cart()) { return; }
        $courier = substr((string) $method->get_method_id(), self::PREFIX_LEN);
        if (!BGCouriers_Couriers::get($courier)) { return; }
        // Only a selection made for THIS courier; another courier's ids are invalid here.
        $s = WC()->session;
        $mine = $s && (string) $s->get('bgcouriers_selection_courier', '') === $courier;
        $sel_method = $mine ? (string) $s->get('bgcouriers_method', '') : '';
        $site_id    = $mine ? (int) $s->get('bgcouriers_site_id', 0) : 0;
        $office_id  = $mine ? (int) $s->get('bgcouriers_office_id', 0) : 0;
        $post_code  = $mine ? (string) $s->get('bgcouriers_post_code', '') : '';
        // The customer has been in this courier before: show what they chose, which the price row is also quoted from.
        if (!$mine && $s) {
            $remembered = (array) $s->get('bgcouriers_sel_by_courier', []);
            $r = isset($remembered[$courier]) && is_array($remembered[$courier]) ? $remembered[$courier] : null;
            if ($r && in_array((string) ($r['method'] ?? ''), BGCouriers_Settings::enabled_methods($courier), true)) {
                $sel_method = (string) $r['method'];
                if ((int) ($r['site_id'] ?? 0) > 0) {
                    $site_id   = (int) $r['site_id'];
                    $office_id = (int) ($r['office_id'] ?? 0);
                }
            }
        }
        // Carry the city across a courier switch by postcode; office ids are courier-specific, so not the office.
        if (!$mine && $s) {
            $carry_pc = (string) $s->get('bgcouriers_post_code', '');
            $country  = BGCouriers_Pricing::destination_country();
            $carry_city = $carry_pc !== '' ? BGCouriers_Nomenclature::city_by_postcode($courier, $carry_pc, $country) : null;
            // By code first, then by name as the previous courier spells it (BOX NOW codes Sofia by its lowest locker).
            if (!$carry_city) {
                $from    = (string) $s->get('bgcouriers_selection_courier', '');
                $from_id = (int) $s->get('bgcouriers_site_id', 0);
                $from_c  = ($from !== '' && $from !== $courier && $from_id > 0) ? BGCouriers_Nomenclature::city_by_id($from, $from_id) : null;
                $name    = (string) ($from_c['name'] ?? '');
                if ($name !== '') { $carry_city = BGCouriers_Nomenclature::match_city($courier, $name, $carry_pc, $country); }
            }
            if ($carry_city) { $site_id = (int) $carry_city['city_id']; $post_code = $carry_pc !== '' ? $carry_pc : (string) ($carry_city['post_code'] ?? ''); }
        }

        $city_option = '';
        if ($site_id) {
            $city = BGCouriers_Nomenclature::city_by_id($courier, $site_id);
            if ($city) {
                $label = (string) $city['name'] . (!empty($city['post_code']) ? ' (' . $city['post_code'] . ')' : '');
                $city_option = '<option value="' . esc_attr($site_id) . '" selected>' . esc_html($label) . '</option>';
            }
        }
        $office_option = '';
        $auto_office = false;
        $off_country = BGCouriers_Pricing::destination_country();
        if (!in_array($off_country, BGCouriers_Settings::delivery_countries($courier), true)) { $off_country = BGCouriers_Settings::home_country(); }
        // An office without a town is half a selection; do not show it.
        if (!$site_id) { $office_id = 0; }
        if ($office_id) {
            $office = BGCouriers_Nomenclature::office_by_id($courier, $office_id);
            // The dropdown's live list can hold an office the synced table does not.
            if (!$office && $site_id) {
                foreach ((array) BGCouriers_Ajax::cached_city_offices($courier, $site_id, $off_country) as $row) {
                    if ((int) ($row['office_id'] ?? 0) === $office_id) { $office = $row; break; }
                }
            }
            if ($office) {
                $office_option = '<option value="' . esc_attr($office_id) . '" selected>' . esc_html($office['name'] . ' - ' . $office['address']) . '</option>';
            }
        } elseif ($site_id && $sel_method !== '' && $sel_method !== 'address') {
            // A single office or locker on the chosen tab is pre-selected here, server-side, so a re-render cannot wipe it.
            $warm = BGCouriers_Ajax::cached_city_offices($courier, $site_id, $off_country);
            $only = $warm === null
                ? BGCouriers_Nomenclature::offices($courier, $site_id, $sel_method)
                : array_values(array_filter($warm, static function ($o) use ($sel_method) { return ($o['type'] ?? '') === $sel_method; }));
            if (count($only) === 1) {
                $one = $only[0];
                $office_option = '<option value="' . esc_attr((string) $one['office_id']) . '" selected>'
                    . esc_html($one['name'] . ' - ' . $one['address']) . '</option>';
                $auto_office = true;
            }
        }
        $office_style = ($sel_method === 'address') ? ' style="display:none;"' : '';

        $av = function ($k) use ($s, $mine) { return $mine ? esc_attr((string) $s->get('bgcouriers_addr_' . $k, '')) : ''; };
        // The street box holds the bare name; its type and the courier's id ride in hidden fields, all restored from the session.
        $sn  = $mine ? (string) $s->get('bgcouriers_addr_street_name', '') : '';
        $st  = $mine ? (string) $s->get('bgcouriers_addr_street_type', '') : '';
        $sid = $mine ? (int) $s->get('bgcouriers_addr_street_id', 0) : 0;
        $street_option = $sn !== '' ? '<option value="' . esc_attr($sn) . '" selected>' . esc_html(trim($st . ' ' . $sn)) . '</option>' : '';
        $street_hidden = '<input type="hidden" class="bgc-street-id" value="' . esc_attr((string) $sid) . '"><input type="hidden" class="bgc-street-type" value="' . esc_attr($st) . '">';
        $addr_style = ($sel_method === 'address') ? '' : ' style="display:none;"';

        $office_label = ($sel_method === 'automat')
            ? esc_html__('APS (locker)', 'bg-couriers')
            : esc_html__('Office', 'bg-couriers');

        // One delivery country means no choice shown; the data attribute is rendered regardless.
        $countries = BGCouriers_Settings::delivery_countries($courier);
        $country   = BGCouriers_Pricing::destination_country();
        if (!in_array($country, $countries, true)) { $country = BGCouriers_Settings::home_country(); }
        $country_row = '';
        if (count($countries) > 1) {
            $names = (function_exists('WC') && WC()->countries) ? WC()->countries->get_countries() : [];
            $opts  = '';
            foreach ($countries as $c) {
                $opts .= '<option value="' . esc_attr($c) . '"' . ($c === $country ? ' selected' : '') . '>'
                    . esc_html((string) ($names[$c] ?? $c)) . '</option>';
            }
            $country_row = '<div class="bgc-field bgc-country-field"><label>' . esc_html__('Country', 'bg-couriers') . '</label>'
                . '<select class="bgc-country">' . $opts . '</select></div>';
        }

        // Only the chosen courier's block renders visible, so the page does not flash every courier's fields.
        $hide  = self::chosen_courier() !== $courier ? ' style="display:none;"' : '';
        $nocod = self::nocod_for_ui($courier);
        $html = '<div class="bgc-fields" data-courier="' . esc_attr($courier) . '" data-method="' . esc_attr($sel_method) . '"'
           . ' data-label="' . esc_attr((string) (BGCouriers_Couriers::all()[$courier] ?? $courier)) . '"'
           . ' data-methods="' . esc_attr(implode(',', BGCouriers_Settings::enabled_methods($courier))) . '"'
           . ' data-order="' . esc_attr(implode(',', BGCouriers_Settings::method_order($courier))) . '"'
           . ' data-country="' . esc_attr($country) . '"'
           . ' data-nocod="' . esc_attr(implode(',', $nocod)) . '"' . $hide . '>'
           . '<div class="bgc-loader" aria-hidden="true"><span class="bgc-spinner"></span></div>'
           . '<div class="bgc-tabs" role="tablist"></div>'
           . ($nocod ? '<div class="bgc-nocod" style="display:none;">'
               . esc_html__('Cash on delivery is not available for this delivery option - choose another one, or pay in advance.', 'bg-couriers')
               . '</div>' : '')
           . '<div class="bgc-panel">'
           . $country_row
           . '<div class="bgc-field bgc-city-field" id="' . esc_attr(self::field_id($courier, 'city')) . '"><label>' . esc_html__('City', 'bg-couriers') . '</label>'
           . '<select class="bgc-city"><option value=""></option>' . $city_option . '</select>'
           . '<input type="hidden" class="bgc-postcode" value="' . esc_attr($post_code) . '"></div>'
           . '<div class="bgc-field bgc-office-row" id="' . esc_attr(self::field_id($courier, 'office')) . '"' . $office_style . '><label class="bgc-office-label">' . $office_label . '</label>'
           . '<div class="bgc-office-pick"><select class="bgc-office"' . ($auto_office ? ' data-auto="1"' : '') . '>' . $office_option . '</select>'
           . '<button type="button" class="bgc-map-btn" title="' . esc_attr__('View on map', 'bg-couriers') . '">'
           . '<svg class="bgc-map-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>'
           . '<span>' . esc_html__('Map', 'bg-couriers') . '</span></button></div></div>'
           . '<div class="bgc-address-rows"' . $addr_style . '>'
           . '<div class="bgc-grid' . (get_option('bgcouriers_address_map', 'no') === 'yes' ? ' bgc-grid-map' : '') . '">'
           . '<div class="bgc-field bgc-street-field" id="' . esc_attr(self::field_id($courier, 'street')) . '"><label>' . esc_html__('Street', 'bg-couriers') . ' *</label><select class="bgc-street"><option value=""></option>' . $street_option . '</select>' . $street_hidden . '</div>'
           . '<div class="bgc-field bgc-streetno-field" id="' . esc_attr(self::field_id($courier, 'streetno')) . '"><label>' . esc_html__('No.', 'bg-couriers') . ' *</label><input type="text" class="bgc-street-no" autocomplete="off" value="' . $av('street_no') . '"></div>'
           . (get_option('bgcouriers_address_map', 'no') === 'yes'
               ? '<div class="bgc-field bgc-addr-map-cell"><label aria-hidden="true">&nbsp;</label>'
                 . '<button type="button" class="bgc-map-btn bgc-addr-map-btn bgc-addr-map-icon" title="' . esc_attr__('Choose on map', 'bg-couriers') . '" aria-label="' . esc_attr__('Choose on map', 'bg-couriers') . '">'
                 . '<svg class="bgc-map-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>'
                 . '</button></div>'
               : '')
           . '</div>'
           . '<div class="bgc-street-note" style="display:none;"></div>'
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
        echo wp_kses($html, BGCouriers_Kses::checkout_fields());
    }

}
