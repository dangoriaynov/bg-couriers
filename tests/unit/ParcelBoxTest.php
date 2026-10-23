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

    /** @return array<string,array{array,float,array}> units, packaging factor, box (cm) */
    public static function boxes(): array {
        $u = static fn($l, $w, $h, $qty = 1, $measured = true) => ['l' => $l, 'w' => $w, 'h' => $h, 'qty' => $qty] + ($measured ? [] : ['measured' => false]);
        $box = static fn($l, $w, $h) => ['length' => $l, 'width' => $w, 'height' => $h];
        return [
            'one flat item keeps its shape'         => [[$u(15, 11, 0.3)], 1.0, $box(15, 11, 1)],
            'quantity grows height, not footprint'  => [[$u(10, 10, 2, 5)], 1.0, $box(10, 10, 10)],
            'biggest item sets the footprint, tallest the floor' => [[$u(30, 20, 1), $u(5, 5, 5, 2)], 1.0, $box(30, 20, 5)],
            'own dimensions order does not matter'  => [[$u(1, 30, 20)], 1.0, $box(30, 20, 1)],
            'packaging allowance adds height'       => [[$u(10, 10, 10)], 1.5, $box(10, 10, 15)],
            'allowance under one is ignored'        => [[$u(10, 10, 10)], 0.5, $box(10, 10, 10)],
            'one measured product measures all'     => [[$u(30, 20, 1), $u(10, 10, 2, 1, false)], 1.0, $box(30, 20, 2)],
            'never shorter than the tallest item'   => [[$u(14, 7, 7), $u(10, 10, 0.2)], 1.0, $box(14, 10, 7)],
            'whole centimetres, never zero'         => [[$u(10.2, 7.4, 0.1)], 1.0, $box(11, 8, 1)],
            'stand-in units only'                   => [[$u(10, 10, 2, 1, false), $u(10, 10, 2, 3, false)], 1.1, []],
            'no sizes'                              => [[$u(0, 0, 0, 3)], 1.0, []],
            'no units'                              => [[], 1.0, []],
        ];
    }

    /**
     * Footprint of the biggest item, height from volume but never below the tallest item; empty means
     * "use the configured parcel".
     *
     * @dataProvider boxes
     */
    public function test_box_of_units(array $units, float $factor, array $box): void {
        $this->assertSame($box, BGCouriers_Settings::box_of_units($units, $factor));
    }
}
