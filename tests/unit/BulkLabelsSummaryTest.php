<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-bulk-labels.php';

/**
 * @group speedy
 */
final class BulkLabelsSummaryTest extends TestCase {
    public function test_summary_tallies_statuses(): void {
        $c = BGC_Bulk_Labels::summary(['generated', 'reused', 'generated', 'skipped', 'failed']);
        $this->assertSame(['generated' => 2, 'reused' => 1, 'skipped' => 1, 'failed' => 1], $c);
    }
}
