<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-labels.php';

/**
 * Printing clears the "not printed yet" flag - through EVERY print route. The per-order print did; the
 * bulk "Print labels" action never touched the flag, so a merchant who prints in batches (the owner
 * does) saw every waybill stay green for good, reload or not - reported 2026-09-22 as "the badges hang
 * around constantly". One shared call now, so the next print route cannot forget either.
 *
 * @group core
 */
final class MarkPrintedTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_every_printed_waybill_loses_its_flag_and_gains_a_timestamp(): void {
        $a = new WC_Order(); $a->meta = ['_bgcouriers_waybill' => 'A1', '_bgcouriers_label_needs_print' => '1'];
        $b = new WC_Order(); $b->meta = ['_bgcouriers_waybill' => 'B2', '_bgcouriers_label_needs_print' => '1'];
        Functions\when('wc_get_order')->alias(static fn($id) => [1 => $a, 2 => $b][(int) $id] ?? false);

        BGCouriers_Labels::mark_printed([1, 2]);

        foreach ([$a, $b] as $o) {
            $this->assertSame('', $o->get_meta('_bgcouriers_label_needs_print'));
            $this->assertGreaterThan(0, (int) $o->get_meta('_bgcouriers_label_printed_at'));
            $this->assertSame(1, $o->saves);
        }
    }

    /** No waybill means nothing was printed for it - a cancelled order in the batch stays as it is. */
    public function test_an_order_without_a_waybill_is_left_alone(): void {
        $o = new WC_Order(); $o->meta = ['_bgcouriers_label_needs_print' => '1'];
        Functions\when('wc_get_order')->alias(static fn($id) => (int) $id === 5 ? $o : false);

        BGCouriers_Labels::mark_printed([5, 99]);

        $this->assertSame('1', $o->get_meta('_bgcouriers_label_needs_print'));
        $this->assertSame(0, $o->saves);
    }
}
