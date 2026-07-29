<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';

require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-tracking-poller.php';

/**
 * "Shipped" must mean the courier actually took the parcel. Creating a waybill only hands over the data,
 * and every courier reports that as its own first tracking event (Speedy's 148), so marking on the first
 * sighting would flip orders to Shipped while the parcel is still on the merchant's desk.
 *
 * @group core
 */
final class ShippedStatusTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function opt(string $target): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($target) {
            return $n === 'bgcouriers_autostatus_on_shipped' ? $target : $d;
        });
    }
    private function call(WC_Order $o, BGCouriers_Tracking $t): bool {
        return BGCouriers_Tracking_Poller::mark_shipped($o, $t);
    }
    private function tracking(string $status, int $events): BGCouriers_Tracking {
        $e = [];
        for ($i = 0; $i < $events; $i++) { $e[] = ['code' => (string) $i, 'name' => 'op ' . $i, 'date' => '']; }
        return new BGCouriers_Tracking('W1', $status, $e);
    }

    public function test_off_by_default_does_nothing(): void {
        $this->opt('');
        $o = new WC_Order();
        $this->assertFalse($this->call($o, $this->tracking('148', 3)));
        $this->assertSame('processing', $o->status);
    }

    /** The registration event alone is NOT shipped - it only means the courier has the data. */
    public function test_first_sighting_with_only_the_registration_event_waits(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $this->assertFalse($this->call($o, $this->tracking('148', 1)));
        $this->assertSame('processing', $o->status);
        $this->assertSame('148', $o->meta['_bgcouriers_track_first'], 'remembers where it started');
        $this->assertArrayNotHasKey('_bgcouriers_shipped_marked', $o->meta);
    }

    /** Next poll, the status has moved on -> the courier has it. */
    public function test_moving_past_the_first_status_marks_shipped(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_first'] = '148';
        $this->assertTrue($this->call($o, $this->tracking('2', 2)));
        $this->assertSame('bgcouriers-shipped', $o->status, 'the wc- prefix is stripped for update_status');
        $this->assertSame('yes', $o->meta['_bgcouriers_shipped_marked']);
    }

    /** Polling twice a day, the history can already show movement the very first time we look. */
    public function test_first_sighting_with_more_than_one_event_marks_immediately(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $this->assertTrue($this->call($o, $this->tracking('2', 2)));
        $this->assertSame('bgcouriers-shipped', $o->status);
    }

    /** Still sitting at the same registration status: not shipped, however often we poll. */
    public function test_unchanged_registration_status_never_marks(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_first'] = '148';
        $this->assertFalse($this->call($o, $this->tracking('148', 1)));
        $this->assertSame('processing', $o->status);
    }

    public function test_marks_only_once(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_first']   = '148';
        $o->meta['_bgcouriers_shipped_marked'] = 'yes';
        $o->status = 'completed'; // merchant moved it on afterwards
        $this->assertFalse($this->call($o, $this->tracking('5', 4)));
        $this->assertSame('completed', $o->status, 'never dragged back to Shipped');
    }

    /** A finished or dead order must not be reopened by a late tracking event. */
    public function test_terminal_statuses_are_left_alone(): void {
        foreach (['completed', 'cancelled', 'refunded', 'failed'] as $st) {
            $this->opt('wc-bgcouriers-shipped');
            $o = new WC_Order();
            $o->status = $st;
            $o->meta['_bgcouriers_track_first'] = '148';
            $this->assertFalse($this->call($o, $this->tracking('9', 3)), $st);
            $this->assertSame($st, $o->status, $st);
        }
    }

    /** Re-running against an order already in the target status is a no-op, not a second transition. */
    public function test_already_in_the_target_status(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $o->status = 'bgcouriers-shipped';
        $o->meta['_bgcouriers_track_first'] = '148';
        $this->assertFalse($this->call($o, $this->tracking('9', 3)));
        $this->assertNull($o->transition);
    }

    /** Any status may be chosen, not just ours. */
    public function test_a_custom_target_status_is_honoured(): void {
        $this->opt('wc-on-hold');
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_first'] = '148';
        $this->assertTrue($this->call($o, $this->tracking('2', 2)));
        $this->assertSame('on-hold', $o->status);
    }
}
