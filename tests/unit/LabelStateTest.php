<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-labels.php';

/**
 * The waybill icon the merchant reads on the orders list and the order screen: red when the order has
 * outgrown the waybill (amount or address changed, nothing re-issued it), green while the current
 * waybill has not been printed, plain otherwise. One source of truth (BGCouriers_Labels::label_state)
 * so the two screens never disagree.
 *
 * @group core
 */
final class LabelStateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('wc_format_decimal')->alias(static fn($v, $d = 2) => number_format((float) $v, (int) $d, '.', ''));
        Functions\when('wc_get_weight')->alias(static fn($w, $to, $from = null) => (float) $w / 1000);
        Functions\when('get_option')->justReturn('1');
        Functions\when('__')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function order(): WC_Order {
        $o = new WC_Order();
        foreach (['courier' => 'speedy', 'method' => 'office', 'site_id' => '68134', 'office_id' => '307',
                  'post_code' => '1000', 'street_name' => 'Витоша', 'street_no' => '5'] as $k => $v) {
            $o->meta['_bgcouriers_' . $k] = $v;
        }
        $o->total = 24.0; $o->shipping_total = 3.0;
        $o->items = [new WC_Order_Item_Stub(2193, 2, 16.0)];
        $o->meta['_bgcouriers_waybill'] = '1CJALN21098719';
        return $o;
    }
    /** Record the fingerprint the waybill was issued for, as generate() does. */
    private function issue(WC_Order $o): void { $o->meta['_bgcouriers_label_fp'] = BGCouriers_Labels::label_fingerprint($o); }

    public function test_no_waybill_has_no_state(): void {
        $o = $this->order();
        unset($o->meta['_bgcouriers_waybill']);
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_OK, BGCouriers_Labels::label_state($o));
    }

    /** A waybill from before the fingerprint feature has nothing to compare against - do not flag it. */
    public function test_a_waybill_with_no_recorded_fingerprint_has_no_state(): void {
        $o = $this->order(); // no _bgcouriers_label_fp set
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_OK, BGCouriers_Labels::label_state($o));
    }

    public function test_a_freshly_issued_waybill_is_unprinted(): void {
        $o = $this->order(); $this->issue($o);
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_UNPRINTED, BGCouriers_Labels::label_state($o));
    }

    public function test_a_printed_waybill_that_matches_the_order_is_ok(): void {
        $o = $this->order(); $this->issue($o);
        $o->meta['_bgcouriers_label_printed_fp'] = $o->meta['_bgcouriers_label_fp'];
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_OK, BGCouriers_Labels::label_state($o));
    }

    public function test_a_changed_total_makes_it_stale(): void {
        $o = $this->order(); $this->issue($o);
        $o->meta['_bgcouriers_label_printed_fp'] = $o->meta['_bgcouriers_label_fp']; // even if printed
        $o->total = 30.0;
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_STALE, BGCouriers_Labels::label_state($o),
            'a printed label the order has outgrown is still stale');
    }

    public function test_a_changed_address_makes_it_stale(): void {
        $o = $this->order(); $this->issue($o);
        $o->meta['_bgcouriers_street_no'] = '7';
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_STALE, BGCouriers_Labels::label_state($o));
    }

    /** Re-issue records the new fingerprint but leaves the printed mark behind: green until reprinted. */
    public function test_a_reissue_is_unprinted_again(): void {
        $o = $this->order(); $this->issue($o);
        $o->meta['_bgcouriers_label_printed_fp'] = $o->meta['_bgcouriers_label_fp'];
        $o->total = 30.0;
        $this->issue($o); // the re-issue path records the new fingerprint
        $this->assertSame(BGCouriers_Labels::LABEL_STATE_UNPRINTED, BGCouriers_Labels::label_state($o));
    }

    public function test_the_stale_message_says_re_issue_when_it_still_can(): void {
        $o = $this->order(); $this->issue($o); $o->total = 30.0;
        $this->assertStringContainsString('re-issue', BGCouriers_Labels::label_state_message($o));
    }

    public function test_the_stale_message_says_arrange_with_the_courier_once_collected(): void {
        $o = $this->order(); $this->issue($o); $o->total = 30.0;
        $o->meta['_bgcouriers_track_stage'] = 'delivered'; // locked
        $this->assertStringContainsString('courier', BGCouriers_Labels::label_state_message($o));
    }
}
