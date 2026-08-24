<?php
defined('ABSPATH') || exit;

class BGCouriers_Checkout {
    /**
     * Shipping-method id prefix. Rate ids look like "bgcouriers_speedy:12", and the courier id is what
     * follows it. NEVER hardcode the offset: it used to be substr($id, 4) for the old "bgc_" prefix, and
     * when the prefix was renamed the number stayed behind - every rate then resolved to a courier that
     * does not exist, and the checkout lost all of its shipping methods.
     */
    const METHOD_PREFIX = 'bgcouriers_';
    /** Length of METHOD_PREFIX, for substr() offsets. */
    private const PREFIX_LEN = 11;

    /**
     * What WooCommerce itself called each shipping package, noted before any other plugin has had a say.
     *
     * Recognising WooCommerce's own heading used to mean asking the WooCommerce catalogue for it from
     * here, with its msgids kept in variables so they would not be extracted into this plugin's own
     * catalogue. WordPress.org's scanner rejects both halves of that, and rightly: a plugin translates
     * its own strings, not its neighbour's, and a translation call takes a literal. None of it is needed.
     * The heading arrives at the filter already rendered in the site's language, so the plugin notes what
     * arrived first and compares against that - which is also more honest, since it recognises the real
     * default whatever the locale, WooCommerce version or wording.
     *
     * @var array<int,string>
     */
    private $wc_package_names = [];

