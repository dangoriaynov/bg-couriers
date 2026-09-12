<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-order.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';

/**
 * How many boxes an order is, and what it is insured for.
 *
 * Both were literals in the courier payloads - `parcelsCount => 1`, `packageNumber => 1`,
 * `insuredValue => 0` - so a shop sending three parcels got one waybill for one box and made the other
 * two by hand, and insurance was unreachable however valuable the goods.
 *
 * The property worth pinning is the arithmetic: a courier re-weighs at the depot and bills the
 * difference, so the per-parcel weights must add back up to the total exactly, at any split.
 *
 * @group core
 */
final class ParcelsAndInsuranceTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** The suite's shared WC_Order stand-in - one partial stub for every test, by design. */
    private function order(array $meta): WC_Order {
        $o = new WC_Order();
        $o->meta = $meta;
        return $o;
    }

    public function test_one_parcel_unless_told_otherwise(): void {
        $this->assertSame(1, BGCouriers_Order::parcels($this->order([])));
        $this->assertSame(1, BGCouriers_Order::parcels($this->order(['_bgcouriers_parcels' => '1'])));
        // Nonsense from a text box must not become zero or negative parcels.
        $this->assertSame(1, BGCouriers_Order::parcels($this->order(['_bgcouriers_parcels' => '0'])));
        $this->assertSame(1, BGCouriers_Order::parcels($this->order(['_bgcouriers_parcels' => '-4'])));
        $this->assertSame(3, BGCouriers_Order::parcels($this->order(['_bgcouriers_parcels' => '3'])));
    }

    /** A mistyped 1000 must not ask a courier for a thousand labels. */
    public function test_the_parcel_count_is_capped(): void {
        $this->assertSame(99, BGCouriers_Order::parcels($this->order(['_bgcouriers_parcels' => '1000'])));
    }

    /** Insurance is opt-in: it costs the sender money, so nothing is insured by default. */
    public function test_nothing_is_insured_unless_asked(): void {
        $this->assertSame(0.0, BGCouriers_Order::insurance($this->order([])));
        $this->assertSame(0.0, BGCouriers_Order::insurance($this->order(['_bgcouriers_insurance' => '-5'])));
        $this->assertSame(149.9, BGCouriers_Order::insurance($this->order(['_bgcouriers_insurance' => '149.90'])));
    }

    /** @dataProvider splits */
    public function test_the_parts_add_back_up_to_the_whole(float $total, int $n): void {
        $w = BGCouriers_Order::parcel_weights($total, $n);
        $this->assertCount($n, $w, 'one entry per parcel');
        $this->assertEqualsWithDelta($total, array_sum($w), 0.0005,
            "the $n parts of {$total}kg must add back up: " . implode(' + ', $w));
        foreach ($w as $kg) { $this->assertGreaterThan(0, $kg, 'no parcel may weigh nothing'); }
    }

    public function splits(): array {
        return [
            'one box'            => [2.0, 1],
            'two even'           => [4.0, 2],
            'three, recurring'   => [10.0, 3],   // 3.333... - the case that does not divide
            'seven, awkward'     => [1.0, 7],
            'heavy, many'        => [87.65, 12],
        ];
    }

    /** A single parcel must be the whole weight, untouched by the splitting arithmetic. */
    public function test_one_parcel_carries_the_whole_weight(): void {
        $this->assertSame([7.25], BGCouriers_Order::parcel_weights(7.25, 1));
    }

    /**
     * WHICH couriers carry several parcels is the courier's own answer now, not a list in this file.
     *
     * It was BGCouriers_Order::MULTI_PARCEL_COURIERS, a courier-id list sitting a file away from every
     * courier it named - the exact shape this project has been caught by before, when Express One was
     * given five settings that no hardcoded list had been told about.
     */
    public function test_the_couriers_that_carry_several_parcels_say_so_themselves(): void {
        BGCouriers_Couriers::reset();
        $make = function (bool $many) {
            return new class($many) extends BGCouriers_Abstract_Courier {
                private $many;
                public function __construct(bool $many) { $this->many = $many; }
                public function multi_parcel(): bool { return $this->many; }
                public function id(): string { return 'x'; }
                public function label(): string { return 'X'; }
                public function capabilities(): array { return []; }
                public function check_credentials(): bool { return true; }
                public function fetch_cities(): array { return []; }
                public function fetch_offices(int $city_id): array { return []; }
                public function quote(array $shipment): BGCouriers_Quote { return new BGCouriers_Quote(0.0, 0.0, 'BGN', 'fallback'); }
                public function create_label(\WC_Order $order): BGCouriers_Label { return new BGCouriers_Label(''); }
                public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
                public function cancel_label(string $waybill): bool { return true; }
                public function track(string $waybill): BGCouriers_Tracking { return new BGCouriers_Tracking('', '', []); }
                public function tracking_url(string $waybill): string { return ''; }
            };
        };
        foreach (['speedy' => true, 'econt' => false, 'sameday' => true, 'expressone' => true, 'evropat' => false] as $id => $many) {
            BGCouriers_Couriers::register($id, ucfirst($id), static function () use ($make, $many) { return $make($many); });
        }
        $this->assertSame(['speedy', 'sameday', 'expressone'], BGCouriers_Order::multi_parcel_couriers());
        BGCouriers_Couriers::reset();
    }

    /** And the three real ones answer the way the measurements in their classes say they do. */
    public function test_the_real_couriers_answer_as_measured(): void {
        $src = static function (string $f): string {
            return (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-' . $f . '.php');
        };
        foreach (['speedy', 'sameday', 'expressone'] as $id) {
            $this->assertStringContainsString('public function multi_parcel(): bool { return true; }', $src($id), $id . ' has to say it carries several');
        }
        foreach (['econt', 'pigeon', 'boxnow', 'evropat'] as $id) {
            $this->assertStringNotContainsString('multi_parcel', $src($id), $id . ' must not claim it does');
        }
    }
}
