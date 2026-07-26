<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-bulk-labels.php';

/**
 * @group speedy
 */
final class BulkLabelsSummaryTest extends TestCase {
    public function test_summary_tallies_statuses(): void {
        $c = BGC_Bulk_Labels::summary(['generated', 'reused', 'generated', 'skipped', 'failed']);
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
        $added = array_keys(array_diff_key((new BGC_Bulk_Labels_Registrar())->registered(), ['keep' => 1]));
        Monkey\tearDown();
        $this->assertSame(BGC_Bulk_Labels::actions(), $added);
    }
}

/** Calls register() without the constructor's add_filter side effects. */
final class BGC_Bulk_Labels_Registrar {
    public function registered(): array {
        $r = new ReflectionClass('BGC_Bulk_Labels');
        $m = $r->getMethod('register');
        return $m->invoke($r->newInstanceWithoutConstructor(), ['keep' => 'WooCommerce action']);
    }
}
