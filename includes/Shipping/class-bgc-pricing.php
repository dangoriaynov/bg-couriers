<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Pricing {
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
            $rep = $site_id > 0 ? BGC_Nomenclature::offices($courier, $site_id, $method) : [];
            if (!empty($rep[0]['office_id'])) {
                $office = (int) $rep[0]['office_id'];
            } else {
                $first = BGC_Nomenclature::first_office($courier, $method);
                if (!empty($first['office_id'])) { $office = (int) $first['office_id']; $site_id = (int) $first['city_id']; }
            }
        }
        return ['office_id' => $office, 'site_id' => $site_id];
    }

    /**
     * Price for the checkout shipping row. Before the customer picks a city we return the FAST cached daily
     * reference (no API call) - so switching couriers stays snappy and the customer can start entering the
     * address immediately. Once a real city is chosen we do the exact live quote against the resolved office.
     */
    public static function checkout_quote(BGC_Courier_Interface $courier, string $method, int $site_id, int $office, array $packed, string $currency): BGC_Quote {
        // 'fixed' mode: a predefined flat price, regardless of address - never call the API or cache.
        if (BGC_Settings::price_mode($courier->id(), $method) === 'fixed') {
            $price = (float) BGC_Settings::method_config($courier->id(), $method)['price'];
            return new BGC_Quote($price > 0 ? round($price, 2) : 6.99, 0.0, $currency, 'fixed');
        }
        if ($site_id <= 0) {
            $est = self::estimate($courier->id(), $method);
            if ($est !== null) { return new BGC_Quote(round($est, 2), 0.0, $currency, 'reference'); }
        }
        // Cache the live quote per courier+method+city+weight. The city now carries across couriers, so
        // without this every switch would re-hit the courier API; with it, a seen combo is instant.
        $w    = round((float) ($packed['weight_kg'] ?? 0), 2);
        $tkey = 'bgc_q_' . $courier->id() . '_' . $method . '_' . $site_id . '_' . str_replace('.', '', (string) $w);
        $cached = get_transient($tkey);
        if (is_array($cached) && isset($cached['p'])) {
            return new BGC_Quote((float) $cached['p'], 0.0, (string) ($cached['c'] ?? $currency), 'cached');
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

    public static function quote(BGC_Courier_Interface $courier, array $shipment): BGC_Quote {
        $method  = (string) ($shipment['method'] ?? 'address');
        $mode    = BGC_Settings::price_mode($courier->id(), $method);
        $store   = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'BGN';
        $default = (float) BGC_Settings::method_config($courier->id(), $method)['price'];
        // Live API for 'live' and 'fallback' (not 'fixed').
        if ($mode !== 'fixed' && in_array('live_quote', $courier->capabilities(), true)) {
            try { return $courier->quote($shipment); }
            catch (\Exception $e) { BGC_Logger::debug('live quote failed -> fallback', ['courier' => $courier->id()]); }
        }
        // No live price (fixed mode, or the API failed). 'fixed'/'fallback' prefer the configured price;
        // 'live' prefers the daily cached reference. All amounts are already in the store currency.
        if (($mode === 'fixed' || $mode === 'fallback') && $default > 0) {
            return new BGC_Quote(round($default, 2), 0.0, $store, 'fixed');
        }
        $cached = BGC_Rates::get($courier->id(), $method);
        if ($cached !== null) { return new BGC_Quote($cached, 0.0, $store, 'standard'); }
        $amount = $default > 0 ? $default : 6.99;
        return new BGC_Quote(round($amount, 2), 0.0, $store, 'flat');
    }

    /**
     * A no-API price estimate for a courier+method (the cart-page estimate): the cached daily reference,
     * else the configured default price, else null (no estimate available). Store currency, net.
     */
    public static function estimate(string $courier, string $method): ?float {
        $mc = BGC_Settings::method_config($courier, $method);
        // 'fixed' mode shows its fixed price everywhere; otherwise the daily cached reference, then the default.
        if (BGC_Settings::price_mode($courier, $method) === 'fixed') {
            return $mc['price'] > 0 ? (float) $mc['price'] : null;
        }
        $cached = BGC_Rates::get($courier, $method);
        if ($cached !== null) { return (float) $cached; }
        return $mc['price'] > 0 ? (float) $mc['price'] : null;
    }
}
