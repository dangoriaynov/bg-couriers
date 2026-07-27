<?php
defined('ABSPATH') || exit;

class BGCouriers_Packer {
    public static function standard(): array {
        return ['weight_kg' => 2.0, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
    }
    public static function from_weight(float $kg): array {
        return ['weight_kg' => max($kg, 0.1)] + array_slice(self::standard(), 1, null, true);
    }
}
