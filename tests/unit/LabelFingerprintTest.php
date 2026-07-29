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
 * The fingerprint decides whether an edited order gets its waybill voided and re-issued automatically.
 * A false positive costs a real shipment, so what is IN it and what is OUT of it both matter.
 *
 * @group core
 */
final class LabelFingerprintTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('wc_format_decimal')->alias(static fn($v, $d = 2) => number_format((float) $v, (int) $d, '.', ''));
        Functions\when('wc_get_weight')->alias(static fn($w, $to, $from = null) => (float) $w / 1000);
        Functions\when('get_option')->justReturn('1');
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
        return $o;
    }
    private function fp(WC_Order $o): string { return BGCouriers_Labels::label_fingerprint($o); }

    public function test_is_stable_for_an_unchanged_order(): void {
        $o = $this->order();
        $this->assertSame($this->fp($o), $this->fp($o));
        $this->assertSame($this->fp($this->order()), $this->fp($this->order()));
    }

    /** These must NEVER void a live shipment. */
    public function test_ignores_changes_the_courier_does_not_care_about(): void {
        $o = $this->order(); $before = $this->fp($o);
        $o->status = 'completed';
        $o->notes[] = 'some internal note';
        $o->meta['_bgcouriers_track_status'] = '148';
        $o->meta['_bgcouriers_waybill'] = 'NEWNUMBER';
        $o->meta['_bgcouriers_label_url'] = 'https://example/x.pdf';
        $this->assertSame($before, $this->fp($o), 'status, notes and tracking meta must not trigger a re-issue');
    }

    /** Each of these genuinely invalidates the waybill already at the courier. */
    public function test_detects_every_change_that_matters(): void {
        $cases = [
            'delivery office'  => static function (WC_Order $o) { $o->meta['_bgcouriers_office_id'] = '999'; },
            'city'             => static function (WC_Order $o) { $o->meta['_bgcouriers_site_id'] = '41'; },
            'delivery method'  => static function (WC_Order $o) { $o->meta['_bgcouriers_method'] = 'address'; },
            'courier'          => static function (WC_Order $o) { $o->meta['_bgcouriers_courier'] = 'econt'; },
            'street'           => static function (WC_Order $o) { $o->meta['_bgcouriers_street_name'] = 'Раковски'; },
            'house number'     => static function (WC_Order $o) { $o->meta['_bgcouriers_street_no'] = '7'; },
            'postcode'         => static function (WC_Order $o) { $o->meta['_bgcouriers_post_code'] = '4000'; },
            'manual weight'    => static function (WC_Order $o) { $o->meta['_bgcouriers_weight_kg'] = '2.5'; },
            'order total (COD)'=> static function (WC_Order $o) { $o->total = 30.0; },
            'shipping total'   => static function (WC_Order $o) { $o->shipping_total = 5.0; },
            'payment method'   => static function (WC_Order $o) { $o->payment_method = 'bacs'; },
            'recipient name'   => static function (WC_Order $o) { $o->name = 'Друг Човек'; },
            'recipient phone'  => static function (WC_Order $o) { $o->phone = '0899999999'; },
            'item quantity'    => static function (WC_Order $o) { $o->items = [new WC_Order_Item_Stub(2193, 3, 24.0)]; },
            'different product'=> static function (WC_Order $o) { $o->items = [new WC_Order_Item_Stub(4073, 2, 16.0)]; },
            'item added'       => static function (WC_Order $o) { $o->items[] = new WC_Order_Item_Stub(4075, 1, 8.0); },
        ];
        foreach ($cases as $what => $mutate) {
            $o = $this->order(); $before = $this->fp($o);
            $mutate($o);
            $this->assertNotSame($before, $this->fp($o), "a changed $what must be detected");
        }
    }
}
