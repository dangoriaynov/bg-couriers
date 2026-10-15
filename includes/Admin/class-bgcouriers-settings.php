<?php
defined('ABSPATH') || exit;

/** The plugin's settings, read side only; the admin side is BGCouriers_Settings_Admin, the tab BGCouriers_WC_Settings. */
class BGCouriers_Settings {

    /** Product types that are a list of other products rather than a parcel. */
    private const CONTAINER_TYPES = ['bundle', 'grouped', 'woosb', 'yith_bundle', 'composite'];

    const METHODS = ['office', 'address', 'automat'];
    // ---- data accessors ----

    public static function get(string $group, string $key, $default = '') {
        $name = $group === 'global' ? 'bgcouriers_' . $key : 'bgcouriers_' . $group . '_' . $key;
        return get_option($name, $default);
    }
    /** What this courier needs to talk to its API, whether or not it is enabled: orders placed with a disabled courier still print and track. */
    public static function courier_credentials(string $courier): ?array {
        if (!array_key_exists($courier, BGCouriers_Couriers::all())) { return null; }
        return [
            'username' => get_option('bgcouriers_' . $courier . '_username', ''),
            'password' => BGCouriers_Encryption::decrypt(get_option('bgcouriers_' . $courier . '_password', '')),
        ];
    }
    /** The credentials, but only while the courier is switched on and usable. */
    public static function courier_config(string $courier): ?array {
        if (get_option('bgcouriers_' . $courier . '_enabled', 'no') !== 'yes') { return null; }
        // Switched on is an intention; without validated credentials there is no live price and no waybill, so not offered.
        if (!self::creds_present($courier)) { return null; }
        if (get_option('bgcouriers_' . $courier . '_validated', 'yes') !== 'yes') { return null; }
        return self::courier_credentials($courier);
    }
    /** Every gate before the checkout may offer this courier: on, credentials validated, at least one delivery option on. */
    public static function courier_offerable(string $courier): bool {
        return self::courier_config($courier) !== null && self::enabled_methods($courier) !== [];
    }
    /** Diagnosis only (WooCommerce never calls a method outside a zone); true when it cannot tell. */
    public static function in_a_shipping_zone(string $courier): bool {
        if (!class_exists('WC_Shipping_Zones')) { return true; }
        $id = 'bgcouriers_' . $courier;
        $zones = WC_Shipping_Zones::get_zones();
        $zones[] = ['id' => 0]; // "Locations not covered by your other zones" is a zone like any other
        foreach ($zones as $z) {
            $zone = WC_Shipping_Zones::get_zone((int) ($z['id'] ?? 0));
            if (!$zone) { continue; }
            foreach ($zone->get_shipping_methods(true) as $m) {
                if (isset($m->id) && $m->id === $id) { return true; }
            }
        }
        return false;
    }
    /** @return array<string,string> id => label of registered couriers. */
    public static function couriers(): array { return BGCouriers_Couriers::all(); }
    /** 'live' | 'fallback' | 'fixed' per courier+method. */
    public static function price_mode(string $courier, string $method): string {
        $m = (string) get_option('bgcouriers_' . $courier . '_' . $method . '_price_mode', 'fallback');
        if (!in_array($m, ['live', 'fallback', 'fixed'], true)) { $m = 'fallback'; }
        // A courier with no price endpoint can only be fixed, whatever is stored.
        $c = BGCouriers_Couriers::get($courier);
        if ($c && !in_array('live_quote', $c->capabilities(), true)) { return 'fixed'; }
        return $m;
    }
    public static function method_config(string $courier, string $method): array {
        $p = 'bgcouriers_' . $courier . '_' . $method . '_';
        return [
            'enabled' => get_option($p . 'enabled', 'yes') === 'yes',
            'price'   => (float) get_option($p . 'price', 0),
        ];
    }
    const DROPDOWN_LIMIT = 20;
    /** Bounds the city search (when not preloaded) and the street search; office lists are never limited. */
    public static function dropdown_limit(): int {
        $raw = get_option('bgcouriers_dropdown_limit', self::DROPDOWN_LIMIT);
        if ($raw === '' || (int) $raw <= 0) { return self::DROPDOWN_LIMIT; }
        return (int) $raw;
    }
    /** Merchant-absorbed free delivery for this order; also decides the payer on the waybill (sender-paid). */
    public static function free_for_order(\WC_Order $order, string $courier): bool {
        $method = (string) $order->get_meta('_bgcouriers_method');
        $cfg    = self::free_shipping($courier, $method);
        if (empty($cfg['enabled'])) { return false; }
        // Goods only, as the cart rule reads it: the delivery charge never counts towards the threshold.
        $goods = (float) $order->get_subtotal();
        return $goods >= (float) $cfg['threshold'];
    }
    /** A courier-level threshold applies to every delivery option; per-option thresholds only when it is empty. */
    public static function free_shipping(string $courier, string $method = ''): array {
        $threshold = (float) get_option('bgcouriers_' . $courier . '_free_threshold', 0);
        if ($threshold <= 0 && $method !== '') {
            $threshold = (float) get_option('bgcouriers_' . $courier . '_' . $method . '_free_threshold', 0);
        }
        return [
            'enabled'   => $threshold > 0,
            'threshold' => $threshold,
        ];
    }
    /** Parcel dimensions in cm: measured from the order when auto_box() is on, else the configured default. */
    public static function box_dims(?\WC_Order $order = null): array {
        $default = self::configured_box();
        if ($order instanceof \WC_Order && self::auto_box()) {
            $box = self::box_of_units(self::order_units($order, $default), self::box_factor());
            if ($box) { return $box; }
        }
        return $default;
    }

