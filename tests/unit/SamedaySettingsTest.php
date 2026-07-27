<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * @group sameday
 */
final class SamedaySettingsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        // The adapter constructor reads options (sandbox host) - default-return unless a test overrides.
        Functions\when('get_option')->alias(static function ($name, $default = false) { return $default; });
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('sameday', __('Sameday', 'bg-couriers'), static function () {
            return new BGCouriers_Sameday([]);
        });
    }

    protected function tearDown(): void {
        BGCouriers_Couriers::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_identity_and_capabilities(): void {
        $c = new BGCouriers_Sameday(['username' => 'u', 'password' => 'p']);
        $this->assertSame('sameday', $c->id());
        $this->assertSame('Sameday', $c->label());
        $this->assertSame(['address', 'office', 'automat', 'live_quote'], $c->capabilities());
    }

    public function test_registered_in_registry(): void {
        $this->assertArrayHasKey('sameday', BGCouriers_Couriers::all());
        $this->assertSame('Sameday', BGCouriers_Couriers::all()['sameday']);
    }
}
