<?php
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
 * Sameday easyBox availability: the smallest box a parcel needs, and whether a locker has one (or larger) free.
 *
 * @group sameday
 */
final class SamedayLockerAvailabilityTest extends BGCouriers_Unit_TestCase {
    protected function setUp(): void {
        parent::setUp();
        // box_dims_table() reads the bgcouriers_sameday_box_dims filter; unstubbed it returns the default table.
        Functions\when('apply_filters')->returnArg(2);
    }

    // ── box_size_for(): parcel -> required easyBox size ──────────────────────

    /** @return array<string,array{array,string}> parcel cm => easyBox size (cells 44.5 x 47 cm, heights S 10 / M 20 / L 39) */
    public static function box_sizes(): array {
        $p = static fn($l, $w, $h) => ['length' => $l, 'width' => $w, 'height' => $h];
        return [
            'shop default'       => [$p(10, 10, 2), 'S'],
            'taller'             => [$p(40, 30, 12), 'M'],
            'tall'               => [$p(40, 30, 30), 'L'],
            'exactly S cell'     => [$p(47, 44.5, 10), 'S'],
            'just over S'        => [$p(10, 10, 10.5), 'M'],
            'exactly M'          => [$p(10, 10, 20), 'M'],
            'just over M'        => [$p(10, 10, 20.5), 'L'],
            'exactly L'          => [$p(10, 10, 39), 'L'],
            'deeper than cell'   => [$p(47.5, 10, 2), 'L'],
            'wider than cell'    => [$p(10, 44.6, 2), 'L'],
            'oversized, never nothing' => [$p(200, 200, 200), 'L'],
        ];
    }

    /** @dataProvider box_sizes */
    public function test_a_parcel_needs_the_smallest_box_it_fits_and_never_nothing(array $parcel, string $size): void {
        $this->assertSame($size, BGCouriers_Sameday::box_size_for($parcel));
    }

    public function test_the_box_dimensions_are_correctable_through_the_filter(): void {
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

    /** @return array<string,array{array,string,bool}> free boxes, needed size => fits? */
    public static function fits(): array {
        return [
            'same size free'      => [['S' => 3, 'M' => 0, 'L' => 0], 'S', true],
            'only larger free'    => [['S' => 0, 'M' => 0, 'L' => 2], 'S', true],
            'only smaller free'   => [['S' => 9, 'M' => 0, 'L' => 0], 'L', false],
            'full locker'         => [['S' => 0, 'M' => 0, 'L' => 0], 'S', false],
            'no boxes at all'     => [[], 'S', false],
            'M into M'            => [['M' => 1], 'M', true],
            'M into L'            => [['L' => 1], 'M', true],
            'M into S'            => [['S' => 5], 'M', false],
        ];
    }

    /** @dataProvider fits */
    public function test_a_locker_fits_when_a_box_of_the_needed_size_or_larger_is_free(array $free, string $need, bool $fits): void {
        $this->assertSame($fits, BGCouriers_Sameday::locker_fits($free, $need));
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

    /** Staffed "SAMEDAY point" counters have no compartments: left out, not reported as full. */
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
