<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Pricing {
    /**
     * Resolve the office to quote against, given the customer's session selection.
     * - office/automat with a chosen office → use it.
     * - office/automat, a city picked but the city has NO office of that type → 'unavailable' (the
     *   customer literally cannot ship there; the method must not show a price).
     * - office/automat, no city picked yet → a representative (first) office, so the pre-selection
     *   baseline is a live quote at the REAL cart weight (not a fixed-weight cached figure).
     *
     * @return array{office_id:int, site_id:int, unavailable:bool}
     */
    public static function resolve_office(string $courier, string $method, int $site_id, int $office): array {
        $unavailable = false;
        if ($office <= 0 && in_array($method, ['office', 'automat'], true)) {
            if ($site_id > 0) {
                $rep = BGC_Nomenclature::offices($courier, $site_id, $method);
                if (!empty($rep[0]['office_id'])) { $office = (int) $rep[0]['office_id']; }
                else { $unavailable = true; }
            } else {
                $rep = BGC_Nomenclature::first_office($courier, $method);
                if (!empty($rep['office_id'])) { $office = (int) $rep['office_id']; $site_id = (int) $rep['city_id']; }
            }
        }
        return ['office_id' => $office, 'site_id' => $site_id, 'unavailable' => $unavailable];
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
}
