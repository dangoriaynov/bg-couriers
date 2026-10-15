<?php
defined('ABSPATH') || exit;

class BGCouriers_Packer {
    public static function standard(): array {
        return ['weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
    }
    public static function from_weight(float $kg): array {
        return ['weight_kg' => max($kg, 0.1)] + array_slice(self::standard(), 1, null, true);
    }

    /** Shop-unit weight (woocommerce_weight_unit) to kilograms; converted here so no call site forgets. */
    public static function kg(float $store_weight): float {
        return function_exists('wc_get_weight') ? (float) wc_get_weight($store_weight, 'kg') : $store_weight;
    }

    public static function from_store_weight(float $store_weight): array {
        return self::from_weight(self::kg($store_weight));
    }
}
