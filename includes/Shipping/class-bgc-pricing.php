<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Pricing {
    public static function quote(BGC_Courier_Interface $courier, array $shipment): BGC_Quote {
        $method = (string) ($shipment['method'] ?? 'address');
        if (in_array('live_quote', $courier->capabilities(), true)) {
            try { return $courier->quote($shipment); }
            catch (\Exception $e) { BGC_Logger::debug('live quote failed -> fallback', ['courier' => $courier->id()]); }
        }
        $cached = BGC_Rates::get($courier->id(), $method);
        if ($cached !== null) { return new BGC_Quote($cached, 0.0, 'BGN', 'standard'); }
        // Configured per-method default price (this method's currency -> store currency).
        $mc = BGC_Settings::method_config($courier->id(), $method);
        $store = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'BGN';
        $amount = $mc['price'] > 0 ? $mc['price'] : 6.99;
        $amount = BGC_Currency::convert($amount, $mc['currency'] ?: 'BGN', $store);
        return new BGC_Quote(round($amount, 2), 0.0, $store, 'flat');
    }
}
