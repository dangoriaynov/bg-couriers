<?php
use PHPUnit\Framework\TestCase;

// BGC_Method_Speedy extends WC_Shipping_Method; stub it so the file loads without WooCommerce.
if (!class_exists('WC_Shipping_Method')) { class WC_Shipping_Method {} }
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgc-method-speedy.php';

final class FreeShippingTest extends TestCase {
    public function test_free_at_or_above_threshold(): void {
        $mc = ['free_enabled' => true, 'free_threshold' => 50.0];
        $this->assertTrue(BGC_Method_Speedy::is_free(50.0, $mc));   // exactly at threshold
        $this->assertTrue(BGC_Method_Speedy::is_free(75.0, $mc));   // above
    }
    public function test_not_free_below_threshold(): void {
        $this->assertFalse(BGC_Method_Speedy::is_free(49.99, ['free_enabled' => true, 'free_threshold' => 50.0]));
    }
    public function test_not_free_when_disabled_or_zero_threshold(): void {
        $this->assertFalse(BGC_Method_Speedy::is_free(100.0, ['free_enabled' => false, 'free_threshold' => 50.0]));
        $this->assertFalse(BGC_Method_Speedy::is_free(100.0, ['free_enabled' => true, 'free_threshold' => 0.0]));
    }
}
