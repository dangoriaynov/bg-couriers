<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-bulk-labels.php';

/**
 * @group speedy
 */
final class BulkLabelsSummaryTest extends TestCase {
    public function test_summary_tallies_statuses(): void {
        $c = BGCouriers_Bulk_Labels::summary(['generated', 'reused', 'generated', 'skipped', 'failed']);
        $this->assertSame(['generated' => 2, 'reused' => 1, 'skipped' => 1, 'failed' => 1], $c);
    }

    /**
     * actions() drives which dropdown options the JS pulls into our <optgroup>. If a bulk action is ever
     * added to register() without being listed there it would stay loose outside the group, so hold the
     * two lists to the same set AND the same order (the group renders in actions() order).
     */
    public function test_actions_list_matches_everything_register_adds(): void {
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        $added = array_keys(array_diff_key((new BGCouriers_Bulk_Labels_Registrar())->registered(), ['keep' => 1]));
        Monkey\tearDown();
        $this->assertSame(BGCouriers_Bulk_Labels::actions(), $added);
    }

    /**
     * The "Print N on A4 / A6" links after a bulk generate or re-issue carry the batch's order ids, so the
     * list script can turn those rows' green print tiles back the moment one is clicked (the PDF opens
     * in another tab and the list never reloads). The ids come from the same transient the print handler
     * reads. And the attribute has to be on the kses allow-list, or it is stripped at output.
     */
    public function test_the_batch_print_links_name_their_orders(): void {
        Monkey\setUp();
        foreach (['__', 'esc_html__', 'esc_attr__'] as $f) { Functions\when($f)->returnArg(1); }
        foreach (['esc_html', 'esc_attr', 'esc_url', 'wp_kses'] as $f) { Functions\when($f)->returnArg(1); }
        Functions\when('get_current_user_id')->justReturn(9);
        Functions\when('admin_url')->alias(static fn($p) => 'http://x/' . $p);
        Functions\when('wp_nonce_url')->returnArg(1);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_transient')->alias(static fn($k) => $k === 'bgcouriers_bulk_notice_9'
            ? ['kind' => 'generate', 'generated' => 2, 'print' => true]
            : ($k === 'bgcouriers_print_batch_9' ? [3, 4] : false));
        ob_start(); (new BGCouriers_Bulk_Labels_Registrar())->notice(); $h = ob_get_clean();
        Monkey\tearDown();

        $this->assertSame(2, substr_count($h, 'data-ids="3,4"'), 'both the A4 and the A6 link');
        require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-kses.php';
        $this->assertArrayHasKey('data-ids', BGCouriers_Kses::admin_actions()['a']);
    }
}

/** Calls register() without the constructor's add_filter side effects. */
final class BGCouriers_Bulk_Labels_Registrar {
    public function notice(): void {
        (new ReflectionClass('BGCouriers_Bulk_Labels'))->newInstanceWithoutConstructor()->notice();
    }
    public function registered(): array {
        $r = new ReflectionClass('BGCouriers_Bulk_Labels');
        $m = $r->getMethod('register');
        return $m->invoke($r->newInstanceWithoutConstructor(), ['keep' => 'WooCommerce action']);
    }
}
