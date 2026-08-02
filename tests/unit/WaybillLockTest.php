<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * Once a courier has physically collected a parcel, cancelling or re-issuing its waybill changes only OUR
 * copy: the courier keeps delivering against the document travelling with the box, and the shop ends up
 * believing a shipment was voided when it is still on its way. So those actions stop at that point.
 *
 * The line is HANDOVER, not "has a waybill" and not "is moving": a freshly created label sits at the
 * courier as data only, and every courier reports that as its own first tracking event - re-issuing then
 * is perfectly safe and is exactly what the merchant does after fixing an address.
 *
 * @group core
 */
final class WaybillLockTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $meta */
    private function order(array $meta): WC_Order {
        $o = new WC_Order();
        $o->meta = $meta + ['_bgcouriers_courier' => 'speedy', '_bgcouriers_waybill' => '63689161156'];
        return $o;
    }

    /** Nothing heard from the courier yet - a label that was only just created stays fully editable. */
    public function test_a_fresh_waybill_is_not_locked(): void {
        $this->assertFalse(BGCouriers_Labels::is_locked($this->order([])));
    }

    /**
     * The parcel is registered and moving through the courier's own first event, but nobody has collected
     * anything yet. Locking here would block the ordinary "fix the address and re-issue" flow.
     */
    public function test_registered_but_not_collected_is_not_locked(): void {
        $o = $this->order(['_bgcouriers_track_stage' => 'transit',
                           '_bgcouriers_track_text'  => 'Получена информация за пратка']);
        $this->assertFalse(BGCouriers_Labels::is_locked($o));
    }

    /** The courier has it: this is the point of no return. */
    public function test_collected_is_locked(): void {
        $o = $this->order(['_bgcouriers_handover' => 'yes', '_bgcouriers_track_stage' => 'transit']);
        $this->assertTrue(BGCouriers_Labels::is_locked($o));
    }

    /** Delivered, and a parcel travelling BACK, are both out of the merchant's hands. */
    public function test_shipments_in_the_couriers_hands_are_locked(): void {
        foreach (['delivered', 'returning'] as $stage) {
            $this->assertTrue(BGCouriers_Labels::is_locked($this->order(['_bgcouriers_track_stage' => $stage])), $stage);
        }
    }

    /**
     * A parcel that has come all the way back is on the merchant's own counter: that waybill is spent and
     * a second attempt needs a fresh one, so it must NOT be locked. Locking it left the shop holding a box
     * it could not re-send.
     */
    public function test_a_parcel_back_on_the_counter_is_not_locked(): void {
        $this->assertFalse(BGCouriers_Labels::is_locked($this->order(['_bgcouriers_track_stage' => 'returned'])));
    }

    /**
     * A cancelled shipment is deliberately NOT locked - it is void, and re-issuing is the way out of it.
     * Locking that would leave the order with no usable waybill and no way to make one.
     */
    public function test_a_cancelled_shipment_stays_editable(): void {
        $o = $this->order(['_bgcouriers_track_stage' => 'cancelled', '_bgcouriers_track_text' => 'Анулиране']);
        $this->assertFalse(BGCouriers_Labels::is_locked($o));
    }

    /** The refusal has to say why, and name the way out - it is the merchant's only cue. */
    public function test_the_message_explains_and_points_somewhere(): void {
        $m = BGCouriers_Labels::locked_message();
        $this->assertNotSame('', $m);
        $this->assertStringContainsString('courier', $m);
    }
}
