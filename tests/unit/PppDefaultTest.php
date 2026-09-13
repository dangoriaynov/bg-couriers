<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-econt.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * Which couriers pay cash on delivery out by postal money order (ППП) BY DEFAULT - the answer a shop
 * that has never opened the setting gets. It used to be a list of two ids in the settings reader; the
 * couriers answer for themselves now (ppp_payout_by_default()), and the stored toggle still wins.
 *
 * @group core
 */
final class PppDefaultTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });
        BGCouriers_Couriers::register('econt', 'Econt', static function () { return new BGCouriers_Econt([]); });
        BGCouriers_Couriers::register('pigeon', 'Pigeon Express', static function () { return new BGCouriers_Pigeon([]); });
        BGCouriers_Couriers::register('boxnow', 'BOX NOW', static function () { return new BGCouriers_Boxnow([]); });
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    private function options(array $map): void {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($map) { return $map[$name] ?? $default; });
    }

    public function test_speedy_and_econt_pay_out_by_postal_order_unless_told_otherwise(): void {
        $this->options([]);
        $this->assertTrue(BGCouriers_Settings::courier_ppp_payout('speedy'));
        $this->assertTrue(BGCouriers_Settings::courier_ppp_payout('econt'));
        $this->assertFalse(BGCouriers_Settings::courier_ppp_payout('pigeon'), 'not as standard');
        $this->assertFalse(BGCouriers_Settings::courier_ppp_payout('boxnow'), 'BOX NOW does not do it');
        $this->assertFalse(BGCouriers_Settings::courier_ppp_payout('nobody'), 'a courier the registry does not know is off');
    }

    public function test_the_stored_toggle_wins_over_the_default(): void {
        $this->options(['bgcouriers_speedy_ppp_payout' => 'no', 'bgcouriers_pigeon_ppp_payout' => 'yes']);
        $this->assertFalse(BGCouriers_Settings::courier_ppp_payout('speedy'));
        $this->assertTrue(BGCouriers_Settings::courier_ppp_payout('pigeon'));
    }

    /** The other default that moved: only a courier that SAYS it cannot bill the recipient is forced in total. */
    public function test_only_a_courier_that_cannot_bill_the_recipient_is_forced_in_total(): void {
        $this->options(['bgcouriers_speedy_ship_in_total' => 'no', 'bgcouriers_boxnow_ship_in_total' => 'no', 'bgcouriers_nobody_ship_in_total' => 'no']);
        $this->assertFalse(BGCouriers_Settings::ship_in_total('speedy'), 'Speedy can, so its toggle is honoured');
        $this->assertTrue(BGCouriers_Settings::ship_in_total('boxnow'), 'BOX NOW cannot, whatever the toggle says');
        $this->assertFalse(BGCouriers_Settings::ship_in_total('nobody'), 'unknown to the registry: the stored toggle is read as it is');
        $this->assertFalse(BGCouriers_Couriers::get('boxnow')->recipient_can_pay_delivery());
        $this->assertTrue(BGCouriers_Couriers::get('speedy')->recipient_can_pay_delivery());
    }
}
