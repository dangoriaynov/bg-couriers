<?php
defined('ABSPATH') || exit;

class BGCouriers_Packer {
    public static function standard(): array {
        return ['weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
    }
    public static function from_weight(float $kg): array {
        return ['weight_kg' => max($kg, 0.1)] + array_slice(self::standard(), 1, null, true);
    }

    /**
     * A weight the way WooCommerce hands it over - in the SHOP's own unit (woocommerce_weight_unit),
     * which is not necessarily the kilogram every courier quotes in.
     *
     * A shop that prices in grams was quoting every parcel a THOUSAND times too heavy: a 40 g basket
     * was sent to the couriers as 40 kg, so one of them charged 39,44 € for a locker delivery and
     * another refused the parcel outright and let the configured fallback price stand in for it. The
     * label path has always converted (BGCouriers_Abstract_Courier::order_weight_kg), so the parcel
     * that was actually booked never matched the price the customer had been shown.
     *
     * The conversion lives here, on the way in, so no call site can forget it.
     */
    public static function kg(float $store_weight): float {
        return function_exists('wc_get_weight') ? (float) wc_get_weight($store_weight, 'kg') : $store_weight;
    }

    /** Package weight in the shop's own unit -> a parcel the couriers can be asked about. */
    public static function from_store_weight(float $store_weight): array {
        return self::from_weight(self::kg($store_weight));
    }
}