    public function __construct() {
        add_action('woocommerce_after_shipping_rate', [$this, 'render_fields'], 10, 2);
        add_action('woocommerce_review_order_before_shipping', [$this, 'render_allmap_button']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 10, 1);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'package_hash']);
        add_filter('woocommerce_package_rates', [$this, 'sort_rates'], 20);
        // Two ends of the same filter: the first note what WooCommerce itself called the package, the
        // second decides. The pair has to BRACKET every other listener - a plugin renaming the package
        // below our recorder would have its name recorded as WooCommerce's and then removed - so the
        // priorities are the extremes rather than a comfortable 1 and 999. See package_name() for why
        // nothing here translates anything.
        add_filter('woocommerce_shipping_package_name', [$this, 'capture_package_name'], PHP_INT_MIN, 3);
        add_filter('woocommerce_shipping_package_name', [$this, 'package_name'], PHP_INT_MAX, 3);
        add_action('woocommerce_after_cart_totals', [$this, 'cart_estimate']); // shipping estimate on the cart page
        // Hide WC's generic cart shipping calculator (Country/Region/City/Postcode): it prices a delivery
        // to a postcode, while every rate here is priced to the office, automat or street address the
        // customer picks at checkout. The calculator is gated by an OPTION rather than a filter, so it is
        // short-circuited; a CSS net (added inline in assets()) covers themes (e.g. Shoptimizer) that
        // render it from a custom template regardless. Both ask the setting, not the constructor: a
        // store that unticks it gets WooCommerce's calculator back on the very next page load.
        add_filter('pre_option_woocommerce_enable_shipping_calc', [$this, 'hide_calculator_option']);
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'info_price_label'], 4, 2);     // "delivery not in the total": estimate instead of a price
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'logo_shipping_label'], 5, 2);  // courier brand logo before the name
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'info_tip_label'], 30, 2);      // (i) hover hint LAST, so the row stays one short line
        // Our own delivery fields ARE the address, so WooCommerce's are dropped - unless the store also
        // ships some other way and needs them (see BGCouriers_Settings::own_address_fields()).
        add_filter('woocommerce_checkout_fields', [$this, 'simplify_fields']);
        // Free-shipping progress notice: render it in the checkout notice area + refresh it on every
        // recalculation via WC's fragment mechanism (server computes the remaining; no DOM parsing).
        add_action('woocommerce_before_checkout_form', [$this, 'render_free_notice'], 5);
        add_filter('woocommerce_update_order_review_fragments', [$this, 'free_notice_fragment']);
        add_filter('woocommerce_shipping_chosen_method', [$this, 'default_courier'], 10, 3);
        // COD fiscalisation (ППП) rules: gate the COD payment gateway / courier rates at runtime.
        add_filter('woocommerce_available_payment_gateways', [$this, 'ppp_filter_gateways']);
        add_filter('woocommerce_package_rates', [$this, 'ppp_filter_rates'], 25, 2);
        // A foreign address with nothing to choose from is a dead end unless it says why and offers the
        // way out - see no_shipping_reason() and reset_country().
        add_filter('woocommerce_no_shipping_available_html', [$this, 'no_shipping_reason']);
        add_filter('woocommerce_cart_no_shipping_available_html', [$this, 'no_shipping_reason_cart']);
        // ...and the payment box below it, which WooCommerce fills with its own "no available payment
        // methods" when this plugin is the one that took them away - see no_payment_reason().
        add_filter('woocommerce_no_available_payment_methods_message', [$this, 'no_payment_reason']);
        add_action('template_redirect', [$this, 'reset_country']);
    }

    /**
     * Say why a parcel to another country has no delivery options, and offer the way back.
     *
     * WooCommerce's own sentence there is "please ensure that your address has been entered correctly",
     * which is wrong twice over abroad: the address is fine, and there is nothing in it to correct. The
     * customer is also STUCK - the country is chosen inside the delivery box, the delivery box is drawn
     * against a shipping rate, and there are no rates - so without a way back the only escape from a
     * country the shop cannot deliver to is to clear the site's cookies.
     *
     * Only ever replaces the message for a foreign destination. Domestically WooCommerce's sentence is
     * the right one (the address really can be wrong) and is left exactly as it is.
     *
     * @param string $html
     * @param bool   $cart Whether the totals block being filtered is the cart page's.
     * @return string
     */
    public function no_shipping_reason($html, $cart = false) {
        if (!function_exists('WC') || !class_exists('BGCouriers_Pricing')) { return $html; }
        $country = BGCouriers_Pricing::destination_country();
        if (!BGCouriers_Settings::is_intl($country)) { return $html; }
        $names = WC()->countries ? (array) WC()->countries->get_countries() : [];
        $there = (string) ($names[$country] ?? $country);
        // The one cause the plugin can name with certainty: it removed the rates itself, because a shop
        // whose cash on delivery is legal only through the courier's ППП has no way to be paid abroad.
        if (BGCouriers_Settings::cod_fiscalization() === 'ppp' && !BGCouriers_Settings::has_prepaid_gateway()) {
            /* translators: %s: country name */
            $msg = sprintf(__('Delivery to %s can only be paid in advance, and this shop has no prepaid payment method at the moment.', 'bg-couriers'), $there);
        } else {
            /* translators: %s: country name */
            $msg = sprintf(__('No courier can deliver to %s at the moment.', 'bg-couriers'), $there);
        }
        return '<span class="bgc-no-shipping">' . esc_html($msg) . ' ' . $this->home_link($cart) . '</span>';
    }

    /**
     * The link that puts the delivery country back home, ready to print.
     *
     * Built off a NAMED page rather than the current URL: the totals block and the payment box are both
     * re-rendered inside WooCommerce's own AJAX refresh, where the request is /?wc-ajax=update_order_review
     * - and a link built off that takes the customer to a page whose entire body is "-1".
     *
     * @param bool $cart Whether the block being filtered is the cart page's.
     * @return string
     */
    private function home_link(bool $cart = false): string {
        $home  = BGCouriers_Settings::home_country();
        $names = WC()->countries ? (array) WC()->countries->get_countries() : [];
        /* translators: %s: the shop's own country */
        $back = sprintf(__('Deliver in %s instead', 'bg-couriers'), (string) ($names[$home] ?? $home));
        $base = $cart ? wc_get_cart_url() : wc_get_checkout_url();
        $url  = add_query_arg('bgcouriers_home', wp_create_nonce('bgcouriers_home'), $base);
        return '<a href="' . esc_url($url) . '">' . esc_html($back) . '</a>';
    }

    /**
     * Say why the payment box is empty, when this plugin is the reason it is.
     *
     * Abroad, a shop whose cash on delivery is receipted through the courier's ППП has no way at all to be
     * paid: no courier performs a ППП across the border, so the plugin removes cash on delivery - and on a
     * shop with no prepaid method that leaves nothing. WooCommerce then prints "Sorry, it seems that there
     * are no available payment methods", which says neither what happened nor how to get out of it, and on
     * a Bulgarian shop it is in English besides. The customer already read the reason above the payment
     * box; this replaces the stock sentence with the same way back, so the screen ends in an exit rather
     * than in a dead stop.
     *
     * Only ever for a foreign destination and only under ППП. Any other empty payment box belongs to the
     * shop, not to this plugin, and keeps WooCommerce's own wording.
     *
     * @param string $msg
     * @return string
     */
    public function no_payment_reason($msg) {
        if (!function_exists('WC') || !class_exists('BGCouriers_Pricing')) { return $msg; }
        if (BGCouriers_Settings::cod_fiscalization() !== 'ppp') { return $msg; }
        $country = BGCouriers_Pricing::destination_country();
        if (!BGCouriers_Settings::is_intl($country)) { return $msg; }
        $names = WC()->countries ? (array) WC()->countries->get_countries() : [];
        /* translators: %s: country name */
        $there = sprintf(__('No payment method is available for delivery to %s.', 'bg-couriers'),
            (string) ($names[$country] ?? $country));
        return '<span class="bgc-no-payment">' . esc_html($there) . ' ' . $this->home_link() . '</span>';
    }

    /**
     * The same message on the cart page, where the way back is the cart itself.
     *
     * @param string $html
     * @return string
     */
    public function no_shipping_reason_cart($html) {
        return $this->no_shipping_reason($html, true);
    }

    /**
     * The way back out of a country the shop cannot deliver to.
     *
     * The town and the office in the session were looked up in that country's own nomenclature, so they
     * are dropped with it - keeping them would quote the next courier against a city id that means a
     * different place at home. WooCommerce's own shipping country goes back too: it is what decides the
     * shipping zone, and a customer left in a zone with no methods is exactly the state being escaped.
     */
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
            // Billing only when the plugin owns the address fields - then the country box on screen is the
            // hidden one it pins itself, and leaving it abroad would fail validation with nothing visible
            // to correct. A shop that kept WooCommerce's own fields has a billing country the customer
            // typed, which is theirs and none of this function's business.
            if (BGCouriers_Settings::own_address_fields()) { WC()->customer->set_billing_country($home); }
            WC()->customer->save();
        }
        wp_safe_redirect(remove_query_arg('bgcouriers_home'));
        exit;
    }

    /**
     * When the merchant relies on the courier's ППП (has no cash register), a courier that does NOT offer ППП
     * cannot legally take cash-on-delivery. So while such a courier is the chosen shipping method, remove the
     * COD payment gateway - the order must be prepaid. Couriers that do ППП (or the cash-register mode) are
     * unaffected.
     *
     * The same is true of every courier the moment the parcel leaves the country: ППП is a Bulgarian postal
     * money transfer and the courier refuses it for a foreign address, so a shop whose cash-on-delivery is
     * legal only BECAUSE the courier does the ППП has no such arrangement abroad. Its international orders
     * are prepaid ones. Nothing changes for a shop that ships at home only, or one with a cash register.
     *
     * @param array $gateways id => WC_Payment_Gateway
     * @return array
     */
    public function ppp_filter_gateways($gateways) {
        if (!is_array($gateways) || BGCouriers_Settings::cod_fiscalization() !== 'ppp') { return $gateways; }
        $country = BGCouriers_Pricing::destination_country();
        $courier = self::chosen_bgc_courier();
        // Abroad the destination alone decides, with no courier asked. The ППП is a Bulgarian postal money
        // transfer and none of them performs one across the border, so there is nothing that could be
        // chosen to bring it back. Waiting for a chosen courier is what left cash on delivery on screen
        // beside a message saying the order could only be prepaid: every rate for the foreign address had
        // been refused, so there was no chosen courier to ask about.
        $strip = BGCouriers_Settings::is_intl($country)
            || ($courier !== '' && !BGCouriers_Settings::ppp_payout_reaches($courier, $country));
        if ($strip) {
            foreach ($gateways as $gid => $gw) {
                if (BGCouriers_Settings::is_cod_gateway((string) $gid, $gw)) { unset($gateways[$gid]); }
            }
        }
        return $gateways;
    }

    /**
     * If the merchant relies on ППП AND the shop offers NO prepaid gateway at all, a courier that can't do ППП
     * is unusable (COD only, no way to fiscalise) - so drop its shipping rates. When a prepaid gateway exists
     * the courier stays (usable for prepaid; COD is just hidden for it by ppp_filter_gateways above).
     *
     * A parcel leaving the country is the same case: no courier's ППП follows it, so such a shop cannot sell
     * abroad at all until it offers a prepaid way to pay. Which is why the settings screen says so beside the
     * countries themselves, rather than letting the merchant find out from an empty checkout.
     *
     * @param array $rates
     * @param array $package
     * @return array
     */
    public function ppp_filter_rates($rates, $package) {
        if (BGCouriers_Settings::cod_fiscalization() !== 'ppp' || BGCouriers_Settings::has_prepaid_gateway()) { return $rates; }
        $country = BGCouriers_Pricing::destination_country($package);
        foreach ($rates as $id => $rate) {
            $courier = self::courier_from_rate_id((string) $id);
            if ($courier !== '' && !BGCouriers_Settings::ppp_payout_reaches($courier, $country)) { unset($rates[$id]); }
        }
        return $rates;
    }

    /** The courier id of the customer's chosen BGCOURIERS shipping method this session, or '' if none. */
    private static function chosen_bgc_courier(): string {
        $chosen = (function_exists('WC') && WC()->session) ? (array) WC()->session->get('chosen_shipping_methods') : [];
        foreach ($chosen as $m) {
            $c = self::courier_from_rate_id((string) $m);
            if ($c !== '') { return $c; }
        }
        return '';
    }

    /** 'bgcouriers_econt:8' / 'bgcouriers_econt' -> 'econt'; '' for non-BGCOURIERS method ids. */
    private static function courier_from_rate_id(string $id): string {
        if (strpos($id, self::METHOD_PREFIX) !== 0) { return ''; }
        $mid = explode(':', $id)[0]; // strip the instance id
        return substr($mid, self::PREFIX_LEN);
    }

    /** Pre-select the configured default courier when the customer hasn't chosen a shipping method yet. */
    public function default_courier($default, $rates, $chosen_method) {
        if (!empty($chosen_method)) { return $default; } // respect an explicit choice
        $pref = (string) get_option('bgcouriers_default_courier', '');
        if ($pref !== '') {
            foreach ((array) $rates as $id => $rate) {
                if (is_object($rate) && method_exists($rate, 'get_method_id') && $rate->get_method_id() === 'bgcouriers_' . $pref) { return $id; }
            }
        }
        return $default;
    }

    /**
     * What the (i) at the end of a courier row says. Every courier gets one, so the rows read the same
     * way: the explanation is always in the same place instead of some couriers carrying an (i) and
     * others a full sentence on a line of their own.
     *
     * @param \WC_Shipping_Rate $rate The rate being rendered.
     * @return string Tip text, or '' for a rate that needs no explanation.
     */
    private function rate_tip($rate): string {
        $meta = method_exists($rate, 'get_meta_data') ? (array) $rate->get_meta_data() : [];
        // "Delivery in the order total" off: the row shows an estimate the courier collects at the door.
        if ((float) ($meta['_bgcouriers_info_price'] ?? 0) > 0) {
            return __('Paid to the courier on delivery.', 'bg-couriers');
        }
        $courier_id = substr((string) $rate->get_method_id(), self::PREFIX_LEN);
        $c = BGCouriers_Couriers::get($courier_id);
        if (!$c) { return ''; }
        // On the cart nothing has been chosen yet, so say which delivery type the price is quoted for
        // and whether it can still move. At checkout the choice is made and the price is already final.
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

    /**
     * "Delivery in the order total" off: the rate is 0 so WC would render the label alone (or "Free") -
     * replace it with the informational estimate the customer will pay the courier on delivery.
     * Runs BEFORE the logo (5) filter; rebuilding from get_label() drops whatever suffix WC appended
     * for the zero cost.
     */
    public function info_price_label($label, $method) {
        if (!is_object($method) || !method_exists($method, 'get_meta_data')) { return $label; }
        $meta = (array) $method->get_meta_data();
        $info = (float) ($meta['_bgcouriers_info_price'] ?? 0);
        if ($info <= 0) { return $label; }
        // Just "~price" here - the "paid to the courier on delivery" explanation lives in the (i)
        // hover tip appended by info_tip_label, so the rate row never wraps into an ugly mess.
        return $method->get_label() . ': ~' . wc_price($info);
    }

    /** A small (i) at the end of every courier rate label (checkout + cart); the explanation sits in its
     *  hover/focus tooltip instead of bloating the row text or taking a line of its own. */
    public function info_tip_label($label, $method) {
        if (!is_object($method) || !method_exists($method, 'get_method_id')) { return $label; }
        if (strpos((string) $method->get_method_id(), self::METHOD_PREFIX) !== 0) { return $label; }
        $tip = $this->rate_tip($method);
        if ($tip === '') { return $label; }
        // The icon SAYS which of the two it is instead of being a generic "i" the customer has to hover
        // to learn anything at all: a banknote when they will hand the money to the courier at the door,
        // a shopping bag when the delivery is already in what they are paying now. The drawing is a CSS
        // background rather than inline SVG on purpose - rate labels are printed through wp_kses_post,
        // which strips <svg> outright, while a class on a <span> survives (that is how this (i) lives).
        $meta = method_exists($method, 'get_meta_data') ? (array) $method->get_meta_data() : [];
        $mode = (float) ($meta['_bgcouriers_info_price'] ?? 0) > 0 ? 'courier' : 'total';
        return $label . ' <span class="bgc-info-tip bgc-pay-' . esc_attr($mode) . '" tabindex="0" role="img"'
            . ' data-tip="' . esc_attr($tip) . '" aria-label="' . esc_attr($tip) . '"></span>';
    }

    public function render_free_notice(): void { echo wp_kses_post(self::free_notice_html()); }
    public function free_notice_fragment($fragments) { $fragments['.bgc-free-notice'] = self::free_notice_html(); return $fragments; }

    /**
     * The opener for the combined map, above the courier rows. The dialog asks for the city and the
     * destination type itself, so unlike each courier's own Map button this one needs nothing chosen
     * first - which is the whole point: it is for the customer who has not picked a courier yet.
     *
     * Rendered as a <tr>, not a <div>: this hook fires as a direct child of the review-order table's
     * <tfoot>, outside any <tr>. A bare <div> there is not valid table content, so the HTML5 parser
     * foster-parents it out - it would land above the WHOLE order table, not above the courier rows.
     * The <tr> is what keeps it inside the table; the single colspan="2" cell (rather than the
     * label/td pairs the rows around it use) is deliberate - this row holds one control, not a
     * label and a value.
     */
    /**
     * The couriers this cart can ACTUALLY be shipped with, read from the rates on offer.
     *
     * Not from the enabled-courier settings, which is a different question. A courier can be switched on
     * and configured and still be absent from the checkout - BOX NOW is exactly that on a shop whose only
     * gateway is cash on delivery, because it cannot do ППП and ppp_filter_rates drops it. Built from
     * settings, the map button showed its mark above a list the customer could not find it in.
     *
     * @return array<string,true> courier id => true, for the couriers with a rate in this checkout.
     */
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
        // The setting gates THIS button only. The dialog itself is what every courier's own Map button
        // opens now - there is no second map any more - so switching this off removes the shortcut
        // above the rates, not the ability to pick a point on a map.
        if (get_option('bgcouriers_allmap', 'yes') !== 'yes') { return; }
        // Limited to the couriers actually on offer, so a cart that ends up with only "to address"
        // couriers gets no button at all rather than one opening an empty map.
        if (!self::has_pickup_courier(array_keys(self::offered_couriers()))) { return; }
        if (function_exists('is_cart') && is_cart()) { return; } // the pickers belong to checkout
        // The couriers' own marks, in the order the rates are listed below - the button is a shortcut
        // into a map of THESE couriers, and showing whose makes that concrete before it is opened.
        $marks = '';
        $offered = self::offered_couriers();
        foreach (BGCouriers_Settings::courier_order() as $cid) {
            // On offer for THIS cart, not merely switched on in the settings.
            if (!isset($offered[$cid])) { continue; }
            $methods = BGCouriers_Settings::enabled_methods($cid);
            if (!in_array('office', $methods, true) && !in_array('automat', $methods, true)) { continue; }
            $logo = BGCouriers_Couriers::logo_url($cid);
            if ($logo === '') { continue; }
            $marks .= '<img class="bgc-allmap-mark" src="' . esc_url($logo) . '" alt="">';
        }
        // A <tr> because this is emitted inside WooCommerce's order-review TABLE - a <div> there is
        // foster-parented straight out of it by the HTML parser. The block checkout is not a table, so
        // the button itself is built separately and wrapped only here.
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

    /** The map button on its own, with no table around it - what the block checkout needs. */
    public static function allmap_button_html(string $marks = ''): string {
        return '<button type="button" class="bgc-allmap-btn">'
            . '<span class="bgc-allmap-marks">' . $marks . '</span>'
            . esc_html__('Interactive map', 'bg-couriers')
            . '</button>';
    }

    /**
     * The same button, decided the same way, for a checkout that is not a table.
     *
     * Without it the block checkout had the map only through each courier's own Map button - which is
     * DISABLED until a town is chosen, so the one feature that exists to let a customer choose by place
     * rather than by courier had no way in at all.
     *
     * @return string '' when this cart has nothing to show on a map.
     */
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

    /** Prepend the courier's brand logo to its shipping-method radio label (BGCOURIERS methods only). */
    public function logo_shipping_label($label, $method) {
        if (!is_object($method) || !method_exists($method, 'get_method_id')) { return $label; }
        $mid = (string) $method->get_method_id();
        if (strpos($mid, 'bgcouriers_') !== 0) { return $label; }
        $url = BGCouriers_Couriers::logo_url(substr($mid, self::PREFIX_LEN));
        if ($url === '') { return $label; }
        return '<img class="bgc-ship-logo" src="' . esc_url($url) . '" alt="" aria-hidden="true" width="16" height="16"> ' . $label;
    }

    /**
     * Shipping-cost estimate on the cart page (per enabled courier + delivery option), so the customer
     * sees prices before checkout. No-API (cached reference / configured default) - the exact, address-
     * specific price is computed at checkout. Re-renders with the cart totals (WooCommerce refreshes them).
     */
    public function cart_estimate(): void {
        if (get_option('bgcouriers_cart_estimate_enabled', 'no') !== 'yes') { return; }
        if (!function_exists('WC') || !WC()->cart) { return; }
        $labels = ['office' => __('office', 'bg-couriers'), 'address' => __('address', 'bg-couriers'), 'automat' => __('APS', 'bg-couriers')];
        $names  = BGCouriers_Couriers::all();
        $rows   = [];
        foreach (BGCouriers_Settings::courier_order() as $cid) {
            if (BGCouriers_Settings::courier_config($cid) === null) { continue; } // enabled + configured only
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
        // wp_kses_post: the rows carry wc_price() markup (<span class="amount"><bdi>), which it allows.
        echo wp_kses_post('<div class="bgc-cart-estimate"><div class="bgc-cart-est-title">'
            . esc_html__('Estimated shipping (exact price at checkout)', 'bg-couriers') . '</div>'
            . implode('', $rows) . '</div>');
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
        // Nothing to earn when this courier's delivery isn't charged with the order.
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

    /**
     * The plugin collects the delivery address in its own fields, so the standard WC address
     * fields are redundant. The documented way to drop checkout fields is to unset() them from
     * the woocommerce_checkout_fields filter (classic checkout) - they are then never rendered
     * and never validated (no flicker, no hidden DOM). persist() sets the order's address from
     * our selection, and billing_country is kept so the Bulgaria shipping zone still matches.
     */
    public function simplify_fields($fields) {
        // A store that also ships some other way keeps WooCommerce's fields - the courier's own city,
        // office and address inputs then sit alongside them instead of replacing them.
        if (!BGCouriers_Settings::own_address_fields()) { return $fields; }
        foreach (['billing', 'shipping'] as $g) {
            foreach (['address_1', 'address_2', 'city', 'state', 'postcode'] as $f) {
                unset($fields[$g][$g . '_' . $f]);
            }
        }
        // A courier label needs a recipient phone, so require it. The e-mail is not the courier's business
        // (it is only forwarded if the merchant opts to), so it is optional unless the SHOP asks for it -
        // for its own order e-mails or invoices, which is a question about the shop and not about delivery.
        if (isset($fields['billing']['billing_phone'])) { $fields['billing']['billing_phone']['required'] = true; }
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['required'] = BGCouriers_Settings::require_email();
        }
        // When the country field is hidden, pin it to the country the delivery box is actually quoting
        // for, so the hidden field still submits - and submits the right one. A shop that delivers
        // nowhere else keeps sending its own country exactly as before.
        if (get_option('bgcouriers_hide_country', 'no') === 'yes') {
            $country = BGCouriers_Pricing::destination_country();
            foreach (['billing', 'shipping'] as $g) {
                if (isset($fields[$g][$g . '_country'])) { $fields[$g][$g . '_country']['default'] = $country; }
            }
        }
        return $fields;
    }

    /**
     * The couriers whose street box may only offer what they list.
     *
     * @return string[] Courier ids.
     */
    private static function street_list_only_couriers(): array {
        $out = [];
        foreach (array_keys(BGCouriers_Couriers::all()) as $id) {
            $co = BGCouriers_Couriers::get($id);
            if ($co && method_exists($co, 'street_list_only') && $co->street_list_only()) { $out[] = $id; }
        }
        return $out;
    }

    /**
     * Short-circuit WooCommerce's "enable the cart shipping calculator" option to 'no' while the setting
     * asks for it. Returning the incoming $pre unchanged means "do not short-circuit", so unticking the
     * setting hands the option straight back to WooCommerce.
     *
     * @param mixed $pre Whatever an earlier pre_option filter decided (false = read the real option).
     * @return mixed
     */
    public function hide_calculator_option($pre = false) {
        return BGCouriers_Settings::hide_shipping_calculator() ? 'no' : $pre;
    }

    /**
     * First pass over the package heading, before every other listener: note what WooCommerce produced.
     *
     * @param string $name    The heading WooCommerce built ("Shipment", "Пратка", "Shipment 2", ...).
     * @param int    $index   Package index.
     * @param array  $package The package.
     * @return string $name, untouched.
     */
    public function capture_package_name($name, $index = 0, $package = []) {
        $this->wc_package_names[(int) $index] = (string) $name;
        return $name;
    }

    /**
     * Last pass, after every other listener: drop the heading above the shipping methods.
     *
     * WooCommerce 10.x renamed it from "Shipping" to "Shipment", and several locales - bg_BG among them -
     * have no translation for the new string yet, so a fully Bulgarian checkout showed one English word
     * directly above our courier picker. Removing it beats translating it: the picker underneath already
     * says what the block is.
     *
     * @param string $name    The heading as it stands after every other plugin has had its say.
     * @param int    $index   Package index.
     * @param array  $package The package.
     * @return string '' to drop the heading, or $name to leave it exactly as it is.
     */
    public function package_name($name, $index = 0, $package = []) {
        // Anything another plugin put here is that plugin's business, not ours.
        if (($this->wc_package_names[(int) $index] ?? null) !== (string) $name) { return $name; }
        // Our courier picker sits directly under this heading and already says what it is, so the word
        // above it is pure repetition eating vertical space - which matters most on a phone, where the
        // shipping block can fill the screen. A cart split into several packages keeps its headings:
        // "Shipment 1 / 2" is the only thing telling them apart.
        return $this->single_package() ? '' : $name;
    }

    /** Whether the cart produced exactly one shipping package. Unknown (no WooCommerce yet) counts as one. */
    private function single_package(): bool {
        if (!function_exists('WC') || !WC() || !WC()->shipping()) { return true; }
        return count((array) WC()->shipping()->get_packages()) <= 1;
    }

    public function package_hash($packages) {
        $s = WC()->session;
        if (!$s) { return $packages; }
        // The payment method belongs in the key as much as the office does: cash on delivery costs the
        // courier a collection fee and therefore changes the price. WooCommerce caches a package's rates
        // against a hash of the package, so without this the shopper switching to cash on delivery would
        // keep being shown the prepaid price that was cached before they switched.
        $key = (string) $s->get('bgcouriers_method', '') . ':' . (int) $s->get('bgcouriers_site_id', 0)
             . ':' . (int) $s->get('bgcouriers_office_id', 0)
             . ':' . (string) $s->get('chosen_payment_method', '');
        foreach ($packages as $i => $pkg) { $packages[$i]['bgcouriers_selection'] = $key; }
        return $packages;
    }

    /** Order the courier shipping rates at checkout by the configured courier order (General settings). */
    public function sort_rates($rates) {
        if (!is_array($rates) || count($rates) < 2) { return $rates; }
        $pos = array_flip(BGCouriers_Settings::courier_order());
        $key = static function ($r) use ($pos) {
            $mid = (is_object($r) && method_exists($r, 'get_method_id')) ? (string) $r->get_method_id() : '';
            return strpos($mid, self::METHOD_PREFIX) === 0 ? ($pos[substr($mid, self::PREFIX_LEN)] ?? 900) : 1000; // other plugins' rates keep to the end
        };
        uasort($rates, static function ($a, $b) use ($key) { return $key($a) <=> $key($b); });
        return $rates;
    }

    /** The courier id of the chosen bgcouriers_<id> shipping method, or null. */
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

    /**
     * Does anyone deliver to a pickup point? The combined map plots offices and APS lockers, so a store
     * whose couriers all deliver to the door has nothing to show and gets no button.
     *
     * @param string[]|null $courier_ids Courier ids to consider; null = every registered courier. The
     *                                   argument exists so the rule can be tested on its own.
     * @return bool
     */
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
        if (!$courier) { return; } // a non-bgc shipping method - not ours to validate
        $names = BGCouriers_Couriers::all();
        $label = $names[$courier] ?? ucfirst($courier);
        // Validate the phone FORMAT (it's already required elsewhere) so a malformed number is caught here
        // with a friendly message, not as a cryptic courier API rejection after the order is placed. Bulgarian
        // numbers: 0 + 8-9 digits (e.g. 0888123456) or +359 + 8-9 digits; separators are stripped first.
        // Abroad the same rule would reject the recipient's own perfectly good number, so there the test is
        // only that the number can be turned into something a courier will accept, in that country's code.
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
        $s = WC()->session;
        // The saved selection must belong to the courier actually chosen - switching couriers voids the old pick.
        if ((string) $s->get('bgcouriers_selection_courier', '') !== $courier) {
            /* translators: %s: courier name */
            $errors->add('bgc', sprintf(__('Please choose your %s delivery point before placing the order.', 'bg-couriers'), $label));
            return;
        }
        // BoxNow - a locker picked on the map widget (no city).
        if ($courier === 'boxnow') {
            if ((int) $s->get('bgcouriers_office_id', 0) <= 0) {
                $errors->add('bgc', __('Please choose a BOX NOW locker before placing the order.', 'bg-couriers'));
            }
            return;
        }
        // City/office couriers (Speedy, Econt, Pigeon).
        $method = (string) $s->get('bgcouriers_method', '');
        if ((int) $s->get('bgcouriers_site_id', 0) <= 0) {
            /* translators: %s: courier name */
            $errors->add('bgc', sprintf(__('Please choose a city for %s delivery.', 'bg-couriers'), $label));
        }
        if ($method === 'address') {
            $street = (string) $s->get('bgcouriers_addr_street_name', '');
            $no     = (string) $s->get('bgcouriers_addr_street_no', '');
            if ($street === '' || $no === '') {
                /* translators: %s: courier name */
                $errors->add('bgc', sprintf(__('Please enter a street and number for %s address delivery.', 'bg-couriers'), $label));
            }
        } elseif ((int) $s->get('bgcouriers_office_id', 0) <= 0) {
            /* translators: %s: courier name */
            $errors->add('bgc', sprintf(__('Please choose an office/APS for %s.', 'bg-couriers'), $label));
        }
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
            'quote_price'  => (float) $s->get('bgcouriers_quote_price', 0),
            'quote_source' => (string) $s->get('bgcouriers_quote_source', ''),
            'street_name'  => (string) $s->get('bgcouriers_addr_street_name', ''),
            'street_no'    => (string) $s->get('bgcouriers_addr_street_no', ''),
            'complex'      => (string) $s->get('bgcouriers_addr_complex', ''),
            'block'        => (string) $s->get('bgcouriers_addr_block', ''),
            'entrance'     => (string) $s->get('bgcouriers_addr_entrance', ''),
            'floor'        => (string) $s->get('bgcouriers_addr_floor', ''),
            'apartment'    => (string) $s->get('bgcouriers_addr_apartment', ''),
            'address_note' => (string) $s->get('bgcouriers_addr_address_note', ''),
            'boxnow_name'  => (string) $s->get('bgcouriers_boxnow_name', ''),
            'boxnow_addr'  => (string) $s->get('bgcouriers_boxnow_addr', ''),
        ]);
    }

    /**
     * Write a delivery selection ($d) onto an order: the _bgcouriers_* meta the label uses, plus the WC
     * billing/shipping address for display. Shared by the checkout (from the session) and the admin
     * order editor (from POST), so both produce identical order data.
     */
    public static function apply_delivery(\WC_Order $order, array $d): void {
        $courier = (string) ($d['courier'] ?? '');
        if ($courier === '') { return; }
        $g = static function ($k, $def = '') use ($d) { return $d[$k] ?? $def; };
        // BoxNow is locker-only; force 'automat' so a stale method can't leak on.
        $method = $courier === 'boxnow'
            ? 'automat'
            : ((string) $g('method') ?: (BGCouriers_Settings::enabled_methods($courier)[0] ?? 'office'));

        // The country is NOT defaulted to the shop's own here. The admin order editor posts a delivery
        // without one, and defaulting would quietly move a Romanian order back to Bulgaria the first time
        // someone opened it and pressed save - and with it the service the label is booked under.
        $country = strtoupper(trim((string) $g('country')));
        if ($country === '') { $country = BGCouriers_Settings::order_country($order); }

        $order->update_meta_data('_bgcouriers_courier', $courier);
        $order->update_meta_data('_bgcouriers_country', $country);
        $order->update_meta_data('_bgcouriers_method', $method);
        $order->update_meta_data('_bgcouriers_site_id', (int) $g('site_id', 0));
        $order->update_meta_data('_bgcouriers_office_id', (int) $g('office_id', 0));
        $order->update_meta_data('_bgcouriers_post_code', (string) $g('post_code'));
        $order->update_meta_data('_bgcouriers_street_name', (string) $g('street_name'));
        $order->update_meta_data('_bgcouriers_street_no',   (string) $g('street_no'));
        $order->update_meta_data('_bgcouriers_complex',     (string) $g('complex'));
        $order->update_meta_data('_bgcouriers_block',       (string) $g('block'));
        $order->update_meta_data('_bgcouriers_entrance',    (string) $g('entrance'));
        $order->update_meta_data('_bgcouriers_floor',       (string) $g('floor'));
        $order->update_meta_data('_bgcouriers_apartment',   (string) $g('apartment'));
        $order->update_meta_data('_bgcouriers_address_note',(string) $g('address_note'));
        $order->update_meta_data('_bgcouriers_boxnow_name', (string) $g('boxnow_name'));
        $order->update_meta_data('_bgcouriers_boxnow_addr', (string) $g('boxnow_addr'));
        if (array_key_exists('quote_price', $d))  { $order->update_meta_data('_bgcouriers_quote_price', (float) $d['quote_price']); }
        if (array_key_exists('quote_source', $d)) { $order->update_meta_data('_bgcouriers_quote_source', (string) $d['quote_source']); }

        // Fill the WC order address from the selection (the label itself uses the _bgcouriers_* meta above).
        $city   = (int) $g('site_id', 0) ? BGCouriers_Nomenclature::city_by_id($courier, (int) $g('site_id', 0)) : null;
        $name   = (string) ($city['name'] ?? '');
        $post   = (string) $g('post_code') ?: (string) ($city['post_code'] ?? '');
        $region = (string) ($city['region'] ?? '');
        if ($courier === 'boxnow') {
            $name = ''; $post = '';
            $line1 = (string) $g('boxnow_name');
            $line2 = (string) $g('boxnow_addr');
        } elseif ($method === 'address') {
            $line1 = trim((string) $g('street_name') . ' ' . (string) $g('street_no'));
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
    }
    public function assets(): void {
        $on_cart     = function_exists('is_cart') && is_cart();
        // ...or any page carrying the checkout BLOCK. is_checkout() is true only for the page WooCommerce
        // has been told is the checkout; the block can be dropped anywhere.
        $on_checkout = (function_exists('is_checkout') && is_checkout()) || BGCouriers_Blocks::is_block_checkout();
        if (!$on_cart && !$on_checkout) { return; }
        // The courier rate rows are the same markup on both pages, so their stylesheet loads on both.
        // Without this the cart had no row styling at all and the theme stacked radio, logo, name and
        // price on separate lines.
        $rates_css = BGCOURIERS_PATH . 'assets/css/bgc-rates.css';
        wp_enqueue_style('bgc-rates', BGCOURIERS_URL . 'assets/css/bgc-rates.css', [], is_file($rates_css) ? (string) filemtime($rates_css) : BGCOURIERS_VERSION);
        // Cart page: plus the small static stylesheet (the estimate box).
        if ($on_cart) {
            $cart_css = BGCOURIERS_PATH . 'assets/css/bgc-cart.css';
            wp_enqueue_style('bgc-cart', BGCOURIERS_URL . 'assets/css/bgc-cart.css', ['bgc-rates'], is_file($cart_css) ? (string) filemtime($cart_css) : BGCOURIERS_VERSION);
            // The net for themes that render WooCommerce's calculator from their own template regardless
            // of the option. Inline rather than in the stylesheet so that a store which unticks the
            // setting gets no rule hiding a calculator it asked to keep.
            if (BGCouriers_Settings::hide_shipping_calculator()) {
                wp_add_inline_style('bgc-cart', '.woocommerce-shipping-calculator{display:none!important;}');
            }
        }
        if (!$on_checkout) { return; }
        wp_enqueue_style('select2');
        // Version by file mtime so every asset change busts the browser cache automatically.
        $css = BGCOURIERS_PATH . 'assets/css/bgc-checkout.css';
        $js  = BGCOURIERS_PATH . 'assets/js/bgc-checkout.js';
        // Leaflet (bundled locally - no CDN, WP.org-safe) powers the office/APS map picker.
        wp_enqueue_style('bgc-leaflet', BGCOURIERS_URL . 'assets/lib/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('bgc-leaflet', BGCOURIERS_URL . 'assets/lib/leaflet/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('bgc-checkout', BGCOURIERS_URL . 'assets/css/bgc-checkout.css', ['bgc-leaflet', 'bgc-rates'], is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
        wp_enqueue_script('bgc-checkout', BGCOURIERS_URL . 'assets/js/bgc-checkout.js', ['jquery', 'selectWoo', 'bgc-leaflet'], is_file($js) ? (string) filemtime($js) : BGCOURIERS_VERSION, true);
        // The combined map dialog. Separate files on purpose: bgc-checkout.js already carries the
        // per-courier picker, and that one must keep working exactly as it does. Not loaded at all when
        // the merchant has switched the map off - an unused script is still a script the customer waits for.
        if (self::has_pickup_courier()) {
            $allmap_css = BGCOURIERS_PATH . 'assets/css/bgc-allmap.css';
            $allmap_js  = BGCOURIERS_PATH . 'assets/js/bgc-allmap.js';
            wp_enqueue_style('bgc-allmap', BGCOURIERS_URL . 'assets/css/bgc-allmap.css', ['bgc-checkout'], is_file($allmap_css) ? (string) filemtime($allmap_css) : BGCOURIERS_VERSION);
            wp_enqueue_script('bgc-allmap', BGCOURIERS_URL . 'assets/js/bgc-allmap.js', ['bgc-checkout'], is_file($allmap_js) ? (string) filemtime($allmap_js) : BGCOURIERS_VERSION, true);
        }
        // When enabled (default), preload each enabled courier's cities-with-offices (office/automat) so the
        // checkout city dropdown needs no AJAX and availability is derived client-side. The AJAX path stays
        // as the fallback + for address (all BG cities). Off => nothing preloaded, pure AJAX as before.
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
            // The shop's own country, and the one the preloaded city index was actually built for: pick
            // another country and that index is a list of the wrong towns, so the browser stops using it.
            'homeCountry' => BGCouriers_Settings::home_country(),
            'cityIndexCountry' => BGCouriers_Pricing::destination_country(),
            // "Closest to you" on the interactive map. A merchant can switch the whole comparison off:
            // it asks the browser for a location, and a shop that would rather not ask at all should
            // not have to disable the map to avoid it.
            //
            // 'yes'/'no' rather than a boolean, deliberately: wp_localize_script() casts every value to
            // a string, so `false` arrives in JavaScript as '' - which is indistinguishable from a key
            // that is not there at all. This setting defaults to ON, so those two cases have to be
            // told apart, and the string is what tells them apart.
            'allmapNearest' => get_option('bgcouriers_allmap_nearest', 'yes') === 'yes' ? 'yes' : 'no',
            'cityIndex' => $city_index,
            // Couriers whose street box must not accept a typed street (see street_list_only()). A list
            // rather than a flag per courier, so the browser can ask about whichever one is chosen.
            'streetListOnly' => self::street_list_only_couriers(),
            'addressMap' => get_option('bgcouriers_address_map', 'no') === 'yes',
            // The Google Maps key is deliberately NOT sent to the browser: nothing here loads a Google
            // map, and the key is only ever used server-side, for the reverse geocode in
            // BGCouriers_Ajax::geocode(). Localising it printed the merchant's key in the page source of
            // every checkout, where anyone could spend their Geocoding quota with it.
            'leaflet_images' => BGCOURIERS_URL . 'assets/lib/leaflet/images/', // bundled Leaflet marker icons
            'icons' => BGCouriers_Icons::map(), // same delivery-type glyphs as the admin, shown with text on the tabs
            'emergency' => BGCouriers_Settings::emergency(),
            'boxnow' => [
                'widget'    => 'https://map.boxnow.bg/iframe.html', // BoxNow map widget (has built-in GPS)
                'partnerId' => (string) get_option('bgcouriers_boxnow_partner_id', ''),
                'country'   => 'bg',
                'gps'       => 'yes',
            ],
            'i18n'  => [
                'address'=>__('To address','bg-couriers'),'office'=>__('To office','bg-couriers'),'automat'=>__('To APS','bg-couriers'),
                'office_label'=>__('Office','bg-couriers'),'automat_label'=>__('APS (locker)','bg-couriers'),
                'emerg_default'=>__('Having trouble placing your order? We can help - call us:','bg-couriers'),
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
                'map_none' => __('No offices with a map location for this city yet - use the list.','bg-couriers'),
                'addr_map_title' => __('Choose your address on the map','bg-couriers'),
                'addr_map_hint' => __('Click the map or drag the pin to your address.','bg-couriers'),
                'addr_use' => __('Use this address','bg-couriers'),
                'addr_none' => __('No address found here - try another spot.','bg-couriers'),
                'allmap_title' => __('Interactive map', 'bg-couriers'),
                'allmap_show' => __('Show the offices', 'bg-couriers'),
                'allmap_na' => __('Not available for this order', 'bg-couriers'),
                'allmap_choose' => __('Choose', 'bg-couriers'),
                'allmap_city_ph' => __('Choose a city', 'bg-couriers'),
                // The two halves of the phone-sized dialog, which shows one of them at a time.
                'allmap_map' => __('Map', 'bg-couriers'),
                'allmap_list' => __('List', 'bg-couriers'),
                // Said while the couriers are being asked - a spinner alone in a large empty dialog
                // reads as nothing happening.
                'allmap_loading' => __('Loading the pickup points…', 'bg-couriers'),
                // Opens Google Maps directions to the point. No origin is sent - Google uses wherever the
                // customer is, which is the whole question they are asking.
                'allmap_directions' => __('Directions', 'bg-couriers'),
                'clear' => __('Clear', 'bg-couriers'),
                // The nearest-office comparison on the map. Short by design: they sit on one line
                // together with a distance and two prices.
                'near_title' => __('Closest to you', 'bg-couriers'),
                'near_to_address' => __('to your address', 'bg-couriers'),
                'near_save' => __('you save', 'bg-couriers'),
                'near_straight' => __('as the crow flies', 'bg-couriers'),
                'near_find' => __('Find the closest office', 'bg-couriers'),
                'near_drag' => __('Drag the pin to where you are', 'bg-couriers'),
                'near_m' => __('m', 'bg-couriers'),
                'near_km' => __('km', 'bg-couriers'),
                // The answer line names a courier and a distance but not WHICH point it means; it is a
                // button, and this says so.
                'near_which' => __('Show this point on the map', 'bg-couriers'),
                // On a point's own bubble, once the customer's location is known.
                'near_from_you' => __('from you', 'bg-couriers'),
            ],
        ]);

        // Hide configured checkout fields. The selectors are a merchant-entered setting printed into a
        // stylesheet, so they go through hidden_field_selectors(), which validates each entry and drops
        // anything that is not a plain selector.
        $selectors = BGCouriers_Settings::hidden_field_selectors();
        if ($selectors !== '') {
            wp_add_inline_style('bgc-checkout', $selectors . '{display:none !important;}');
        }
        // Hide the Country/Region field (BG-only store) when enabled in settings.
        if (get_option('bgcouriers_hide_country', 'no') === 'yes') {
            wp_add_inline_style('bgc-checkout', '#billing_country_field,#shipping_country_field{display:none !important;}');
        }
    }
    public function render_fields($method, $index): void {
        if (strpos((string) $method->get_method_id(), 'bgcouriers_') !== 0) { return; }
        // The interactive pickers belong to checkout (their JS/CSS only load there). On the cart page keep
        // the rate row clean - the customer picks the destination at checkout (the cart shows the estimate).
        if (function_exists('is_cart') && is_cart()) { return; }
        $courier = substr((string) $method->get_method_id(), self::PREFIX_LEN); // 'bgcouriers_speedy' -> 'speedy'
        if (!BGCouriers_Couriers::get($courier)) { return; }
        if ($courier === 'boxnow') { $this->render_boxnow_fields(WC()->session); return; } // locker chosen on the map widget
        // Stateful: re-render the session selection so update_checkout recalcs don't wipe the fields.
        // Only render a selection that was made for THIS courier - switching couriers must not show a
        // stale city/office from another courier (whose ids are invalid here).
        $s = WC()->session;
        $mine = $s && (string) $s->get('bgcouriers_selection_courier', '') === $courier;
        $sel_method = $mine ? (string) $s->get('bgcouriers_method', '') : '';
        $site_id    = $mine ? (int) $s->get('bgcouriers_site_id', 0) : 0;
        $office_id  = $mine ? (int) $s->get('bgcouriers_office_id', 0) : 0;
        $post_code  = $mine ? (string) $s->get('bgcouriers_post_code', '') : '';
        // Not the current selection, but the customer HAS been in this courier before: show them back
        // what they chose in it. The price row above is quoted from the same memory
        // (BGCouriers_Pricing::selection_for), and a block showing "to address" over a locker's price is
        // the two halves of one row disagreeing about what is being bought.
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
        // Carry the city across a courier switch: postcode is courier-agnostic, so if this courier has no
        // selection yet but the customer already picked a city, pre-fill the same city (resolved for THIS
        // courier). The office stays empty - office ids are courier-specific, so they pick that again.
        if (!$mine && $s) {
            $carry_pc = (string) $s->get('bgcouriers_post_code', '');
            if ($carry_pc !== '') {
                $carry_city = BGCouriers_Nomenclature::city_by_postcode($courier, $carry_pc, BGCouriers_Pricing::destination_country());
                if ($carry_city) { $site_id = (int) $carry_city['city_id']; $post_code = $carry_pc; }
            }
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
        if ($office_id) {
            $office = BGCouriers_Nomenclature::office_by_id($courier, $office_id);
            if ($office) {
                $office_option = '<option value="' . esc_attr($office_id) . '" selected>' . esc_html($office['name'] . ' - ' . $office['address']) . '</option>';
            }
        } elseif ($site_id && $sel_method !== '' && $sel_method !== 'address') {
            // A town with exactly ONE office - or one locker - is not a choice, and most towns are like
            // that. Presenting a dropdown holding a single row and waiting to be told about it is work
            // for the customer that answers itself.
            //
            // Decided HERE rather than in the browser because this block is re-rendered by the server on
            // every recalculation: doing it in JS meant the selection was made and then wiped by the very
            // next round, which is the same race that has bitten this file before. Rendered as the chosen
            // option it simply IS the state, and cannot be lost.
            $only = BGCouriers_Nomenclature::offices($courier, $site_id, $sel_method);
            if (count($only) === 1) {
                $one = $only[0];
                $office_option = '<option value="' . esc_attr((string) $one['office_id']) . '" selected>'
                    . esc_html($one['name'] . ' - ' . $one['address']) . '</option>';
                $auto_office = true;   // the JS stores it once, so the ORDER carries what the field shows
            }
        }
        // Office/automat picker shows for office+automat methods, hides for address.
        $office_style = ($sel_method === 'address') ? ' style="display:none;"' : '';

        $av = function ($k) use ($s, $mine) { return $mine ? esc_attr((string) $s->get('bgcouriers_addr_' . $k, '')) : ''; };
        $sn = $mine ? (string) $s->get('bgcouriers_addr_street_name', '') : '';
        $street_option = $sn !== '' ? '<option value="' . esc_attr($sn) . '" selected>' . esc_html($sn) . '</option>' : '';
        $addr_style = ($sel_method === 'address') ? '' : ' style="display:none;"';

        $office_label = ($sel_method === 'automat')
            ? esc_html__('APS (locker)', 'bg-couriers')
            : esc_html__('Office', 'bg-couriers');

        // Where this parcel is going. A shop that delivers only at home has one country and is shown no
        // choice at all - the field would be a dropdown with a single entry, asking a question that has
        // one answer. It renders as a data attribute regardless, so the browser always knows.
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

        // Only the chosen courier's block is visible from the server - the others render hidden so the page
        // doesn't briefly show every courier's fields expanded before the JS hides them. JS keeps this in sync.
        $hide = self::chosen_courier() !== $courier ? ' style="display:none;"' : '';
        // Built up first, then escaped once at output - every field inside is already esc_attr/esc_html'd,
        // and wp_kses() restricts the result to the tags this form is made of.
        $html = '<div class="bgc-fields" data-courier="' . esc_attr($courier) . '" data-method="' . esc_attr($sel_method) . '"'
           . ' data-methods="' . esc_attr(implode(',', BGCouriers_Settings::enabled_methods($courier))) . '"'
           . ' data-order="' . esc_attr(implode(',', BGCouriers_Settings::method_order($courier))) . '"'
           . ' data-country="' . esc_attr($country) . '"' . $hide . '>'
           . '<div class="bgc-loader" aria-hidden="true"><span class="bgc-spinner"></span></div>'
           . '<div class="bgc-tabs" role="tablist"></div>'
           . '<div class="bgc-panel">'
           . $country_row
           // The postcode rides along in the city label as "Name (1234)" (search + disambiguation); its value
           // is kept in a hidden field - it's never sent to a courier, only used to carry the city across a
           // courier switch and to set the order's postcode record.
           . '<div class="bgc-field bgc-city-field"><label>' . esc_html__('City', 'bg-couriers') . '</label>'
           . '<select class="bgc-city"><option value=""></option>' . $city_option . '</select>'
           . '<input type="hidden" class="bgc-postcode" value="' . esc_attr($post_code) . '"></div>'
           . '<div class="bgc-field bgc-office-row"' . $office_style . '><label class="bgc-office-label">' . $office_label . '</label>'
           . '<div class="bgc-office-pick"><select class="bgc-office"' . ($auto_office ? ' data-auto="1"' : '') . '>' . $office_option . '</select>'
           . '<button type="button" class="bgc-map-btn" title="' . esc_attr__('View on map', 'bg-couriers') . '">'
           . '<svg class="bgc-map-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>'
           . '<span>' . esc_html__('Map', 'bg-couriers') . '</span></button></div></div>'
           . '<div class="bgc-address-rows"' . $addr_style . '>'
           . '<div class="bgc-grid' . (get_option('bgcouriers_address_map', 'no') === 'yes' ? ' bgc-grid-map' : '') . '">'
           . '<div class="bgc-field bgc-street-field"><label>' . esc_html__('Street', 'bg-couriers') . ' *</label><select class="bgc-street"><option value=""></option>' . $street_option . '</select></div>'
           . '<div class="bgc-field bgc-streetno-field"><label>' . esc_html__('No.', 'bg-couriers') . ' *</label><input type="text" class="bgc-street-no" autocomplete="off" value="' . $av('street_no') . '"></div>'
           . (get_option('bgcouriers_address_map', 'no') === 'yes'
               // Small map-pin icon next to No. (same generic style as the order editor), not a full-width button.
               ? '<div class="bgc-field bgc-addr-map-cell"><label aria-hidden="true">&nbsp;</label>'
                 . '<button type="button" class="bgc-map-btn bgc-addr-map-btn bgc-addr-map-icon" title="' . esc_attr__('Choose on map', 'bg-couriers') . '" aria-label="' . esc_attr__('Choose on map', 'bg-couriers') . '">'
                 . '<svg class="bgc-map-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>'
                 . '</button></div>'
               : '')
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
        echo wp_kses($html, BGCouriers_Kses::checkout_fields());
    }

    /** BOX NOW checkout: a locker chosen on the BoxNow map widget (no city/office dropdowns). */
    private function render_boxnow_fields($s): void {
        // Only treat the saved locker as ours if the selection was actually made for BoxNow - otherwise a
        // stale office id from a previously-chosen courier would render an empty "selected locker" box.
        $mine   = $s && (string) $s->get('bgcouriers_selection_courier', '') === 'boxnow';
        $locker = $mine ? (int) $s->get('bgcouriers_office_id', 0) : 0;
        $name   = $mine ? (string) $s->get('bgcouriers_boxnow_name', '') : '';
        $addr   = $mine ? (string) $s->get('bgcouriers_boxnow_addr', '') : '';
        $has    = $locker > 0;
        $hide   = self::chosen_courier() !== 'boxnow' ? ' style="display:none;"' : ''; // hidden unless BoxNow is the chosen courier
        $html = '<div class="bgc-fields bgc-boxnow" data-courier="boxnow" data-method="automat" data-methods="automat" data-order="automat"' . $hide . '>'
           . '<div class="bgc-panel">'
           . '<button type="button" class="button bgc-boxnow-pick">' . esc_html__('Choose a BOX NOW locker', 'bg-couriers') . '</button>'
           . '<div class="bgc-boxnow-selected"' . ($has ? '' : ' style="display:none;"') . '>'
           . '<strong class="bgc-boxnow-name">' . esc_html($name) . '</strong>'
           . '<span class="bgc-boxnow-addr"> ' . esc_html($addr) . '</span>'
           . '</div>'
           . '<input type="hidden" class="bgc-boxnow-id" value="' . esc_attr($has ? (string) $locker : '') . '">'
           . '</div></div>';
        echo wp_kses($html, BGCouriers_Kses::checkout_fields());
    }
}
