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
        $flat = (float) BGC_Settings::get($courier->id(), 'flat_fallback', 6.99);
        return new BGC_Quote($flat, 0.0, 'BGN', 'flat');
    }
}
