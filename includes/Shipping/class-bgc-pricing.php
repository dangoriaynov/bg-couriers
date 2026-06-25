<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Pricing {
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
