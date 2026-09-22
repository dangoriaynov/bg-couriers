<?php
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-status.php';

/**
 * WooCommerce's legacy Reports > Sales by date builds its refund sub-queries with 'order_status' => false
 * ("no status clause, filter on the parent order instead") and still runs that value through
 * `woocommerce_reports_order_statuses`. A WP.org user reported the result on 2026-09-22: the report page
 * died with "add_to_reports(): Argument #1 ($statuses) must be of type array, false given".
 *
 * The false has to come back untouched: coercing it into [our status] would put a status clause into a
 * query that deliberately has none, and the report would silently count zero refunds.
 *
 * @group core
 */
final class ReportStatusFilterTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_the_no_filter_marker_passes_through_untouched(): void {
        $this->assertFalse((new BGCouriers_Order_Status())->add_to_reports(false));
    }

    public function test_a_status_list_gains_shipped_at_the_end(): void {
        $this->assertSame(['completed', 'processing', BGCouriers_Order_Status::SLUG],
            (new BGCouriers_Order_Status())->add_to_reports(['completed', 'processing']));
    }
}
