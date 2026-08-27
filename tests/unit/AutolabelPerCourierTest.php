<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * Whether a waybill is issued by itself is asked per courier, not once for the shop.
 *
 * Creating a waybill does not mean the same thing to every courier. To Sameday it means "the parcel
 * exists, come and get it": on 2026-08-26 a waybill issued four seconds after checkout brought its
 * courier to the door the same morning, for a parcel the shop was not sending until the next day, and
 * the courier voided it on the spot. Speedy and Econt are asked to come in a separate request, so an
 * early waybill costs them nothing - and a shop should not have to give up automation everywhere to be
 * safe with one of them.
 *
 * @group core
 */
final class AutolabelPerCourierTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function options(array $map): void {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($map) {
            return $map[$name] ?? $default;
        });
    }

    public function test_a_courier_that_has_said_nothing_follows_the_general_setting(): void {
        $this->options(['bgcouriers_autolabel_enabled' => 'yes']);
        $this->assertTrue(BGCouriers_Settings::autolabel_for('speedy'));
        $this->options(['bgcouriers_autolabel_enabled' => 'no']);
        $this->assertFalse(BGCouriers_Settings::autolabel_for('speedy'));
    }

    /** The case this exists for: everything automatic except the courier that comes straight away. */
    public function test_one_courier_can_be_switched_off_while_the_rest_stay_on(): void {
        $this->options([
            'bgcouriers_autolabel_enabled'  => 'yes',
            'bgcouriers_sameday_autolabel'  => 'no',
        ]);
        $this->assertFalse(BGCouriers_Settings::autolabel_for('sameday'));
        $this->assertTrue(BGCouriers_Settings::autolabel_for('speedy'));
        $this->assertTrue(BGCouriers_Settings::autolabel_for('econt'));
    }

    /** ...and the other way round: off for the shop, on for the one courier that is safe. */
    public function test_one_courier_can_be_switched_on_while_the_shop_is_off(): void {
        $this->options([
            'bgcouriers_autolabel_enabled' => 'no',
            'bgcouriers_speedy_autolabel'  => 'yes',
        ]);
        $this->assertTrue(BGCouriers_Settings::autolabel_for('speedy'));
        $this->assertFalse(BGCouriers_Settings::autolabel_for('sameday'));
    }

    /**
     * "Not said" and "no" are different answers, which is why the per-courier field is a select and not
     * a checkbox: turning the general setting on later must reach the couriers nobody spoke about.
     */
    public function test_an_empty_answer_is_not_a_no(): void {
        $this->options([
            'bgcouriers_autolabel_enabled' => 'yes',
            'bgcouriers_pigeon_autolabel'  => '',
        ]);
        $this->assertTrue(BGCouriers_Settings::autolabel_for('pigeon'));
    }

    /** Anything that is not yes/no is not an answer either - a stale or hand-edited value inherits. */
    public function test_a_value_that_means_nothing_inherits(): void {
        $this->options([
            'bgcouriers_autolabel_enabled' => 'yes',
            'bgcouriers_econt_autolabel'   => 'maybe',
        ]);
        $this->assertTrue(BGCouriers_Settings::autolabel_for('econt'));
    }
}
