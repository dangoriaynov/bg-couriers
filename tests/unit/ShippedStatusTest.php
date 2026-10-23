<?php
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';

require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-tracking-poller.php';

/**
 * "Shipped" must mean the courier actually took the parcel. Creating a waybill only hands over the data,
 * and every courier reports that as its own first tracking event (Speedy's 148), so marking on the first
 * sighting would flip orders to Shipped while the parcel is still on the merchant's desk.
 *
 * @group core
 */
final class ShippedStatusTest extends BGCouriers_Unit_TestCase {

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

    /** @return array<string,array{string,array,string,int,string,bool,string}> target, meta, status, last code, events, marks?, status after */
    public static function transitions(): array {
        $first = ['_bgcouriers_track_first' => '148'];
        $ship  = 'wc-bgcouriers-shipped';
        return [
            'off by default'                 => ['', [], 'processing', '148', 3, false, 'processing'],
            'registration event alone waits' => [$ship, [], 'processing', '148', 1, false, 'processing'],
            'moved past the first status'    => [$ship, $first, 'processing', '2', 2, true, 'bgcouriers-shipped'],
            'first sighting already moving'  => [$ship, [], 'processing', '2', 2, true, 'bgcouriers-shipped'],
            'still at the first status'      => [$ship, $first, 'processing', '148', 1, false, 'processing'],
            'already marked once'            => [$ship, $first + ['_bgcouriers_shipped_marked' => 'yes'], 'completed', '5', 4, false, 'completed'],
            'completed left alone'           => [$ship, $first, 'completed', '9', 3, false, 'completed'],
            'cancelled left alone'           => [$ship, $first, 'cancelled', '9', 3, false, 'cancelled'],
            'refunded left alone'            => [$ship, $first, 'refunded', '9', 3, false, 'refunded'],
            'failed left alone'              => [$ship, $first, 'failed', '9', 3, false, 'failed'],
            'custom target status'           => ['wc-on-hold', $first, 'processing', '2', 2, true, 'on-hold'],
        ];
    }

    /** @dataProvider transitions */
    public function test_shipped_is_marked_once_the_status_moves_past_registration(
        string $target, array $meta, string $status, string $code, int $events, bool $marks, string $after
    ): void {
        $this->opt($target);
        $o = new WC_Order();
        $o->status = $status;
        $o->meta = $meta;
        $this->assertSame($marks, $this->call($o, $this->tracking($code, $events)));
        $this->assertSame($after, $o->status);
        if ($marks) {
            $this->assertSame('yes', $o->meta['_bgcouriers_shipped_marked']);
        }
    }

    public function test_the_first_status_seen_is_remembered(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $this->call($o, $this->tracking('148', 1));
        $this->assertSame('148', $o->meta['_bgcouriers_track_first']);
        $this->assertArrayNotHasKey('_bgcouriers_shipped_marked', $o->meta);
    }

    public function test_an_order_already_in_the_target_status_is_not_transitioned_again(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $o->status = 'bgcouriers-shipped';
        $o->meta['_bgcouriers_track_first'] = '148';
        $this->assertFalse($this->call($o, $this->tracking('9', 3)));
        $this->assertNull($o->transition);
    }

    // ── Couriers that answer the handover question outright ─────────────────

    private function pigeon(string $fixture): BGCouriers_Tracking {
        return BGCouriers_Pigeon::parse_tracking($this->fixture('pigeon', $fixture));
    }

    /** Pigeon's history does not grow, so its status code decides handover. */
    public function test_pigeon_marks_shipped_once_the_courier_has_the_parcel(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $this->assertTrue($this->call($o, $this->pigeon('track.json')));
        $this->assertSame('bgcouriers-shipped', $o->status);
        $this->assertSame('yes', $o->meta['_bgcouriers_shipped_marked']);
    }

    public function test_pigeon_does_not_mark_a_freshly_printed_label(): void {
        $this->opt('wc-bgcouriers-shipped');
        $o = new WC_Order();
        $this->assertFalse($this->call($o, $this->pigeon('track-registered.json')));
        $this->assertSame('processing', $o->status);
        $this->assertArrayNotHasKey('_bgcouriers_shipped_marked', $o->meta);
    }

    public function test_a_custom_target_status_is_honoured(): void {
        $this->opt('wc-on-hold');
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_first'] = '148';
        $this->assertTrue($this->call($o, $this->tracking('2', 2)));
        $this->assertSame('on-hold', $o->status);
    }
}
