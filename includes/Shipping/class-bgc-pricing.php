<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Pricing {
    /**
     * Resolve the office to quote against, given the customer's session selection.
     * office/automat with a chosen office → use it. Otherwise quote a representative office (of the
     * chosen city, or — when no city is picked, or the city has none of that type — the first such
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
     * reference (no API call) — so switching couriers stays snappy and the customer can start entering the
     * address immediately. Once a real city is chosen we do the exact live quote against the resolved office.
     */
    public static function checkout_quote(BGC_Courier_Interface $courier, string $method, int $site_id, int $office, array $packed, string $currency): BGC_Quote {
        if ($site_id <= 0) {
            $est = self::estimate($courier->id(), $method);
            if ($est !== null) { return new BGC_Quote(round($est, 2), 0.0, $currency, 'reference'); }
        }
        $res = self::resolve_office($courier->id(), $method, $site_id, $office);
        $shipment = array_merge($packed, [
            'method' => $method, 'site_id' => $res['site_id'], 'office_id' => $res['office_id'],
            'cod_amount' => 0.0, 'currency' => $currency,
        ]);
        return self::quote($courier, $shipment);
    }

    public static function quote(BGC_Courier_Interface $courier, array $shipment): BGC_Quote {
        $method = (string) ($shipment['method'] ?? 'address');
        if (BGC_Settings::dynamic_pricing($courier->id()) && in_array('live_quote', $courier->capabilities(), true)) {
            try { return $courier->quote($shipment); }
            catch (\Exception $e) { BGC_Logger::debug('live quote failed -> fallback', ['courier' => $courier->id()]); }
        }
        // Cached standard rate and configured default are both already in the store currency.
        $store = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'BGN';
        $cached = BGC_Rates::get($courier->id(), $method);
        if ($cached !== null) { return new BGC_Quote($cached, 0.0, $store, 'standard'); }
        $mc = BGC_Settings::method_config($courier->id(), $method);
        $amount = $mc['price'] > 0 ? $mc['price'] : 6.99;
        return new BGC_Quote(round($amount, 2), 0.0, $store, 'flat');
    }

    /**
     * A no-API price estimate for a courier+method (the cart-page estimate): the cached daily reference,
     * else the configured default price, else null (no estimate available). Store currency, net.
     */
    public static function estimate(string $courier, string $method): ?float {
        $cached = BGC_Rates::get($courier, $method);
        if ($cached !== null) { return (float) $cached; }
        $mc = BGC_Settings::method_config($courier, $method);
        return $mc['price'] > 0 ? (float) $mc['price'] : null;
    }
}
