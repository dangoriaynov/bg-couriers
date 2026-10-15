<?php
defined('ABSPATH') || exit;

class BGCouriers_Pricing {
    /** The parcel the checkout is pricing, from the shipping packages (not the cart total, which counts virtual items). */
    public static function cart_parcel(): array {
        $store_weight = 0.0;
        $has = false;
        if (function_exists('WC') && WC() && WC()->cart) {
            foreach ((array) WC()->cart->get_shipping_packages() as $pkg) {
                $store_weight += (float) ($pkg['contents_weight'] ?? 0);
                $has = $has || !empty($pkg['contents']);
            }
        }
        return self::weigh($store_weight, $has);
    }

    public static function package_parcel(array $package): array {
        return self::weigh((float) ($package['contents_weight'] ?? 0), !empty($package['contents']));
    }

    /** A package with contents and no weight falls to the shop's default weight, as the label does; otherwise the 0.1 kg floor. */
    private static function weigh(float $store_weight, bool $has_contents): array {
        if ($store_weight <= 0 && $has_contents && class_exists('BGCouriers_Settings')) {
            return BGCouriers_Packer::from_weight(BGCouriers_Settings::default_weight_kg());
        }
        return BGCouriers_Packer::from_store_weight($store_weight);
    }

    /** A net price as the customer sees it printed: every quote here is net, WooCommerce adds the shipping tax on top. */
    public static function display_price(float $net): float {
        // A shop that adds no shipping tax has nothing to show a price inclusive of.
        if ($net <= 0 || !self::wc_adds_shipping_tax() || get_option('woocommerce_tax_display_cart') !== 'incl') { return $net; }
        return round($net + array_sum(WC_Tax::calc_shipping_tax($net, WC_Tax::get_shipping_tax_rates())), 2);
    }

    /** What the customer hands the courier at the door: gross, whatever the shop's tax display setting says. */
    public static function door_price(BGCouriers_Quote $q): float {
        if ($q->price <= 0) { return 0.0; }
        if ($q->tax > 0) { return round($q->total(), 2); }
        if (!class_exists('WC_Tax')) { return round($q->price, 2); }
        return round($q->price + array_sum(WC_Tax::calc_shipping_tax($q->price, WC_Tax::get_shipping_tax_rates())), 2);
    }

