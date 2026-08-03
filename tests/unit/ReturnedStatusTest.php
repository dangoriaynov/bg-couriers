<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';

/**
 * A refused parcel comes back in two moments and only the second one means anything to the shop: while
 * it travels back nothing has been recovered, but once it is on the counter the goods exist again and the
 * order is over. Cancelling is what a merchant picks - WooCommerce returns the stock with it.
 *
 * Getting this the wrong way round would cancel an order (and restock goods that are still on a van)
 * while the parcel is somewhere between the customer and the shop.
 *
 * @group core
 */
final class ReturnedStatusTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Speedy's two return wordings must land on different stages - that is what gates the status. */
    public function test_only_the_completed_return_is_the_terminal_stage(): void {
        $this->assertSame('returning', BGCouriers_Tracking::classify('Връщане към подателя'),
            'still on a van - nothing recovered yet');
        $this->assertSame('returned', BGCouriers_Tracking::classify('Предаване обратно на подател'),
            'handed back to the sender - the goods are here');
    }

    /** The label reads as an outcome, not as a direction. */
    public function test_the_two_stages_read_differently(): void {
        $this->assertNotSame(BGCouriers_Tracking::stage_label('returning'), BGCouriers_Tracking::stage_label('returned'));
        $this->assertSame('Back with you', BGCouriers_Tracking::stage_label('returned'));
    }

    /** A returned parcel is NOT locked: that waybill is spent and a second attempt needs a fresh one. */
    public function test_a_returned_parcel_can_be_re_shipped(): void {
        $o = new WC_Order();
        $o->meta = ['_bgcouriers_track_stage' => 'returned', '_bgcouriers_handover' => 'yes'];
        $this->assertFalse(BGCouriers_Labels::is_locked($o));

        $o->meta = ['_bgcouriers_track_stage' => 'returning', '_bgcouriers_handover' => 'yes'];
        $this->assertTrue(BGCouriers_Labels::is_locked($o), 'still with the courier');
    }
}
