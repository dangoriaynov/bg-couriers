<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-pickup.php';

/**
 * The merchant ticks "select all" and hits "Request a courier". Most of that selection cannot be
 * collected - no waybill yet, a courier without the service - and the one thing the screen must never
 * do is drop any of it without saying so: an order that vanishes from the list is indistinguishable
 * from one the courier was asked to come for.
 *
 * So the invariant here is arithmetic, not prose: every id goes into exactly one bucket, and the four
 * buckets add up to the selection.
 *
 * @group core
 */
final class PickupSelectionTest extends TestCase {
    /** @var array<int,WC_Order|null> */
    private array $orders = [];

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('get_option')->alias(static function ($n, $d = false) { return $d; });
        Functions\when('wc_get_order')->alias(function ($id) { return $this->orders[(int) $id] ?? null; });
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });
        BGCouriers_Couriers::register('sameday', 'Sameday', static function () { return new BGCouriers_Sameday([]); });
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    /** An order as the label generator leaves it: a waybill and the courier that issued it. */
    private function order(int $id, string $waybill = '', string $courier = ''): int {
        $o = new WC_Order();
        $o->id = $id;
        if ($waybill !== '') { $o->meta['_bgcouriers_waybill'] = $waybill; }
        if ($courier !== '') { $o->meta['_bgcouriers_courier'] = $courier; }
        $o->meta['_bgcouriers_weight_kg'] = 1.2;
        $this->orders[$id] = $o;
        return $id;
    }

    public function test_the_four_buckets_add_up_to_the_selection(): void {
        $ids = [
            $this->order(1, '63710932641', 'speedy'),   // collectable
            $this->order(2, '63710932642', 'speedy'),   // collectable
            $this->order(3, '', 'speedy'),              // no waybill yet
            $this->order(4, '3040012345', 'sameday'),   // Sameday has no pickup API
            $this->order(5, '63710932643', ''),         // waybill, no courier recorded
            $this->order(6, '63710932644', 'ekont'),    // waybill, courier the plugin cannot name
            777,                                        // an order that no longer loads
        ];
        $g = BGCouriers_Pickup::group($ids);

        $counted = count($g['no_waybill']) + count($g['unresolved'])
                 + array_sum(array_map('count', $g['unsupported']))
                 + array_sum(array_map('count', $g['groups']));
        $this->assertSame(count($ids), $counted, 'every selected order must be accounted for somewhere');
    }

    /** The hole this test was written for: a waybill whose courier cannot be named used to vanish. */
    public function test_a_waybill_without_a_courier_is_reported_not_dropped(): void {
        $ids = [$this->order(5, '63710932643', ''), $this->order(6, '63710932644', 'ekont')];
        $g = BGCouriers_Pickup::group($ids);
        $this->assertSame([5, 6], $g['unresolved']);
        $this->assertSame([], $g['groups'], 'nothing is collectable here');
    }

    public function test_a_deleted_order_is_reported_not_dropped(): void {
        $g = BGCouriers_Pickup::group([777]);
        $this->assertSame([777], $g['unresolved']);
    }

    /** Only the ones with a waybill AND a courier that offers the service reach the request. */
    public function test_only_the_collectable_are_grouped(): void {
        $ids = [$this->order(1, '63710932641', 'speedy'), $this->order(3, '', 'speedy'),
                $this->order(4, '3040012345', 'sameday')];
        $g = BGCouriers_Pickup::group($ids);
        $this->assertSame(['speedy'], array_keys($g['groups']));
        $this->assertSame('63710932641', $g['groups']['speedy'][0]['waybill']);
        $this->assertSame(1.2, $g['groups']['speedy'][0]['weight_kg']);
        $this->assertSame([3], $g['no_waybill']);
        $this->assertSame(['sameday' => [4]], $g['unsupported']);
    }
}