    public static function configured_box(): array {
        // Default 10x10x2 cm: small enough to pass every courier's locker compartment validation.
        $g = static function (string $k, int $default): int {
            $v = (int) get_option('bgcouriers_box_' . $k, 0);
            if ($v <= 0) { $v = (int) get_option('bgcouriers_pigeon_box_' . $k, $default); } // pre-move installs
            return max(1, $v);
        };
        return ['length' => $g('length', 10), 'width' => $g('width', 10), 'height' => $g('height', 2)];
    }

    public static function auto_box(): bool {
        return 'no' !== get_option('bgcouriers_auto_box', 'yes');
    }

    public static function box_factor(): float {
        $v = (float) get_option('bgcouriers_box_factor', 1.1);
        return min(3.0, max(1.0, $v > 0 ? $v : 1.1));
    }

    public static function packaging_kg(): float {
        return max(0.0, min(5.0, round((float) get_option('bgcouriers_packaging_kg', 0.0), 3)));
    }

    /**
     * One rectangle for a parcel holding these units: the footprint of the biggest item, the height that holds the volume.
     * @param array[] $units [['l'=>cm,'w'=>cm,'h'=>cm,'qty'=>n], ...]
     * @return array ['length'=>cm,'width'=>cm,'height'=>cm] or [] when nothing has a size.
     */
    public static function box_of_units(array $units, float $factor = 1.0): array {
        $volume   = 0.0;
        $long     = 0.0;
        $short    = 0.0;
        $thick    = 0.0;
        $measured = false;
        foreach ($units as $u) {
            $d = [(float) ($u['l'] ?? 0), (float) ($u['w'] ?? 0), (float) ($u['h'] ?? 0)];
            rsort($d);
            if ($d[0] <= 0 || $d[1] <= 0 || $d[2] <= 0) { continue; }
            $volume += $d[0] * $d[1] * $d[2] * max(1, (int) ($u['qty'] ?? 1));
            $long    = max($long, $d[0]);
            $short   = max($short, $d[1]);
            $thick   = max($thick, $d[2]);
            // A unit standing in for a product that has no dimensions is not a measurement.
            if (false !== ($u['measured'] ?? true)) { $measured = true; }
        }
        // Nothing measured: the configured parcel as it is, without a packaging allowance on top.
        if (!$measured) { return []; }
        if ($volume <= 0 || $long <= 0 || $short <= 0) { return []; }
        $volume *= max(1.0, $factor);
        return [
            'length' => (int) ceil($long),
            'width'  => (int) ceil($short),
            // Never below the thickest single item: the volume alone can ask for less than one item is tall.
            'height' => max(1, (int) ceil($thick), (int) ceil($volume / ($long * $short))),
        ];
    }

    /** @return array[] units for box_of_units(); a product without dimensions counts as the configured default parcel. */
    public static function order_units(\WC_Order $order, array $default): array {
        $pairs = [];
        foreach ($order->get_items() as $item) {
            $pairs[] = [method_exists($item, 'get_product') ? $item->get_product() : null,
                        max(1, (int) $item->get_quantity())];
        }
        return self::units_of($pairs, $default);
    }

