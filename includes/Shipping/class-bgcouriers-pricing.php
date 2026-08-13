<?php
defined('ABSPATH') || exit;

class BGCouriers_Pricing {
    /**
     * Resolve the office to quote against, given the customer's session selection.
     * office/automat with a chosen office → use it. Otherwise quote a representative office (of the
     * chosen city, or - when no city is picked, or the city has none of that type - the first such
     * office anywhere) so the price is a live quote at the REAL cart weight. The checkout greys out a
     * delivery option the chosen city lacks, so the customer never actually selects that combination.
     *
     * @return array{office_id:int, site_id:int}
     */
    public static function resolve_office(string $courier, string $method, int $site_id, int $office): array {
        if ($office <= 0 && in_array($method, ['office', 'automat'], true)) {
            $rep = $site_id > 0 ? BGCouriers_Nomenclature::offices($courier, $site_id, $method) : [];
            if (!empty($rep[0]['office_id'])) {
                $office = (int) $rep[0]['office_id'];
            } else {
                $first = BGCouriers_Nomenclature::first_office($courier, $method);
                if (!empty($first['office_id'])) { $office = (int) $first['office_id']; $site_id = (int) $first['city_id']; }
            }
        }
        return ['office_id' => $office, 'site_id' => $site_id];
    }

    /**
     * The checkout selection to price THIS courier against. The session holds ONE selection, tagged with the
     * courier it was made for (bgcouriers_selection_courier). City ids and office ids are per-courier (each courier
     * has its own nomenclature), so another courier's ids must never be reused - doing so quotes a courier
     * against a foreign city/office and the price jumps when it later becomes the active selection.
     *  - active (selected) courier -> its own stored method / city / office;
     *  - every other listed courier -> the SAME destination city resolved in ITS OWN nomenclature via the
     *    shared postcode (office 0 = a representative office), so its listed price is stable and correct.
     *
     * @return array{method:string, site_id:int, office_id:int}
     */
    public static function selection_for(string $courier_id): array {
        $default = BGCouriers_Settings::enabled_methods($courier_id)[0] ?? 'office';
        $s = (function_exists('WC') && WC()->session) ? WC()->session : null;
        if (!$s) { return ['method' => $default, 'site_id' => 0, 'office_id' => 0]; }
        if ((string) $s->get('bgcouriers_selection_courier', '') === $courier_id) {
            return [
                'method'    => (string) $s->get('bgcouriers_method', '') ?: $default,
                'site_id'   => (int) $s->get('bgcouriers_site_id', 0),
                'office_id' => (int) $s->get('bgcouriers_office_id', 0),
            ];
        }
        $site_id  = 0;
        $postcode = (string) $s->get('bgcouriers_post_code', '');
        if ($postcode !== '') {
            $city = BGCouriers_Nomenclature::city_by_postcode($courier_id, $postcode);
            if ($city) { $site_id = (int) $city['city_id']; }
        }
        return ['method' => $default, 'site_id' => $site_id, 'office_id' => 0];
    }

    /**
     * Price for the checkout shipping row. Before the customer picks a city we return the FAST cached daily
     * reference (no API call) - so switching couriers stays snappy and the customer can start entering the
     * address immediately. Once a real city is chosen we do the exact live quote against the resolved office.
     */
    public static function checkout_quote(BGCouriers_Courier_Interface $courier, string $method, int $site_id, int $office, array $packed, string $currency): BGCouriers_Quote {
        // 'fixed' mode: a predefined flat price, regardless of address - never call the API or cache.
        if (BGCouriers_Settings::price_mode($courier->id(), $method) === 'fixed') {
            $price = (float) BGCouriers_Settings::method_config($courier->id(), $method)['price'];
            return new BGCouriers_Quote($price > 0 ? round($price, 2) : 6.99, 0.0, $currency, 'fixed');
        }
        if ($site_id <= 0) {
            $est = self::reference_for_weight($courier, $method, $packed, $currency);
            if ($est !== null) { return new BGCouriers_Quote(round($est, 2), 0.0, $currency, 'reference'); }
            $est = self::estimate($courier->id(), $method);
            if ($est !== null) { return new BGCouriers_Quote(round($est, 2), 0.0, $currency, 'reference'); }
        }
        // Cache the live quote per courier+method+city+weight. The city now carries across couriers, so
        // without this every switch would re-hit the courier API; with it, a seen combo is instant.
        $w    = round((float) ($packed['weight_kg'] ?? 0), 2);
        $tkey = 'bgcouriers_q_' . $courier->id() . '_' . $method . '_' . $site_id . '_' . str_replace('.', '', (string) $w);
        $cached = get_transient($tkey);
        if (is_array($cached) && isset($cached['p'])) {
            return new BGCouriers_Quote((float) $cached['p'], 0.0, (string) ($cached['c'] ?? $currency), 'cached');
        }
        $res = self::resolve_office($courier->id(), $method, $site_id, $office);
        $shipment = array_merge($packed, [
            'method' => $method, 'site_id' => $res['site_id'], 'office_id' => $res['office_id'],
            'cod_amount' => 0.0, 'currency' => $currency,
        ]);
        $q = self::quote($courier, $shipment);
        if ($q->source === 'live') { set_transient($tkey, ['p' => $q->price, 'c' => $q->currency], 3 * HOUR_IN_SECONDS); }
        return $q;
    }

