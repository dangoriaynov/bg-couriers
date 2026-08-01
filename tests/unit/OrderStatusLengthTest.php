<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-status.php';

/**
 * HPOS stores the order status in wp_wc_orders.status - a varchar(20) - and MySQL truncates anything
 * longer silently. The first version of this status was 'wc-bgcouriers-shipped', 21 characters, so every
 * order it was applied to was written as 'wc-bgcouriers-shippe': a value matching no registered status.
 * Those orders disappeared from wc_get_orders(), which meant the tracking poller never looked at them
 * again and they sat in the admin showing whatever the courier had last said days earlier.
 *
 * @group core
 */
final class OrderStatusLengthTest extends TestCase {
    /** The one rule that would have caught it. */
    public function test_status_key_fits_the_hpos_column(): void {
        $this->assertLessThanOrEqual(20, strlen(BGCouriers_Order_Status::STATUS),
            'wp_wc_orders.status is varchar(20) and truncates silently');
    }

    public function test_slug_is_the_status_without_the_wc_prefix(): void {
        $this->assertSame('wc-' . BGCouriers_Order_Status::SLUG, BGCouriers_Order_Status::STATUS);
    }

    /** ASCII only, no spaces - it goes into URLs, CSS classes and bulk-action values. */
    public function test_status_key_is_url_and_class_safe(): void {
        $this->assertMatchesRegularExpression('/^wc-[a-z0-9-]+$/', BGCouriers_Order_Status::STATUS);
    }

    /** The broken value has to stay recognisable, or the orders written with it cannot be repaired. */
    public function test_the_truncated_legacy_value_is_recorded(): void {
        $this->assertSame(20, strlen(BGCouriers_Order_Status::LEGACY_STATUS));
        $this->assertNotSame(BGCouriers_Order_Status::STATUS, BGCouriers_Order_Status::LEGACY_STATUS);
    }
}
