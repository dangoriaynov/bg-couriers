<?php
/**
 * Just enough WC_Tax for the tax rules: a flat 20% shipping tax, which is the Bulgarian rate.
 *
 * Shared by every test that touches a price, so the inclusive and the exclusive sums come from ONE
 * definition - they are each other's inverse and a test that stubbed only one of them could pass while
 * the pair disagreed.
 */
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        /**
         * A shop with a shipping tax rate - unless a test says otherwise. The live shop has NO rate at
         * all (tax calculation off, an empty rate table), and that shop is where the VAT on a delivery
         * went missing, so it has to be expressible here.
         */
        public static function get_shipping_tax_rates() {
            return empty($GLOBALS['bgcouriers_test_shop_has_no_tax_rates']) ? ['x' => ['rate' => 20.0]] : [];
        }
        /** $price is NET; the tax goes on top. */
        public static function calc_shipping_tax($price, $rates) { return ['x' => round($price * 0.2, 2)]; }
        /** $price ALREADY contains the tax; this is how much of it is tax. */
        public static function calc_inclusive_tax($price, $rates) { return ['x' => round($price - $price / 1.2, 5)]; }
    }
}