    public static function quote(BGCouriers_Courier_Interface $courier, array $shipment): BGCouriers_Quote {
        $method  = (string) ($shipment['method'] ?? 'address');
        $mode    = BGCouriers_Settings::price_mode($courier->id(), $method);
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $default = (float) BGCouriers_Settings::method_config($courier->id(), $method)['price'];
        // Live API for 'live' and 'fallback' (not 'fixed').
        if ($mode !== 'fixed' && in_array('live_quote', $courier->capabilities(), true)) {
            try { return $courier->quote($shipment); }
            catch (\Exception $e) { BGCouriers_Logger::debug('live quote failed -> fallback', ['courier' => $courier->id()]); }
        }
        // No live price (fixed mode, or the API failed). 'fixed'/'fallback' prefer the configured price;
        // 'live' prefers the daily cached reference. All amounts are already in the store currency.
        if (($mode === 'fixed' || $mode === 'fallback') && $default > 0) {
            return new BGCouriers_Quote(round($default, 2), 0.0, $store, 'fixed');
        }
        $cached = BGCouriers_Rates::get($courier->id(), $method);
        if ($cached !== null) { return new BGCouriers_Quote($cached, 0.0, $store, 'standard'); }
        $amount = $default > 0 ? $default : 6.99;
        return new BGCouriers_Quote(round($amount, 2), 0.0, $store, 'flat');
    }

    /**
     * A no-API price estimate for a courier+method (the cart-page estimate): the cached daily reference,
     * else the configured default price, else null (no estimate available). Store currency, net.
     */
    /**
     * The weight a reference price is quoted for: the cart's own, rounded UP to the next half kilo.
     *
     * Up, never down - a price quoted for less than the parcel weighs understates what the customer
     * will pay, which is the failure this whole path exists to stop. Bucketed, because a reference does
     * not need to tell 3.01 kg from 3.04 kg and a key per exact gram would miss the cache on nearly
     * every cart, putting a live courier call in front of a page load.
     *
     * @param float $weight_kg Cart weight.
     * @return float The bucket, never below half a kilo.
     */
    public static function reference_weight(float $weight_kg): float {
        if ($weight_kg <= 0) { return 0.5; }
        return max(0.5, ceil($weight_kg * 2) / 2);
    }

    /** Transient key for a reference price. Carries the weight, or a heavy cart reads a light one's price. */
    public static function reference_key(string $courier, string $method, float $weight_kg): string {
        return 'bgcouriers_ref_' . $courier . '_' . $method . '_' . str_replace('.', '', (string) self::reference_weight($weight_kg));
    }

    /**
     * A reference price for the cart's ACTUAL weight, before any city is chosen.
     *
     * What used to be shown here was a daily figure quoted for a hardcoded 2 kg parcel whatever the
     * cart held (see BGCouriers_Sync::reference_shipment), so a 10 kg order advertised the 2 kg price
     * until the customer picked a city. Quoting the same reference route with the real weight costs one
     * live call per courier, method and weight bucket, cached for three hours.
     *
     * Returns null when the courier cannot be quoted at all, so the caller falls back to the old daily
     * figure rather than showing nothing.
     */
    private static function reference_for_weight(BGCouriers_Courier_Interface $courier, string $method, array $packed, string $currency): ?float {
        $w    = self::reference_weight((float) ($packed['weight_kg'] ?? 0));
        $tkey = self::reference_key($courier->id(), $method, $w);
        $hit  = get_transient($tkey);
        if (is_array($hit) && isset($hit['p'])) { return (float) $hit['p']; }
        if (!class_exists('BGCouriers_Sync')) { return null; }
        $ref = BGCouriers_Sync::reference_shipment($courier->id(), $method);
        if (!$ref) { return null; }
        // The route stays the reference one - there is no destination yet, that is the whole situation -
        // and only the parcel becomes the customer's: their weight, and their box, since a courier
        // prices volume too. Nothing about where it is going comes from the cart.
        $shipment = array_merge($ref, [
            'method'     => $method,
            'weight_kg'  => $w,
            'length_cm'  => $packed['length_cm'] ?? $ref['length_cm'],
            'width_cm'   => $packed['width_cm']  ?? $ref['width_cm'],
            'height_cm'  => $packed['height_cm'] ?? $ref['height_cm'],
            'currency'   => $currency,
            'cod_amount' => 0.0,
        ]);
        try {
            $q = self::quote($courier, $shipment);
        } catch (\Exception $e) {
            return null;
        }
        if ($q->source !== 'live') { return null; }
        set_transient($tkey, ['p' => $q->total()], 3 * HOUR_IN_SECONDS);
        return $q->total();
    }

    public static function estimate(string $courier, string $method): ?float {
        $mc = BGCouriers_Settings::method_config($courier, $method);
        // 'fixed' mode shows its fixed price everywhere; otherwise the daily cached reference, then the default.
        if (BGCouriers_Settings::price_mode($courier, $method) === 'fixed') {
            return $mc['price'] > 0 ? (float) $mc['price'] : null;
        }
        $cached = BGCouriers_Rates::get($courier, $method);
        if ($cached !== null) { return (float) $cached; }
        return $mc['price'] > 0 ? (float) $mc['price'] : null;
    }
}
