<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-labels.php';

/**
 * @group speedy
 */
final class BatchParcelIdsTest extends TestCase {
    public function test_maps_and_skips_blanks(): void {
        $resolver = fn($id) => ['10' => 'W10', '11' => '', '12' => 'W12'][(string) $id] ?? '';
        $this->assertSame(['W10', 'W12'], BGC_Labels::batch_parcel_ids([10, 11, 12], $resolver));
    }
}
