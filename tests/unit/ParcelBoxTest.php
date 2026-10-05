<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * The parcel the shop actually ships is a BAG, and its size is the size of what went into it (owner,
 * 2026-10-05). So the box is the largest item's footprint, and a height that makes the rectangle hold
 * the goods' volume - the number a locker compartment is chosen by.
 *
 * These are the sums, with no WordPress around them: box_of_units() takes plain units and returns plain
 * centimetres, which is why it exists as its own function.
 *
 * @group core
 */
final class ParcelBoxTest extends TestCase {

    /** One sachet stays one sachet: the box is the sachet, not a cube with its volume. */
    public function test_a_single_flat_item_keeps_its_own_shape(): void {
        $box = BGCouriers_Settings::box_of_units([['l' => 15, 'w' => 11, 'h' => 0.3, 'qty' => 1]]);
        $this->assertSame(['length' => 15, 'width' => 11, 'height' => 1], $box);
    }

    /** Five of them are five times the volume on the same footprint - that is what the locker needs. */
    public function test_quantity_grows_the_height_and_not_the_footprint(): void {
        $box = BGCouriers_Settings::box_of_units([['l' => 10, 'w' => 10, 'h' => 2, 'qty' => 5]]);
        $this->assertSame(10, $box['length']);
        $this->assertSame(10, $box['width']);
        $this->assertSame(10, $box['height']);   // 1000 cm3 over a 100 cm2 footprint
    }

    /** The footprint is the biggest item's; the small ones only add their volume. */
    public function test_the_biggest_item_sets_the_footprint(): void {
        $box = BGCouriers_Settings::box_of_units([
            ['l' => 30, 'w' => 20, 'h' => 1, 'qty' => 1],
            ['l' => 5,  'w' => 5,  'h' => 5, 'qty' => 2],
        ]);
        $this->assertSame(30, $box['length']);
        $this->assertSame(20, $box['width']);
        // 600 + 250 = 850 cm3 over 600 cm2
        $this->assertSame(2, $box['height']);
    }

    /** An item entered standing up is the same item: its own dimensions are sorted before anything else. */
    public function test_the_order_of_a_product_s_own_dimensions_does_not_matter(): void {
        $flat     = BGCouriers_Settings::box_of_units([['l' => 30, 'w' => 20, 'h' => 1, 'qty' => 1]]);
        $standing = BGCouriers_Settings::box_of_units([['l' => 1, 'w' => 30, 'h' => 20, 'qty' => 1]]);
        $this->assertSame($flat, $standing);
    }

    /** The packaging allowance is volume, so it shows up in the height and not in the footprint. */
    public function test_the_packaging_allowance_adds_height_only(): void {
        $plain = BGCouriers_Settings::box_of_units([['l' => 10, 'w' => 10, 'h' => 10, 'qty' => 1]], 1.0);
        $bag   = BGCouriers_Settings::box_of_units([['l' => 10, 'w' => 10, 'h' => 10, 'qty' => 1]], 1.5);
        $this->assertSame(['length' => 10, 'width' => 10, 'height' => 10], $plain);
        $this->assertSame(['length' => 10, 'width' => 10, 'height' => 15], $bag);
    }

    /** A factor below 1 would shrink the parcel below the goods - it is ignored, not applied. */
    public function test_an_allowance_under_one_never_shrinks_the_parcel(): void {
        $box = BGCouriers_Settings::box_of_units([['l' => 10, 'w' => 10, 'h' => 10, 'qty' => 1]], 0.5);
        $this->assertSame(10, $box['height']);
    }

    /**
     * A shop with no dimensions on its products keeps exactly what it had: the stand-in units are not a
     * measurement, so the answer is empty and the caller uses the configured parcel - not that parcel
     * with a packaging allowance added to it.
     */
    public function test_stand_in_units_alone_are_not_a_measurement(): void {
        $units = [
            ['l' => 10, 'w' => 10, 'h' => 2, 'qty' => 1, 'measured' => false],
            ['l' => 10, 'w' => 10, 'h' => 2, 'qty' => 3, 'measured' => false],
        ];
        $this->assertSame([], BGCouriers_Settings::box_of_units($units, 1.1));
    }

    /** One real measurement is enough: the rest of the order counts by the shop's default size. */
    public function test_one_measured_product_measures_the_whole_parcel(): void {
        $units = [
            ['l' => 30, 'w' => 20, 'h' => 1, 'qty' => 1],
            ['l' => 10, 'w' => 10, 'h' => 2, 'qty' => 1, 'measured' => false],
        ];
        // 600 + 200 = 800 cm3 over the 30x20 footprint
        $this->assertSame(['length' => 30, 'width' => 20, 'height' => 2], BGCouriers_Settings::box_of_units($units));
    }

    /** Nothing measurable: the caller must fall back to the configured parcel, so it gets an empty answer. */
    public function test_units_without_sizes_measure_nothing(): void {
        $this->assertSame([], BGCouriers_Settings::box_of_units([['l' => 0, 'w' => 0, 'h' => 0, 'qty' => 3]]));
        $this->assertSame([], BGCouriers_Settings::box_of_units([]));
    }

    /** Centimetres are what couriers take, and a parcel is never 0 cm high. */
    public function test_the_result_is_whole_centimetres_and_never_zero(): void {
        $box = BGCouriers_Settings::box_of_units([['l' => 10.2, 'w' => 7.4, 'h' => 0.1, 'qty' => 1]]);
        $this->assertSame(11, $box['length']);
        $this->assertSame(8, $box['width']);
        $this->assertSame(1, $box['height']);
    }
}
