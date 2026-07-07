<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-settings.php';

/**
 * The emergency help phone/message the merchant configures must be read back verbatim —
 * these feed the checkout help box (BGC.emergency) after repeated checkout failures.
 *
 * @group core
 */
final class EmergencyContactTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_emergency_returns_configured_phone_and_message(): void {
        Functions\when('get_option')->alias(function ($option, $default = '') {
            $map = ['bgc_emergency_phone' => '+359 88 123 4567', 'bgc_emergency_message' => 'Обадете ни се на телефона'];
            return $map[$option] ?? $default;
        });
        $e = BGC_Settings::emergency();
        $this->assertSame('+359 88 123 4567', $e['phone']);
        $this->assertSame('Обадете ни се на телефона', $e['message']);
    }

    public function test_emergency_empty_by_default(): void {
        Functions\when('get_option')->alias(function ($option, $default = '') { return $default; });
        $e = BGC_Settings::emergency();
        $this->assertSame('', $e['phone']);
        $this->assertSame('', $e['message']);
    }
}