    private static function wc_adds_shipping_tax(): bool {
        if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) { return false; }
        return class_exists('WC_Tax') && !empty(WC_Tax::get_shipping_tax_rates());
    }

    /** The VAT a courier adds to a NET price. Only for a courier whose figure is documented as net: most report a gross total with no tax field. */
    public static function courier_tax(float $net): float {
        if ($net <= 0) { return 0.0; }
        if (class_exists('WC_Tax')) {
            $rates = WC_Tax::get_shipping_tax_rates();
            if (!empty($rates)) { return round((float) array_sum(WC_Tax::calc_shipping_tax($net, $rates)), 2); }
        }
        return round($net * (float) apply_filters('bgcouriers_courier_vat_rate', 20.0) / 100, 2);
    }

    /** The net price where WooCommerce adds the shipping tax, the gross one where it does not (tax calculation off). */
    public static function rate_cost(BGCouriers_Quote $q): float {
        if ($q->price <= 0) { return 0.0; }
        return round(self::wc_adds_shipping_tax() ? $q->price : $q->total(), 2);
    }

    /** The paid figure for the map from a LIVE quote (one that carries its own tax); null = nothing to show. */
    public static function map_office_price(?BGCouriers_Quote $q, bool $in_total): ?float {
        if (!$q) { return null; }
        $v = $in_total ? self::display_price(self::rate_cost($q)) : self::door_price($q);
        return $v > 0 ? $v : null;
    }

    /** @return array{p:float,t:float,c:string} */
    public static function quote_to_cache(BGCouriers_Quote $q): array {
        return ['p' => $q->price, 't' => $q->tax, 'c' => $q->currency];
    }

    public static function quote_from_cache(array $c, string $currency): BGCouriers_Quote {
        return new BGCouriers_Quote((float) $c['p'], (float) ($c['t'] ?? 0), (string) ($c['c'] ?? $currency), 'cached');
    }

    /**
     * A gross courier price (Evropat quotes with VAT) split into net and tax with the rates WooCommerce will re-add.
     * @return array{0:float,1:float} [net, tax]
     */
    public static function split_gross(float $gross): array {
        if ($gross <= 0 || !class_exists('WC_Tax')) { return [round($gross, 2), 0.0]; }
        $tax = (float) array_sum(WC_Tax::calc_inclusive_tax($gross, WC_Tax::get_shipping_tax_rates()));
        // Tax derived from the rounded net so the two halves add up to the gross exactly.
        $net = round($gross - $tax, 2);
        return [$net, round($gross - $net, 2)];
    }

    /** The cash the courier would collect for this basket, or 0: the COD fee is in the quoted price. Basis is the goods total, not goods + delivery. */
    public static function cart_cod_amount(string $courier = '', string $method = ''): float {
        if (!function_exists('WC') || !WC() || !WC()->cart || !WC()->session) { return 0.0; }
        if ((string) WC()->session->get('chosen_payment_method', '') !== 'cod') { return 0.0; }
        // The session still reads 'cod' during the recalculation that removes the gateway for this courier.
        if ($courier !== '' && !BGCouriers_Settings::cod_allowed_for($courier, $method)) { return 0.0; }
        $goods = (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
        return max(0.0, round($goods, 2));
    }

    /**
     * The office to quote against: the chosen one, else a representative office of the city or country.
     * @return array{office_id:int, site_id:int}
     */
    public static function resolve_office(string $courier, string $method, int $site_id, int $office, string $country = ''): array {
        if ($office <= 0 && in_array($method, ['office', 'automat'], true)) {
            $rep = $site_id > 0 ? BGCouriers_Nomenclature::offices($courier, $site_id, $method) : [];
            if (!empty($rep[0]['office_id'])) {
                $office = (int) $rep[0]['office_id'];
            } else {
                // In the destination country: '' would mean "any country" to the repository.
                $first = BGCouriers_Nomenclature::first_office($courier, $method, self::country_or_home($country));
                if (!empty($first['office_id'])) { $office = (int) $first['office_id']; $site_id = (int) $first['city_id']; }
            }
        }
        return ['office_id' => $office, 'site_id' => $site_id];
    }

    /** '' means the shop's own country, never "any country". */
    private static function country_or_home(string $country): string {
        $c = strtoupper(trim($country));
        return $c !== '' ? $c : BGCouriers_Settings::home_country();
    }

    /** Where the package is going (ISO alpha-2): the delivery box, then the package destination, then the home country. */
    public static function destination_country(array $package = []): string {
        $s = (function_exists('WC') && WC()->session) ? WC()->session : null;
        $c = $s ? strtoupper(trim((string) $s->get('bgcouriers_country', ''))) : '';
        if ($c === '') { $c = strtoupper(trim((string) ($package['destination']['country'] ?? ''))); }
        return $c !== '' ? $c : BGCouriers_Settings::home_country();
    }

    /**
     * The selection to price THIS courier against: ids are per courier, so another courier's are never reused.
     * @return array{method:string, site_id:int, office_id:int, country:string}
     */
    public static function selection_for(string $courier_id): array {
        $default = BGCouriers_Settings::enabled_methods($courier_id)[0] ?? 'office';
        $s = (function_exists('WC') && WC()->session) ? WC()->session : null;
        if (!$s) { return ['method' => $default, 'site_id' => 0, 'office_id' => 0, 'country' => '']; }
        $country = self::destination_country();
        if ((string) $s->get('bgcouriers_selection_courier', '') === $courier_id) {
            return [
                'method'    => (string) $s->get('bgcouriers_method', '') ?: $default,
                'site_id'   => (int) $s->get('bgcouriers_site_id', 0),
                'office_id' => (int) $s->get('bgcouriers_office_id', 0),
                'country'   => $country,
            ];
        }
        $site_id  = 0;
        $postcode = (string) $s->get('bgcouriers_post_code', '');
        if ($postcode !== '') {
            // With the post code alone, "1000" is Sofia and Bucharest at once; the country decides which.
            $city = BGCouriers_Nomenclature::city_by_postcode($courier_id, $postcode, $country);
            if ($city) { $site_id = (int) $city['city_id']; }
        }
        // What the customer last chose in this courier, so its row is not re-quoted for the default method.
        $mine = (array) $s->get('bgcouriers_sel_by_courier', []);
        if (isset($mine[$courier_id]) && is_array($mine[$courier_id])) {
            $m = $mine[$courier_id];
            $method = (string) ($m['method'] ?? '');
            if (in_array($method, BGCouriers_Settings::enabled_methods($courier_id), true)) {
                // A remembered city id may be stale; fall back to the post-code one.
                $own = (int) ($m['site_id'] ?? 0);
                return [
                    'method'    => $method,
                    'site_id'   => $own > 0 ? $own : $site_id,
                    'office_id' => $own > 0 ? (int) ($m['office_id'] ?? 0) : 0,
                    'country'   => $country,
                ];
            }
        }
        return ['method' => $default, 'site_id' => $site_id, 'office_id' => 0, 'country' => $country];
    }

    /** Price for the checkout row: the cached reference before a city is picked, the live quote after. */
    public static function checkout_quote(BGCouriers_Courier_Interface $courier, string $method, int $site_id, int $office, array $packed, string $currency, string $country = ''): BGCouriers_Quote {
        // Abroad it is a live price or nothing: every fallback below is a domestic number.
        $abroad = BGCouriers_Settings::is_intl($country);
        if (!$abroad && BGCouriers_Settings::price_mode($courier->id(), $method) === 'fixed') {
            $price = (float) BGCouriers_Settings::method_config($courier->id(), $method)['price'];
            return new BGCouriers_Quote($price > 0 ? round($price, 2) : 6.99, 0.0, $currency, 'fixed');
        }
        if ($site_id <= 0) {
            $ref = self::reference_for_weight($courier, $method, $packed, $currency, $country);
            if ($ref !== null) { return new BGCouriers_Quote(round($ref['p'], 2), round($ref['t'], 2), $currency, 'reference'); }
            if (!$abroad) {
                // Last resort, a bare net number with no tax to carry.
                $est = self::estimate($courier->id(), $method);
                if ($est !== null) { return new BGCouriers_Quote(round($est, 2), 0.0, $currency, 'reference'); }
            }
        }
        // Cached per courier, method, city, weight and COD: COD changes the price.
        $cod  = self::cart_cod_amount($courier->id(), $method);
        $w    = round((float) ($packed['weight_kg'] ?? 0), 2);
        // Country joins the key only abroad (domestic keys unchanged); currency always, or a shop that changed currency reads old figures.
        $tkey = 'bgcouriers_q_' . $courier->id() . '_' . $method . '_' . $site_id . '_'
              . str_replace('.', '', (string) $w) . ($cod > 0 ? '_cod' . str_replace('.', '', (string) round($cod, 2)) : '')
              . ($abroad ? '_' . strtolower($country) : '') . '_' . strtolower($currency);
        $cached = get_transient($tkey);
        // Checked even though the key carries it, so a key built another way cannot read a price in another unit.
        if (is_array($cached) && isset($cached['p'])
            && (string) ($cached['c'] ?? $currency) === $currency) {
            return self::quote_from_cache($cached, $currency);
        }
        $res = self::resolve_office($courier->id(), $method, $site_id, $office, $country);
        $shipment = array_merge($packed, [
            'method' => $method, 'site_id' => $res['site_id'], 'office_id' => $res['office_id'],
            'cod_amount' => $cod, 'currency' => $currency, 'country' => $country,
        ]);
        $q = self::quote($courier, $shipment);
        if ($q->source === 'live') { set_transient($tkey, self::quote_to_cache($q), 3 * HOUR_IN_SECONDS); }
        return $q;
    }

    /** A failed quote slower than this rests the courier; the trigger is the time, not the kind of error. */
    const SLOW_QUOTE_SECONDS = 5.0;

    /** How long a slow courier is left alone, shop-wide. */
    const SLOW_QUOTE_REST = 300;

    private static function resting(string $courier): bool {
        return (bool) get_transient('bgcouriers_slow_' . $courier);
    }

    private static function slow_seconds(): float {
        return (float) apply_filters('bgcouriers_slow_quote_seconds', self::SLOW_QUOTE_SECONDS);
    }

    /** A slow failure means the next customer gets the fallback price straight away. */
    private static function maybe_rest(string $courier, float $took): void {
        if ($took < self::slow_seconds()) { return; }
        $rest = self::slow_rest();
        set_transient('bgcouriers_slow_' . $courier, 1, $rest);
        BGCouriers_Logger::debug('a slow quote - this courier is left alone for a while', [
            'courier' => $courier, 'seconds' => round($took, 1), 'rest' => $rest]);
    }

    private static function slow_rest(): int {
        return max(1, (int) apply_filters('bgcouriers_slow_quote_rest', self::SLOW_QUOTE_REST));
    }

    private static function wake(string $courier): void {
        if (self::resting($courier)) { delete_transient('bgcouriers_slow_' . $courier); }
    }

    public static function quote(BGCouriers_Courier_Interface $courier, array $shipment): BGCouriers_Quote {
        $method  = (string) ($shipment['method'] ?? 'address');
        $mode    = BGCouriers_Settings::price_mode($courier->id(), $method);
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $default = (float) BGCouriers_Settings::method_config($courier->id(), $method)['price'];
        $abroad  = BGCouriers_Settings::is_intl((string) ($shipment['country'] ?? ''));
        if ($mode !== 'fixed' && in_array('live_quote', $courier->capabilities(), true)
            && ($abroad || !self::resting($courier->id()))) {
            $started = microtime(true);
            try {
                $q = $courier->quote($shipment);
                self::wake($courier->id());
                return $q;
            }
            // \Throwable, not \Exception: a TypeError in an adapter must fall through to the fallback price, not fatal the checkout.
            catch (\Throwable $e) {
                self::maybe_rest($courier->id(), microtime(true) - $started);
                BGCouriers_Logger::debug('live quote failed -> fallback', ['courier' => $courier->id()]);
                // Abroad there is nothing to fall back to.
                if ($abroad) { throw $e; }
            }
        }
        if ($abroad) {
            throw new BGCouriers_Api_Exception(esc_html(sprintf(
                /* translators: 1: courier name, 2: country code. */
                __('%1$s: no live price for %2$s.', 'bg-couriers'),
                $courier->id(), (string) ($shipment['country'] ?? ''))));
        }
        if (($mode === 'fixed' || $mode === 'fallback') && $default > 0) {
            return new BGCouriers_Quote(round($default, 2), 0.0, $store, 'fixed');
        }
        // The zone the parcel is going to, not the one shown before a city is known.
        $zone   = BGCouriers_Zones::for_shipment($courier->id(), $shipment);
        $cached = BGCouriers_Rates::get($courier->id(), $method, $zone, $store);
        if ($cached !== null) { return new BGCouriers_Quote($cached, 0.0, $store, 'standard'); }
        $amount = $default > 0 ? $default : 6.99;
        return new BGCouriers_Quote(round($amount, 2), 0.0, $store, 'flat');
    }

    /** Cart weight rounded UP to the next half kilo: never quote for less than the parcel weighs. */
    public static function reference_weight(float $weight_kg): float {
        if ($weight_kg <= 0) { return 0.5; }
        return max(0.5, ceil($weight_kg * 2) / 2);
    }

    /** Transient key for a reference price; weight and zone are in it, the COUNTRY zone keeps the bare key. */
    public static function reference_key(string $courier, string $method, float $weight_kg, float $cod = 0.0, string $country = '', string $currency = '', string $zone = BGCouriers_Zones::DEFAULT_ZONE): string {
        return 'bgcouriers_ref_' . $courier . '_' . $method . '_' . str_replace('.', '', (string) self::reference_weight($weight_kg))
             . ($zone === BGCouriers_Zones::COUNTRY ? '' : '_' . $zone)
             . ($cod > 0 ? '_cod' . str_replace('.', '', (string) round($cod, 2)) : '')
             . (BGCouriers_Settings::is_intl($country) ? '_' . strtolower($country) : '')
             . ($currency !== '' ? '_' . strtolower($currency) : '');
    }

    /** A live reference price for the cart's actual weight, before a city is chosen; null when the courier cannot be quoted. */
    private static function reference_for_weight(BGCouriers_Courier_Interface $courier, string $method, array $packed, string $currency, string $country = '', string $zone = BGCouriers_Zones::DEFAULT_ZONE): ?array {
        $w    = self::reference_weight((float) ($packed['weight_kg'] ?? 0));
        $cod  = self::cart_cod_amount($courier->id(), $method);
        $tkey = self::reference_key($courier->id(), $method, $w, $cod, $country, $currency, $zone);
        $hit  = get_transient($tkey);
        if (is_array($hit) && isset($hit['p'])) { return ['p' => (float) $hit['p'], 't' => (float) ($hit['t'] ?? 0)]; }
        if (!class_exists('BGCouriers_Sync')) { return null; }
        $ref = BGCouriers_Sync::reference_shipment($courier->id(), $method, $country, $zone);
        if (!$ref) { return null; }
        $shipment = array_merge($ref, [
            'method'     => $method,
            'weight_kg'  => $w,
            'country'    => (string) ($ref['country'] ?? $country),
            'length_cm'  => $packed['length_cm'] ?? $ref['length_cm'],
            'width_cm'   => $packed['width_cm']  ?? $ref['width_cm'],
            'height_cm'  => $packed['height_cm'] ?? $ref['height_cm'],
            'currency'   => $currency,
            'cod_amount' => $cod,
        ]);
        try {
            $q = self::quote($courier, $shipment);
        } catch (\Throwable $e) {
            return null;
        }
        if ($q->source !== 'live') { return null; }
        // Keep the net price AND the courier's tax: dropping either skews the reference by the VAT.
        $entry = ['p' => $q->price, 't' => $q->tax];
        set_transient($tkey, $entry, 3 * HOUR_IN_SECONDS);
        return $entry;
    }

    /** The shipment an ORDER represents, read from its meta: by label time the session is gone. */
    public static function order_shipment(\WC_Order $order): ?array {
        $courier = (string) $order->get_meta('_bgcouriers_courier');
        if ($courier === '') { return null; }
        $dims   = BGCouriers_Settings::box_dims($order);
        $method = (string) $order->get_meta('_bgcouriers_method');
        if ($method === '') { $method = BGCouriers_Settings::enabled_methods($courier)[0] ?? 'office'; }
        return [
            'method'       => $method,
            'site_id'      => (int) $order->get_meta('_bgcouriers_site_id'),
            'office_id'    => (int) $order->get_meta('_bgcouriers_office_id'),
            'office_code'  => '',
            'post_code'    => (string) $order->get_meta('_bgcouriers_post_code'),
            'country'      => BGCouriers_Settings::order_country($order),
            'currency'     => $order->get_currency(),
            'weight_kg'    => BGCouriers_Abstract_Courier::order_weight_kg($order),
            'length_cm'    => (int) $dims['length'],
            'width_cm'     => (int) $dims['width'],
            'height_cm'    => (int) $dims['height'],
            // COD changes the price at every courier.
            'cod_amount'   => self::order_cod_amount($order),
            'street_name'  => (string) $order->get_meta('_bgcouriers_street_name'),
            'street_type'  => (string) $order->get_meta('_bgcouriers_street_type'),
            'street_no'    => (string) $order->get_meta('_bgcouriers_street_no'),
            'complex'      => (string) $order->get_meta('_bgcouriers_complex'),
            'block'        => (string) $order->get_meta('_bgcouriers_block'),
            'entrance'     => (string) $order->get_meta('_bgcouriers_entrance'),
            'floor'        => (string) $order->get_meta('_bgcouriers_floor'),
            'apartment'    => (string) $order->get_meta('_bgcouriers_apartment'),
            'address_note' => (string) $order->get_meta('_bgcouriers_address_note'),
        ];
    }

    public static function order_cod_amount(\WC_Order $order): float {
        $gid = (string) $order->get_payment_method();
        $gws = function_exists('WC') && WC() && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        if (!BGCouriers_Settings::is_cod_gateway($gid, $gws[$gid] ?? null)) { return 0.0; }
        return max(0.0, round((float) $order->get_total(), 2));
    }

    public static function estimate(string $courier, string $method, string $zone = BGCouriers_Zones::DEFAULT_ZONE): ?float {
        $mc = BGCouriers_Settings::method_config($courier, $method);
        if (BGCouriers_Settings::price_mode($courier, $method) === 'fixed') {
            return $mc['price'] > 0 ? (float) $mc['price'] : null;
        }
        $store  = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $cached = BGCouriers_Rates::get($courier, $method, $zone, $store);
        if ($cached !== null) { return (float) $cached; }
        return $mc['price'] > 0 ? (float) $mc['price'] : null;
    }
}