    /** The same measurement for the cart: decides which lockers are offered at all. */
    public static function cart_box_dims(): array {
        $default = self::configured_box();
        if (!self::auto_box() || !function_exists('WC') || !WC()->cart) { return $default; }
        $pairs = [];
        foreach (WC()->cart->get_cart() as $line) {
            $pairs[] = [$line['data'] ?? null, max(1, (int) ($line['quantity'] ?? 1))];
        }
        $box = self::box_of_units(self::units_of($pairs, $default), self::box_factor());
        return $box ?: $default;
    }

    /**
     * @param array[] $pairs [[\WC_Product|null, qty], ...]
     * @return array[] units for box_of_units()
     */
    private static function units_of(array $pairs, array $default): array {
        $unit  = (string) get_option('woocommerce_dimension_unit', 'cm');
        $to_cm = static function ($v) use ($unit) {
            return function_exists('wc_get_dimension') ? (float) wc_get_dimension((float) $v, 'cm', $unit) : (float) $v;
        };
        $units = [];
        foreach ($pairs as $pair) {
            $product = $pair[0] ?? null;
            $qty     = max(1, (int) ($pair[1] ?? 1));
            $l = $product && method_exists($product, 'get_length') ? (float) $product->get_length() : 0.0;
            $w = $product && method_exists($product, 'get_width')  ? (float) $product->get_width()  : 0.0;
            $h = $product && method_exists($product, 'get_height') ? (float) $product->get_height() : 0.0;
            // A bundle/grouped line without its own dimensions is a heading, not a thing: its contents have their own lines.
            if (!($l > 0 && $w > 0 && $h > 0) && $product && method_exists($product, 'get_type')
                && in_array((string) $product->get_type(), self::CONTAINER_TYPES, true)) {
                continue;
            }
            $units[] = ($l > 0 && $w > 0 && $h > 0)
                ? ['l' => $to_cm($l), 'w' => $to_cm($w), 'h' => $to_cm($h), 'qty' => $qty]
                : ['l' => (float) $default['length'], 'w' => (float) $default['width'],
                   'h' => (float) $default['height'], 'qty' => $qty, 'measured' => false];
        }
        return $units;
    }
    /** Default parcel weight in kg when the products carry none; one value for all couriers. */
    public static function default_weight_kg(): float {
        return max(0.1, round((float) get_option('bgcouriers_default_weight_kg', 1.0), 3));
    }
    public static function shipment_contents(): string {
        $v = trim((string) get_option('bgcouriers_shipment_contents', ''));
        if ($v === '') { // pre-move installs configured these per courier
            $v = trim((string) get_option('bgcouriers_speedy_contents', ''))
                ?: trim((string) get_option('bgcouriers_econt_shipment_description', ''));
        }
        return $v !== '' ? $v : 'Goods';
    }
    /** @return string[] delivery methods enabled for the courier (drives checkout options). */
    public static function enabled_methods(string $courier): array {
        $out = [];
        foreach (self::METHODS as $m) {
            if (get_option('bgcouriers_' . $courier . '_' . $m . '_enabled', 'yes') === 'yes') { $out[] = $m; }
        }
        // Pruned to what the courier can do and has synced points for (e.g. Pigeon has no lockers).
        if (class_exists('BGCouriers_Couriers')) {
            $co = BGCouriers_Couriers::get($courier);
            if ($co) { $out = array_values(array_intersect($out, $co->available_methods())); }
        }
        return $out;
    }
    /** The country the shop ships from. */
    public static function home_country(): string { return 'BG'; }
    /** International delivery is unfinished and off; a site opts in with add_filter('bgcouriers_intl_enabled', '__return_true'). */
    public static function intl_enabled(): bool {
        return (bool) apply_filters('bgcouriers_intl_enabled', false);
    }
    /**
     * Countries this courier is configured to deliver to besides home, as ISO alpha-2: only while intl is on,
     * chosen by the merchant and still offered by the courier. $co is the courier when the caller already holds it.
     * @return string[]
     */
    public static function intl_countries(string $courier, $co = null): array {
        if (!self::intl_enabled()) { return []; }
        $saved = get_option('bgcouriers_' . $courier . '_intl_countries', []);
        if (!is_array($saved) || !$saved) { return []; }
        if (!$co && class_exists('BGCouriers_Couriers')) { $co = BGCouriers_Couriers::get($courier); }
        $can = ($co && method_exists($co, 'intl_countries')) ? $co->intl_countries() : [];
        $want = array_map('strtoupper', array_map('strval', $saved));
        return array_values(array_intersect($want, $can));
    }
    public static function ships_to(string $courier, string $country, $co = null): bool {
        $c = strtoupper(trim($country));
        return $c === '' || $c === self::home_country() || in_array($c, self::intl_countries($courier, $co), true);
    }
    /** '' is home: an order with no country yet is not abroad. */
    public static function is_intl(string $country): bool {
        $c = strtoupper(trim($country));
        return $c !== '' && $c !== self::home_country();
    }
    /** The plugin's own meta first, WooCommerce's shipping country as fallback, home as the last word. */
    public static function order_country(\WC_Order $order): string {
        $c = strtoupper(trim((string) $order->get_meta('_bgcouriers_country')));
        if ($c === '') { $c = strtoupper(trim((string) $order->get_shipping_country())); }
        if ($c === '') { $c = strtoupper(trim((string) $order->get_billing_country())); }
        return $c !== '' ? $c : self::home_country();
    }
    public static function delivery_countries(string $courier, $co = null): array {
        return array_merge([self::home_country()], self::intl_countries($courier, $co));
    }
    public static function autolabel(): array {
        return [
            'enabled' => get_option('bgcouriers_autolabel_enabled', 'no') === 'yes',
            'status'  => get_option('bgcouriers_autolabel_status', 'wc-processing'),
        ];
    }
    /** Whether the automatic waybill waits for the dispatch day; existing installs keep what they had (pin_autolabel_wait). */
    public static function autolabel_wait(): bool {
        return get_option('bgcouriers_autolabel_wait', 'yes') === 'yes';
    }
    /** '' follows the general setting; 'yes'/'no' on the courier's own tab overrule it. */
    public static function autolabel_for(string $courier): bool {
        $own = (string) get_option('bgcouriers_' . $courier . '_autolabel', '');
        if ($own === 'yes' || $own === 'no') { return $own === 'yes'; }
        // Nobody has said: a courier that comes for the parcel as soon as a waybill exists starts OFF (existing installs are pinned by BGCouriers_Plugin::pin_autolabel).
        $co = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        if ($co && method_exists($co, 'books_pickup_on_create') && $co->books_pickup_on_create()) { return false; }
        return self::autolabel()['enabled'];
    }
    public static function send_email(): bool {
        return get_option('bgcouriers_send_email', 'no') === 'yes';
    }
    public static function require_email(): bool {
        return get_option('bgcouriers_require_email', 'no') === 'yes';
    }
    public static function own_address_fields(): bool {
        return get_option('bgcouriers_own_address_fields', 'yes') === 'yes';
    }
    /** 'rate' (with the order review) or 'details' (under the customer details); classic checkout only. */
    public static function delivery_position(): string {
        $v = (string) get_option('bgcouriers_delivery_position', 'rate');
        return in_array($v, ['rate', 'details'], true) ? $v : 'rate';
    }
    public static function hide_shipping_calculator(): bool {
        return get_option('bgcouriers_hide_shipping_calc', 'yes') === 'yes';
    }
    public static function label_email(\WC_Order $order): string {
        return self::send_email() ? (string) $order->get_billing_email() : '';
    }
    public static function label_paper_size(string $courier = 'speedy'): string {
        $v = (string) get_option('bgcouriers_' . $courier . '_label_paper_size', 'A6');
        return in_array($v, ['A6', 'A4'], true) ? $v : 'A6';
    }
    public static function free_shipping_label(): string {
        return (string) get_option('bgcouriers_free_shipping_label', '');
    }
    /** 'cash_register' or 'ppp'. */
    public static function cod_fiscalization(): string {
        return get_option('bgcouriers_cod_fiscalization', 'cash_register') === 'ppp' ? 'ppp' : 'cash_register';
    }
    /** Off by default: the courier charges for it. */
    public static function return_voucher(string $courier): bool {
        return get_option('bgcouriers_' . $courier . '_return_voucher', 'no') === 'yes';
    }
    /** 'no' | 'open' | 'test'; one setting for the whole shop, honoured only by couriers that offer it. */
    public static function open_before_pay(): string {
        $v = (string) get_option('bgcouriers_open_before_pay', '');
        if ($v === '') {
            // Installs configured before this became one setting: keep doing exactly what they did.
            $legacy = (string) get_option('bgcouriers_speedy_open_before_pay', '');
            if ($legacy === '') {
                $legacy = get_option('bgcouriers_econt_pay_after_accept', 'no') === 'yes' ? 'open' : 'no';
            }
            $v = $legacy;
        }
        return in_array($v, ['no', 'open', 'test'], true) ? $v : 'no';
    }
    public static function courier_ppp_payout(string $courier): bool {
        // Default is the courier's own answer; the merchant's toggle overrides it either way.
        $co      = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        $default = ($co && method_exists($co, 'ppp_payout_by_default') && $co->ppp_payout_by_default()) ? 'yes' : 'no';
        return get_option('bgcouriers_' . $courier . '_ppp_payout', $default) === 'yes';
    }
    /** PPP is a Bulgarian postal money transfer and stops at the border: abroad, COD can only be plain cash. */
    public static function ppp_payout_reaches(string $courier, string $country): bool {
        return self::courier_ppp_payout($courier) && !self::is_intl($country);
    }
    /** @return string[] Delivery kinds this courier cannot collect cash on; filterable (bgcouriers_no_cod_methods). */
    public static function no_cod_methods(string $courier): array {
        $co   = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        $list = ($co && method_exists($co, 'no_cod_methods')) ? (array) $co->no_cod_methods() : [];
        return array_values(array_filter((array) apply_filters('bgcouriers_no_cod_methods', $list, $courier), 'is_string'));
    }
    /** COD allowed with this courier for this delivery kind: the merchant's fiscalisation and the courier's own limits. '' = kind unknown. */
    public static function cod_allowed_for(string $courier, string $method = ''): bool {
        if ($method !== '' && in_array($method, self::no_cod_methods($courier), true)) { return false; }
        return self::cod_fiscalization() === 'cash_register' || self::courier_ppp_payout($courier);
    }
    /** Delivery charged with the order (true) or paid to the courier at the door; drives the rate cost and the waybill payer. */
    public static function ship_in_total(string $courier): bool {
        // A courier that cannot bill the recipient (BOX NOW) is always charged with the order.
        $co = class_exists('BGCouriers_Couriers') ? BGCouriers_Couriers::get($courier) : null;
        if ($co && method_exists($co, 'recipient_can_pay_delivery') && !$co->recipient_can_pay_delivery()) { return true; }
        $v = (string) get_option('bgcouriers_' . $courier . '_ship_in_total', '');
        if ($v === '') {
            // Unset means OFF for a new install; existing installs were pinned by BGCouriers_Plugin::pin_who_pays_delivery(), and the old service_payer select is still honoured.
            return get_option('bgcouriers_' . $courier . '_service_payer', 'recipient') !== 'recipient';
        }
        return $v !== 'no';
    }
    public static function is_cod_gateway(string $gid, $gw): bool {
        return $gid === 'cod' || (is_object($gw) && is_a($gw, 'WC_Gateway_COD'));
    }
    public static function has_prepaid_gateway(): bool {
        // WC() can be null before WooCommerce has booted.
        $wc = function_exists('WC') ? WC() : null;
        if (!$wc || !$wc->payment_gateways()) { return true; }   // can't tell -> assume yes (don't over-restrict)
        foreach ($wc->payment_gateways()->payment_gateways() as $gid => $gw) {
            if (is_object($gw) && $gw->enabled === 'yes' && !self::is_cod_gateway((string) $gid, $gw)) { return true; }
        }
        return false;
    }
    /** @return array{level:string,msg:string}|null Why this courier cannot be used as configured; drives the red tab tint and the notice. */
    public static function courier_blocker(string $courier): ?array {
        // Sameday refuses recipient-paid delivery unless the contract covers it, and then creates no waybill at all; known only from its rejection flag.
        if ($courier === 'sameday'
            && get_option(BGCouriers_Sameday::NO_RECIPIENT_PAY, '') === 'yes'
            && !self::ship_in_total('sameday')) {
            return ['level' => 'error', 'msg' => __('Sameday does not support “the recipient pays the delivery” on this account, so NO waybill can be created while it is set that way. Turn on “Delivery in the order total” below, or ask Sameday to allow recipient payment on your contract.', 'bg-couriers')];
        }
        // Econt collecting COD under an agreement that does not match how the shop says it is paid out.
        if ($courier === 'econt' && get_option('bgcouriers_econt_cod_enabled', 'no') === 'yes') {
            $c = BGCouriers_Couriers::get('econt');
            if ($c && method_exists($c, 'payout_problems')) {
                foreach ($c->payout_problems() as $p) {
                    return ['level' => 'error', 'msg' => $p['msg'] . ' ' . $p['fix']];
                }
            }
        }
        // "Cannot appear at checkout" outranks "missing a setting"; then whatever the courier itself declares missing.
        $ppp = self::ppp_courier_notice($courier);
        if ($ppp && $ppp['level'] === 'error') { return $ppp; }

        $c = BGCouriers_Couriers::get($courier);
        if ($c && method_exists($c, 'enable_problems')) {
            $problems = $c->enable_problems();
            if ($problems) {
                $first = reset($problems);
                return ['level' => 'error', 'msg' => trim(
                    /* translators: 1: what is missing, 2: how to fix it */
                    sprintf(__('%1$s %2$s Until this is resolved the courier cannot create waybills.', 'bg-couriers'),
                        (string) ($first['msg'] ?? ''), (string) ($first['fix'] ?? ''))
                )];
            }
        }
        return $ppp;
    }
    public static function ppp_courier_notice(string $courier): ?array {
        if ($courier === '' || self::cod_fiscalization() !== 'ppp' || self::courier_ppp_payout($courier)) {
            return null;
        }
        if (self::has_prepaid_gateway()) {
            return ['level' => 'warning', 'msg' => __('This courier does not offer ППП. Because your Cash on delivery setting relies on the courier\'s ППП, it can be used here only for PREPAID orders - cash-on-delivery is turned off for it at checkout.', 'bg-couriers')];
        }
        return ['level' => 'error', 'msg' => __('This courier does not offer ППП and your shop has no prepaid (card / bank transfer) payment method, so it cannot take cash-on-delivery and will NOT appear at checkout. Add a prepaid payment method, or set Cash on delivery to "I have a cash register".', 'bg-couriers')];
    }
    public static function hidden_fields(): string {
        return (string) get_option('bgcouriers_hidden_fields', '');
    }
    /** @return string comma-joined selectors safe to print into a stylesheet; anything that could end the selector is dropped, not cleaned. */
    public static function hidden_field_selectors(): string {
        $out = [];
        foreach (explode(',', wp_strip_all_tags(self::hidden_fields())) as $sel) {
            $sel = trim($sel);
            if ($sel === '' || strlen($sel) > 200) { continue; }
            // tag / .class / #id / * to start, then selector syntax only: [attr="v"], :pseudo, combinators.
            if (!preg_match('/^[A-Za-z0-9_\-#.*:\[][A-Za-z0-9_\-#.*\[\]="\':()\s>+~]*$/', $sel)) { continue; }
            $out[] = $sel;
        }
        return implode(',', $out);
    }
    public static function emergency(): array {
        return [
            'phone'   => (string) get_option('bgcouriers_emergency_phone', ''),
            'message' => (string) get_option('bgcouriers_emergency_message', ''),
        ];
    }
    public static function method_order(string $courier): array {
        $raw = (string) get_option('bgcouriers_' . $courier . '_method_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach (self::METHODS as $m) { if (!in_array($m, $order, true)) { $order[] = $m; } }
        return array_values(array_intersect($order, self::METHODS));
    }
    public static function courier_order(): array {
        $all = array_keys(BGCouriers_Couriers::all());
        $raw = (string) get_option('bgcouriers_courier_order', '');
        $order = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
        foreach ($all as $c) { if (!in_array($c, $order, true)) { $order[] = $c; } }
        return array_values(array_intersect($order, $all));
    }
    /** Are the credentials SAVED? Says nothing about whether the courier is on. */
    public static function creds_present(string $courier = 'speedy'): bool {
        foreach (self::credential_fields($courier) as $f) {
            if (get_option('bgcouriers_' . $courier . '_' . $f, '') === '') { return false; }
        }
        return true;
    }
    /** @return string[] Credential fields this courier issues (Evropat issues one key); the pair when unregistered. */
    public static function credential_fields(string $courier): array {
        $c = BGCouriers_Couriers::get($courier);
        return ($c && method_exists($c, 'credential_fields')) ? $c->credential_fields() : ['username', 'password'];
    }
}
