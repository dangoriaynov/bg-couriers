<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';
require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-tracking-poller.php';

/**
 * A Sameday locker parcel nobody came for, replayed word for word from a live order (11279,
 * 2026-09-25 .. 2026-10-05). Sameday publishes no codes for "waiting", "nobody came" or "handed back" -
 * only Bulgarian prose - and three of its sentences fell between the shared vocabulary's phrases: the
 * parcel was "in transit" while it stood in the locker, "in transit" when its storage period ran out,
 * and still "coming back" after it had been handed back to the shop. None of the three auto-status
 * rules ever ran, and the order sat on Shipped for weeks. A fourth sentence, the driver postponing the
 * collection, was read as the parcel MOVING and put the order on Shipped while the box was still on
 * the counter.
 *
 * @group sameday
 */
final class SamedayReturnJourneyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('_x')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('get_option')->alias(static function ($n, $d = false) {
            $o = [
                'bgcouriers_autostatus_on_shipped'   => 'wc-bgc-shipped',
                'bgcouriers_autostatus_on_unclaimed' => 'wc-failed',
                'bgcouriers_autostatus_on_returned'  => 'wc-cancelled',
            ];
            return $o[$n] ?? $d;
        });
        if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** The sentences Sameday really sent, in the order it sent them. */
    private const JOURNEY = [
        ['2026-09-25T14:55:11+03:00', 'Създадена товарителница'],
        ['2026-09-25T17:30:02+03:00', 'Отложено взимане от подател'],
        ['2026-09-26T10:12:40+03:00', 'Складова наличност скан'],
        ['2026-09-28T22:40:17+03:00', 'Изход сортировач'],
        ['2026-09-29T05:01:33+03:00', 'Експорт от хъб'],
        ['2026-09-29T11:20:08+03:00', 'Заредена в OOH'],
        ['2026-10-01T09:00:00+03:00', 'Срокът за съхранение на пратката е изтекъл.'],
        ['2026-10-01T16:48:51+03:00', 'Взетa от OOH'],
        ['2026-10-02T10:30:00+03:00', 'В процес на връщане към подател'],
        ['2026-10-05T11:02:45+03:00', 'Върната на подател'],
    ];

    /** Sameday's answer after the first $n entries of the journey, newest first as the API sends it. */
    private function answer(int $n): BGCouriers_Tracking {
        $hist = [];
        foreach (array_slice(self::JOURNEY, 0, $n) as $i => [$date, $status]) {
            $hist[] = ['statusId' => 100 + $i, 'status' => $status, 'statusDate' => $date];
        }
        $cur = end($hist);
        return BGCouriers_Sameday::parse_tracking([
            'expeditionSummary' => ['delivered' => false, 'canceled' => false],
            'expeditionStatus'  => ['statusId' => $cur['statusId'], 'status' => $cur['status'], 'statusDate' => $cur['statusDate']],
            'expeditionHistory' => array_reverse($hist),
        ], '1CJALN21393865');
    }

    private function order(): WC_Order {
        $o = new WC_Order();
        $o->status = 'processing';
        $o->meta['_bgcouriers_waybill'] = '1CJALN21393865';
        return $o;
    }

    /** The three sentences that fell through, each on the stage it means. */
    public function test_samedays_own_words_land_on_their_stages(): void {
        $this->assertSame('ready', BGCouriers_Tracking::classify('Заредена в OOH'), 'loaded into the locker = waiting for the customer');
        $this->assertSame('unclaimed', BGCouriers_Tracking::classify('Срокът за съхранение на пратката е изтекъл.'), 'storage period over = nobody came');
        $this->assertSame('returned', BGCouriers_Tracking::classify('Върната на подател'), 'handed back = over');
        // The wording that was already read correctly keeps its stage.
        $this->assertSame('returning', BGCouriers_Tracking::classify('В процес на връщане към подател'));
    }

    /** The old phrase still works, so a courier that writes the expiry first is not lost. */
    public function test_both_orders_of_the_expiry_words_are_read(): void {
        $this->assertSame('unclaimed', BGCouriers_Tracking::classify('Изтекъл срок на съхранение'));
        $this->assertSame('unclaimed', BGCouriers_Tracking::classify('Срокът за съхранение е изтекъл'));
    }

    /** A collection the driver put off means the parcel is still with the shop. */
    public function test_a_postponed_collection_is_not_a_shipment(): void {
        $t = $this->answer(2);
        $this->assertFalse($t->handover, 'the courier says it has not taken it');
        $this->assertSame('registered', $t->stage(), 'still on the counter');
        $o = $this->order();
        $this->assertFalse(BGCouriers_Tracking_Poller::mark_shipped($o, $t));
        $this->assertSame('processing', $o->status);
        // Once the parcel is scanned into a warehouse it has plainly moved.
        $this->assertTrue(BGCouriers_Tracking_Poller::mark_shipped($o, $this->answer(3)));
        $this->assertSame('bgc-shipped', $o->status);
    }

    /** Other wordings say nothing about the handover and leave the rule to count events, as before. */
    public function test_handover_is_only_denied_by_the_explicit_wordings(): void {
        $this->assertFalse(BGCouriers_Sameday::handover('Създадена товарителница'));
        $this->assertFalse(BGCouriers_Sameday::handover('Отложено взимане от подател'));
        $this->assertNull(BGCouriers_Sameday::handover('Складова наличност скан'));
        $this->assertNull(BGCouriers_Sameday::handover('Доставена'));
    }

    /** The whole journey, poll by poll: Shipped, then Failed when nobody came, then Cancelled when back. */
    public function test_the_order_follows_the_parcel_home(): void {
        $o = $this->order();
        $seen = [];
        for ($n = 1; $n <= count(self::JOURNEY); $n++) {
            BGCouriers_Tracking_Poller::record($o, $this->answer($n), 'Sameday', 'completed');
            $seen[$n] = [$o->meta['_bgcouriers_track_stage'] ?? '', $o->status];
        }
        $this->assertSame(['registered', 'processing'],   $seen[1], 'waybill created');
        $this->assertSame(['registered', 'processing'],   $seen[2], 'collection postponed - not shipped');
        $this->assertSame(['transit',    'bgc-shipped'],  $seen[3], 'warehouse scan - shipped');
        $this->assertSame(['ready',      'bgc-shipped'],  $seen[6], 'in the locker - waiting');
        $this->assertSame(['unclaimed',  'failed'],       $seen[7], 'storage period over - failed');
        $this->assertSame(['returning',  'failed'],       $seen[9], 'on its way back - nothing recovered yet');
        $this->assertSame(['returned',   'cancelled'],    $seen[10], 'handed back - cancelled, stock returns');
        $this->assertSame('yes', $o->meta['_bgcouriers_track_done'], 'a returned parcel is final');
    }

    /**
     * The live order was polled by the old rules and sits on "returning" with the courier's last words
     * already stored. The next poll with the new vocabulary must still move it: the stage is re-derived
     * from the same text, and a changed stage is a change.
     */
    public function test_an_order_already_holding_the_last_words_is_moved_by_the_next_poll(): void {
        $o = $this->order();
        $o->status = 'bgc-shipped';
        $o->meta['_bgcouriers_track_status']  = 'Върната на подател';
        $o->meta['_bgcouriers_track_text']    = 'Върната на подател';
        $o->meta['_bgcouriers_track_stage']   = 'returning';
        $o->meta['_bgcouriers_shipped_marked'] = 'yes';
        BGCouriers_Tracking_Poller::record($o, $this->answer(count(self::JOURNEY)), 'Sameday', 'completed');
        $this->assertSame('returned', $o->meta['_bgcouriers_track_stage']);
        $this->assertSame('cancelled', $o->status);
    }
}
