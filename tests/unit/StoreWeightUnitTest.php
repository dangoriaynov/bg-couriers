<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-packer.php';

/**
 * A shop does not have to price in kilograms, and the one this was found on did not: it sets
 * woocommerce_weight_unit to GRAMS, so every weight WooCommerce hands a shipping method - the
 * package's contents_weight, the cart's get_cart_contents_weight() - arrives in grams.
 *
 * The couriers quote in kilograms. Passing the number across unconverted asked them about a parcel a
 * THOUSAND times too heavy: a 40 g basket went out as 40 kg, Sameday quoted 39,44 € for a locker
 * delivery, Pigeon 14,33 €, and Speedy refused the parcel outright ("над допустимия максимум от 32кг")
 * so its configured fallback price stood in for a delivery Speedy would never have carried. The label
 * built later DID convert, so the parcel booked never matched the price the customer was shown.
 *
 * @group core
 */
final class StoreWeightUnitTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Stand in for WooCommerce's own converter, for a shop whose unit is $unit. */
    private function shop_in(string $unit): void {
        Functions\when('wc_get_weight')->alias(static function ($weight, $to, $from = '') use ($unit) {
            $from = $from ?: $unit;
            $to_kg = ['kg' => 1.0, 'g' => 0.001, 'lbs' => 0.453592, 'oz' => 0.0283495];
            return ((float) $weight) * $to_kg[$from] / $to_kg[$to];
        });
    }

    public function test_a_gram_shop_is_converted_to_kilograms(): void {
        $this->shop_in('g');
        $this->assertSame(0.4, BGCouriers_Packer::kg(400.0));
    }

    public function test_a_kilogram_shop_is_left_alone(): void {
        $this->shop_in('kg');
        $this->assertSame(2.5, BGCouriers_Packer::kg(2.5));
    }

    /** The exact basket from the screenshot: 40 in the shop's units must not become a 40 kg parcel. */
    public function test_the_forty_gram_basket_is_not_a_forty_kilo_parcel(): void {
        $this->shop_in('g');
        $packed = BGCouriers_Packer::from_store_weight(40.0);
        $this->assertLessThan(1.0, $packed['weight_kg']);
    }

    /** Below the floor the packer still asks for its 100 g minimum, not for nothing. */
    public function test_a_tiny_gram_basket_falls_back_to_the_floor(): void {
        $this->shop_in('g');
        $this->assertSame(0.1, BGCouriers_Packer::from_store_weight(2.0)['weight_kg']);
    }

    public function test_a_kilogram_shop_packs_unchanged(): void {
        $this->shop_in('kg');
        $this->assertSame(2.5, BGCouriers_Packer::from_store_weight(2.5)['weight_kg']);
        $this->assertSame(10, BGCouriers_Packer::from_store_weight(2.5)['length_cm']);
    }
}
