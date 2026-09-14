<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-packer.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * Sameday easyBox availability: which locker has a free compartment the shop's parcel fits into.
 *
 * box_size_for() maps the shop's parcel to the smallest easyBox size (using Sameday's own published
 * easyBox cell sizes - see box_dims_table()). locker_fits() answers "is a compartment of that size, or
 * any larger, free right now?" - because a parcel that needs S fits an S, M or L box, but one that
 * needs L fits only an L. The map and the office list grey out a locker where the answer is no.
 *
 * @group sameday
 */
final class SamedayLockerAvailabilityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        // box_dims_table() reads the bgcouriers_sameday_box_dims filter; unstubbed it returns the default table.
        Functions\when('apply_filters')->returnArg(2);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    // ── box_size_for(): parcel -> required easyBox size ──────────────────────

    public function test_the_default_small_parcel_needs_the_smallest_box(): void {
        // The shop default (10 x 10 x 2 cm) is tiny - it needs only an S compartment.
        $this->assertSame('S', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 2]));
    }

    public function test_a_taller_parcel_needs_a_medium_box(): void {
        $this->assertSame('M', BGCouriers_Sameday::box_size_for(['length' => 40, 'width' => 30, 'height' => 12]));
    }

    public function test_a_tall_parcel_needs_a_large_box(): void {
        $this->assertSame('L', BGCouriers_Sameday::box_size_for(['length' => 40, 'width' => 30, 'height' => 30]));
    }

    public function test_an_oversized_parcel_maps_to_the_largest_never_to_nothing(): void {
        // Bigger than any compartment -> L (largest), leaving a true misfit for Sameday to reject, as the
        // BOX NOW mapping does. It must never return '' or the locker would read as "fits nothing" wrongly.
        $this->assertSame('L', BGCouriers_Sameday::box_size_for(['length' => 200, 'width' => 200, 'height' => 200]));
    }

    public function test_the_default_table_matches_sameday_published_cell_sizes(): void {
        // Sameday's easyBox terms (sameday.bg): cells are 445 x {100,200,390} x 470 mm (W x H x D), so the
        // opening is 44.5 x 47 cm and the heights S 10 / M 20 / L 39 cm separate the sizes. Pin the boundaries.
        $this->assertSame('S', BGCouriers_Sameday::box_size_for(['length' => 47, 'width' => 44.5, 'height' => 10]));   // exactly the S cell
        $this->assertSame('M', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 10.5]));   // just over S height -> M
        $this->assertSame('M', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 20]));     // exactly the M cell
        $this->assertSame('L', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 20.5]));   // just over M height -> L
        $this->assertSame('L', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 39]));     // exactly the L cell
        // Wider or deeper than the opening -> largest (a real misfit for Sameday to reject), never "nothing".
        $this->assertSame('L', BGCouriers_Sameday::box_size_for(['length' => 47.5, 'width' => 10, 'height' => 2]));    // deeper than 47 cm
        $this->assertSame('L', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 44.6, 'height' => 2]));    // wider than 44.5 cm
    }

    public function test_the_box_dimensions_are_correctable_through_the_filter(): void {
        // Sameday publishes no compartment sizes, so the table is a documented default; a shop whose
        // easyBox network measures differently corrects it through the filter, no code change.
        Functions\when('apply_filters')->alias(static function ($tag, $value) {
            return $tag === 'bgcouriers_sameday_box_dims'
                ? ['max_length' => 60.0, 'max_width' => 45.0, 'heights' => ['S' => 5.0, 'M' => 10.0, 'L' => 20.0]]
                : $value;
        });
        // 8 cm tall is S under the default table (S=8); under the tighter filtered table (S=5) it needs M.
        $this->assertSame('M', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 8]));
        // Taller than the filtered L height (20) -> largest, never nothing.
        $this->assertSame('L', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 25]));
    }

    public function test_a_filter_that_lists_the_heights_out_of_order_still_picks_the_smallest_fit(): void {
        Functions\when('apply_filters')->alias(static function ($tag, $value) {
            return $tag === 'bgcouriers_sameday_box_dims'
                ? ['max_length' => 60.0, 'max_width' => 45.0, 'heights' => ['L' => 36.0, 'S' => 8.0, 'M' => 17.0]]
                : $value;
        });
        $this->assertSame('S', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 6]));
        $this->assertSame('M', BGCouriers_Sameday::box_size_for(['length' => 10, 'width' => 10, 'height' => 15]));
    }

    // ── locker_fits(): required size vs the locker's free boxes ───────────────

    public function test_a_free_box_of_the_required_size_fits(): void {
        $this->assertTrue(BGCouriers_Sameday::locker_fits(['S' => 3, 'M' => 0, 'L' => 0], 'S'));
    }

    public function test_a_smaller_parcel_fits_a_larger_free_box(): void {
        // Need S, only L free -> the small parcel fits the large compartment.
        $this->assertTrue(BGCouriers_Sameday::locker_fits(['S' => 0, 'M' => 0, 'L' => 2], 'S'));
    }

    public function test_a_larger_parcel_does_NOT_fit_a_smaller_free_box(): void {
        // Need L, only S free -> the large parcel cannot go into the small compartment.
        $this->assertFalse(BGCouriers_Sameday::locker_fits(['S' => 9, 'M' => 0, 'L' => 0], 'L'));
    }

    public function test_a_full_locker_fits_nothing(): void {
        $this->assertFalse(BGCouriers_Sameday::locker_fits(['S' => 0, 'M' => 0, 'L' => 0], 'S'));
        $this->assertFalse(BGCouriers_Sameday::locker_fits([], 'S'));
    }

    public function test_need_medium_fits_medium_or_large_only(): void {
        $this->assertTrue(BGCouriers_Sameday::locker_fits(['M' => 1], 'M'));
        $this->assertTrue(BGCouriers_Sameday::locker_fits(['L' => 1], 'M'));
        $this->assertFalse(BGCouriers_Sameday::locker_fits(['S' => 5], 'M'));
    }

    // ── fetch_locker_availability() parses availableBoxes off the live shape ──

    public function test_it_reads_free_boxes_from_the_live_locker_shape(): void {
        // The exact shape measured on the live account 2026-09-14.
        $sameday = new class extends BGCouriers_Sameday {
            public array $pages = [];
            public function __construct() {}
            protected function get_paged(string $path): array { return $this->pages; }
        };
        $sameday->pages = [
            ['lockerId' => 20006, 'availableBoxes' => [['size' => 'S', 'number' => 25], ['size' => 'M', 'number' => 4], ['size' => 'L', 'number' => 10]]],
            // A genuinely FULL easyBox: nothing free, but it HAS compartments (some occupied) - kept, reads full.
            ['lockerId' => 20007, 'availableBoxes' => [], 'occupiedBoxes' => [['size' => 'S', 'number' => 30]]],
            ['id' => 20008, 'availableBoxes' => [['size' => 'L', 'number' => 1]]], // id fallback
            ['name' => 'no id, skipped'],
        ];
        $avail = $sameday->fetch_locker_availability();
        $this->assertSame(['S' => 25, 'M' => 4, 'L' => 10], $avail[20006]);
        $this->assertSame([], $avail[20007], 'a full easyBox is kept with no free boxes');
        $this->assertSame(['L' => 1], $avail[20008]);
        $this->assertArrayNotHasKey(0, $avail);
    }

    /**
     * The lockers listing also returns staffed "SAMEDAY point" counters with no compartments at all
     * (measured live: all three box lists empty). A person takes the parcel there, so they are NOT part
     * of the free-compartment question - they must be left out, not reported as "full".
     */
    public function test_a_staffed_point_with_no_compartments_is_left_out(): void {
        $sameday = new class extends BGCouriers_Sameday {
            public array $pages = [];
            public function __construct() {}
            protected function get_paged(string $path): array { return $this->pages; }
        };
        $sameday->pages = [
            ['lockerId' => 20006, 'availableBoxes' => [['size' => 'S', 'number' => 5]]],           // easyBox
            ['lockerId' => 500009, 'name' => 'SAMEDAY point 1285', 'availableBoxes' => [], 'occupiedBoxes' => [], 'reservedBoxes' => []], // PUDO counter
        ];
        $avail = $sameday->fetch_locker_availability();
        $this->assertArrayHasKey(20006, $avail);
        $this->assertArrayNotHasKey(500009, $avail, 'a compartment-less SAMEDAY point is not an easyBox and is not tracked');
    }
}
