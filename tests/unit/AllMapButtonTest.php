<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';

/**
 * The "all offices on a map" button only makes sense when somebody actually delivers to a pickup
 * point. A store whose couriers all deliver to the door would otherwise get a button opening a
 * dialog with nothing to plot.
 *
 * @group core
 */
final class AllMapButtonTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $opts */
    private function opts(array $opts): void {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($opts) {
            return array_key_exists($name, $opts) ? $opts[$name] : $default;
        });
    }

    public function test_shown_when_an_enabled_courier_delivers_to_an_office(): void {
        $this->opts([
            'bgcouriers_speedy_enabled'         => 'yes',
            'bgcouriers_speedy_office_enabled'  => 'yes',
            'bgcouriers_speedy_address_enabled' => 'yes',
            'bgcouriers_speedy_automat_enabled' => 'no',
        ]);
        $this->assertTrue(BGCouriers_Checkout::has_pickup_courier(['speedy']));
    }

    public function test_shown_for_a_locker_only_courier(): void {
        $this->opts([
            'bgcouriers_boxnow_enabled'         => 'yes',
            'bgcouriers_boxnow_office_enabled'  => 'no',
            'bgcouriers_boxnow_address_enabled' => 'no',
            'bgcouriers_boxnow_automat_enabled' => 'yes',
        ]);
        $this->assertTrue(BGCouriers_Checkout::has_pickup_courier(['boxnow']));
    }

    /** Door delivery only: there is nothing to put on a map. */
    public function test_hidden_when_everyone_delivers_only_to_the_door(): void {
        $this->opts([
            'bgcouriers_speedy_enabled'         => 'yes',
            'bgcouriers_speedy_office_enabled'  => 'no',
            'bgcouriers_speedy_address_enabled' => 'yes',
            'bgcouriers_speedy_automat_enabled' => 'no',
        ]);
        $this->assertFalse(BGCouriers_Checkout::has_pickup_courier(['speedy']));
    }

    /** A courier that is switched off does not get a say, whatever it can carry. */
    public function test_a_disabled_courier_does_not_count(): void {
        $this->opts([
            'bgcouriers_speedy_enabled'         => 'no',
            'bgcouriers_speedy_office_enabled'  => 'yes',
            'bgcouriers_speedy_automat_enabled' => 'yes',
        ]);
        $this->assertFalse(BGCouriers_Checkout::has_pickup_courier(['speedy']));
    }

    public function test_no_couriers_at_all(): void {
        $this->opts([]);
        $this->assertFalse(BGCouriers_Checkout::has_pickup_courier([]));
    }
}
