<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-settings.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';

/**
 * "Delivery in the order total" toggle: drives the checkout rate cost (0 when off) AND the waybill
 * payer / COD amount via service_payer(), with back-compat for the removed "Who pays delivery" select.
 *
 * @group core
 */
final class ShipInTotalTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function options(array $map): void {
        Functions\when('get_option')->alias(function ($name, $default = false) use ($map) {
            return $map[$name] ?? $default;
        });
    }

    private static function payer(string $courier): string {
        $m = new ReflectionMethod(BGC_Abstract_Courier::class, 'service_payer');
        $m->setAccessible(true);
        return $m->invoke(null, $courier);
    }

    public function test_defaults_to_included(): void {
        $this->options([]);
        $this->assertTrue(BGC_Settings::ship_in_total('speedy'));
        $this->assertSame('sender', self::payer('speedy'));
    }

    public function test_toggle_off_means_recipient_pays(): void {
        $this->options(['bgc_speedy_ship_in_total' => 'no']);
        $this->assertFalse(BGC_Settings::ship_in_total('speedy'));
        $this->assertSame('recipient', self::payer('speedy'));
    }

    public function test_toggle_on_wins_over_legacy_select(): void {
        $this->options(['bgc_pigeon_ship_in_total' => 'yes', 'bgc_pigeon_service_payer' => 'recipient']);
        $this->assertTrue(BGC_Settings::ship_in_total('pigeon'));
    }

    public function test_legacy_recipient_select_honored_when_toggle_unset(): void {
        $this->options(['bgc_sameday_service_payer' => 'recipient']);
        $this->assertFalse(BGC_Settings::ship_in_total('sameday'));
        $this->assertSame('recipient', self::payer('sameday'));
    }

    public function test_unsupported_couriers_always_included(): void {
        // Econt / BOX NOW have no verified recipient-pays API field - the toggle must not apply.
        $this->options(['bgc_econt_ship_in_total' => 'no', 'bgc_boxnow_ship_in_total' => 'no']);
        $this->assertTrue(BGC_Settings::ship_in_total('econt'));
        $this->assertTrue(BGC_Settings::ship_in_total('boxnow'));
        $this->assertSame('sender', self::payer('econt'));
    }
}
