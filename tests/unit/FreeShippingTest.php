<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

// BGC_Method_Speedy extends WC_Shipping_Method; stub it so the file loads without WooCommerce.
if (!class_exists('WC_Shipping_Method')) { class WC_Shipping_Method {} }
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgc-method-speedy.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-settings.php';

/**
 * @group speedy
 */
final class FreeShippingTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_is_free_at_or_above_threshold(): void {
        $cfg = ['enabled' => true, 'threshold' => 50.0];
        $this->assertTrue(BGC_Method_Speedy::is_free(50.0, $cfg));   // exactly at threshold
        $this->assertTrue(BGC_Method_Speedy::is_free(75.0, $cfg));   // above
    }
    public function test_is_free_below_threshold(): void {
        $this->assertFalse(BGC_Method_Speedy::is_free(49.99, ['enabled' => true, 'threshold' => 50.0]));
    }
    public function test_is_free_disabled_or_zero_threshold(): void {
        $this->assertFalse(BGC_Method_Speedy::is_free(100.0, ['enabled' => false, 'threshold' => 50.0]));
        $this->assertFalse(BGC_Method_Speedy::is_free(100.0, ['enabled' => true, 'threshold' => 0.0]));
    }

    public function test_free_shipping_auto_enabled_by_positive_threshold(): void {
        // No on/off flag - a positive threshold alone enables free shipping.
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return ['bgc_speedy_free_threshold' => '60'][$name] ?? $default;
        });
        $f = BGC_Settings::free_shipping('speedy');
        $this->assertTrue($f['enabled']);
        $this->assertSame(60.0, $f['threshold']);
    }
    public function test_free_shipping_accessor_defaults_to_off(): void {
        Functions\when('get_option')->alias(function ($name, $default = false) { return $default; });
        $f = BGC_Settings::free_shipping('speedy');
        $this->assertFalse($f['enabled']);
        $this->assertSame(0.0, $f['threshold']);
    }

    public function test_courier_level_threshold_overrides_the_per_method_one(): void {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return ['bgc_speedy_free_threshold' => '50', 'bgc_speedy_office_free_threshold' => '30'][$name] ?? $default;
        });
        $this->assertSame(50.0, BGC_Settings::free_shipping('speedy', 'office')['threshold']);
    }

    public function test_per_method_threshold_applies_only_when_courier_level_is_empty(): void {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return ['bgc_speedy_office_free_threshold' => '30'][$name] ?? $default;
        });
        $f = BGC_Settings::free_shipping('speedy', 'office');
        $this->assertTrue($f['enabled']);
        $this->assertSame(30.0, $f['threshold']);
        // A sibling method without its own threshold stays paid.
        $this->assertFalse(BGC_Settings::free_shipping('speedy', 'address')['enabled']);
    }
}
