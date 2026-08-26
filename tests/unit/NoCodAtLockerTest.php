<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-expressone.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * A courier may collect money from a person and not from a machine.
 *
 * Express One carries no наложен платеж to an EXOBOX locker (their own words, 2026-08-26) and its API
 * will not say so - /1/create-bol accepts COD beside TAKE_OFFICE_ID exactly as happily as it accepts it
 * for a courier delivery. So the refusal is ours to make, and it has to hold in three places or it
 * leaks: the checkout takes the gateway away, the quote stops paying for a collection that cannot
 * happen, and the waybill refuses to be booked at all.
 *
 * The rule is asked per courier and per delivery kind - NOT of every locker. BOX NOW's lockers and
 * Econt's automats do take cash on delivery, and a rule written as "no locker collects money" would
 * have switched cash on delivery off for half the shop.
 *
 * @group core
 */
final class NoCodAtLockerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('expressone', 'Express One', static function () { return new BGCouriers_Expressone([]); });
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    private function options(array $map): void {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($map) {
            return $map[$name] ?? $default;
        });
    }

    public function test_express_one_declares_its_locker(): void {
        $this->assertSame(['automat'], (new BGCouriers_Expressone([]))->no_cod_methods());
    }

    /** Every other courier collects cash wherever it delivers - the default must stay empty. */
    public function test_a_courier_that_has_not_said_otherwise_declares_nothing(): void {
        $this->assertSame([], (new BGCouriers_Speedy([]))->no_cod_methods());
    }

    public function test_cash_on_delivery_is_refused_at_the_express_one_locker(): void {
        $this->options(['bgcouriers_cod_fiscalization' => 'cash_register']);
        $this->assertFalse(BGCouriers_Settings::cod_allowed_for('expressone', 'automat'));
    }

    /** The courier itself is fine - it is the locker that collects nothing. */
    public function test_the_same_courier_still_takes_cash_at_an_office_or_an_address(): void {
        $this->options(['bgcouriers_cod_fiscalization' => 'cash_register']);
        $this->assertTrue(BGCouriers_Settings::cod_allowed_for('expressone', 'office'));
        $this->assertTrue(BGCouriers_Settings::cod_allowed_for('expressone', 'address'));
    }

    /** Asked without a delivery kind (the admin, a courier-wide question), only the shop's own rule applies. */
    public function test_no_delivery_kind_means_only_the_fiscalisation_rule(): void {
        $this->options(['bgcouriers_cod_fiscalization' => 'cash_register']);
        $this->assertTrue(BGCouriers_Settings::cod_allowed_for('expressone'));
    }

    /** The older rule is untouched: no cash register and no ППП from this courier = no cash on delivery. */
    public function test_the_fiscalisation_rule_still_refuses_on_its_own(): void {
        $this->options([
            'bgcouriers_cod_fiscalization'      => 'ppp',
            'bgcouriers_expressone_ppp_payout'  => 'no',
        ]);
        $this->assertFalse(BGCouriers_Settings::cod_allowed_for('expressone', 'office'));
    }

    /** A locker is not a locker: BOX NOW and Econt automats do collect, so nothing may be assumed of 'automat'. */
    public function test_another_couriers_locker_is_not_touched(): void {
        $this->options(['bgcouriers_cod_fiscalization' => 'cash_register']);
        $this->assertSame([], BGCouriers_Settings::no_cod_methods('speedy'));
        $this->assertTrue(BGCouriers_Settings::cod_allowed_for('speedy', 'automat'));
    }

    /**
     * "At the moment" is what the courier said, so the rule has an expiry date on it and a shop that
     * hears of its lifting before we do can act on that without waiting for a release.
     */
    public function test_a_filter_can_lift_the_rule(): void {
        $this->options(['bgcouriers_cod_fiscalization' => 'cash_register']);
        Filters\expectApplied('bgcouriers_no_cod_methods')->andReturn([]);
        $this->assertTrue(BGCouriers_Settings::cod_allowed_for('expressone', 'automat'));
    }
}
