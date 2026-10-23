<?php
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
 * label_state(): stale when the order outgrew its waybill, unprinted while the current waybill is not printed, else ok.
 *
 * @group core
 */
final class LabelStateTest extends BGCouriers_Unit_TestCase {
    protected function setUp(): void {
        parent::setUp();        Functions\when('wc_format_decimal')->alias(static fn($v, $d = 2) => number_format((float) $v, (int) $d, '.', ''));
        Functions\when('wc_get_weight')->alias(static fn($w, $to, $from = null) => (float) $w / 1000);
        Functions\when('get_option')->justReturn('1');
    }

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
    /** What generate() records: the fingerprint and the "not printed yet" flag. */
    private function issue(WC_Order $o): void {
        $o->meta['_bgcouriers_label_fp'] = BGCouriers_Labels::label_fingerprint($o);
        $o->meta['_bgcouriers_label_needs_print'] = '1';
    }
    /** What printing does: clear the flag. */
    private function printed(WC_Order $o): void { $o->meta['_bgcouriers_label_needs_print'] = ''; }

    private function apply(WC_Order $o, string $step): void {
        switch ($step) {
            case 'issue':      $this->issue($o); break;
            case 'print':      $this->printed($o); break;
            case 'fp only':    $o->meta['_bgcouriers_label_fp'] = BGCouriers_Labels::label_fingerprint($o); break;
            case 'no waybill': unset($o->meta['_bgcouriers_waybill']); break;
            case 'new total':  $o->total = 30.0; break;
            case 'new street': $o->meta['_bgcouriers_street_no'] = '7'; break;
        }
    }

    /** @return array<string,array{string[],string}> steps => LABEL_STATE_* */
    public static function histories(): array {
        return [
            'no waybill'                     => [['no waybill'], 'OK'],
            'waybill without fingerprint'    => [[], 'OK'],
            'legacy: fingerprint, no flag'   => [['fp only'], 'OK'],
            'freshly issued'                 => [['issue'], 'UNPRINTED'],
            'issued and printed'             => [['issue', 'print'], 'OK'],
            'total changed after printing'   => [['issue', 'print', 'new total'], 'STALE'],
            'address changed'                => [['issue', 'new street'], 'STALE'],
            're-issued after a change'       => [['issue', 'print', 'new total', 'issue'], 'UNPRINTED'],
            're-issued unchanged'            => [['issue', 'print', 'issue'], 'UNPRINTED'],
        ];
    }

    /** @dataProvider histories */
    public function test_the_label_state_follows_the_waybill_history(array $steps, string $state): void {
        $o = $this->order();
        foreach ($steps as $step) { $this->apply($o, $step); }
        $this->assertSame(constant('BGCouriers_Labels::LABEL_STATE_' . $state), BGCouriers_Labels::label_state($o));
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
