<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-order.php';

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
}
