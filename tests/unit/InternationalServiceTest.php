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
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * Which service, which payer, which country id - the three things a parcel leaving the country changes.
 *
 * Measured against Speedy 2026-08-19 with a 1 kg parcel to office 926 (Bucharest): service 505 is
 * refused for a Romanian destination and 202 for a Bulgarian one, and 202 refuses a RECIPIENT payer.
 * These tests hold the plugin to those answers, so a wrong service can never be posted quietly.
 *
 * @group speedy
 */
final class InternationalServiceTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function body(string $country, string $method = 'address'): array {
        return BGCouriers_Speedy::build_calculate_body([
            'method' => $method, 'site_id' => 68134, 'office_id' => 926,
            'weight_kg' => 1.0, 'cod_amount' => 0.0, 'currency' => 'EUR', 'country' => $country,
        ]);
    }

    private function body_cod(string $country): array {
        return BGCouriers_Speedy::build_calculate_body([
            'method' => 'office', 'site_id' => 68134, 'office_id' => 926,
            'weight_kg' => 1.0, 'cod_amount' => 50.0, 'currency' => 'EUR', 'country' => $country,
        ]);
    }

    public function test_a_domestic_quote_is_exactly_what_it_always_was(): void {
        foreach (['', 'BG', 'bg'] as $home) {
            $body = $this->body($home);
            $this->assertSame([505], $body['service']['serviceIds'], 'home service');
            $this->assertSame('RECIPIENT', $body['payment']['courierServicePayer'], 'home payer');
            $this->assertSame(100, $body['recipient']['addressLocation']['countryId'], 'home country id');
        }
    }

    public function test_a_romanian_quote_switches_service_payer_and_country(): void {
        $body = $this->body('RO');
        $this->assertSame([202], $body['service']['serviceIds']);
        $this->assertSame('SENDER', $body['payment']['courierServicePayer']);
        $this->assertSame(642, $body['recipient']['addressLocation']['countryId']);
    }

    public function test_an_office_abroad_also_gets_the_international_service(): void {
        $body = $this->body('RO', 'office');
        $this->assertSame([202], $body['service']['serviceIds']);
        $this->assertSame(926, $body['recipient']['pickupOfficeId']);
        $this->assertSame('SENDER', $body['payment']['courierServicePayer']);
    }

    /**
     * Cash on delivery abroad is collected as CASH, never as a postal money transfer.
     *
     * ППП is a Bulgarian instrument and Speedy refuses it for a foreign address - the whole calculation
     * comes back with no price at all (sla.cod.moneyTransfer.cod_sub_service_validator.money-transfer-
     * not-allowed-for-foreign-countries, measured 2026-08-19 on a 1 kg parcel to office 901, Sibiu).
     * With CASH the same parcel prices fine, so this is one field between "Romania works" and "Speedy
     * silently stops being offered the moment the customer picks it".
     */
    public function test_cash_on_delivery_abroad_is_collected_as_cash(): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) {
            return $n === 'bgcouriers_speedy_ppp_payout' ? 'yes' : $d;   // the merchant's own contract, at home
        });
        $body = $this->body_cod('RO');
        $this->assertSame('CASH', $body['service']['additionalServices']['cod']['processingType']);
        $this->assertSame(50.0, $body['service']['additionalServices']['cod']['amount'], 'the money is still collected');
    }

    /** And at home the merchant's own arrangement is untouched - this must change nothing domestically. */
    public function test_cash_on_delivery_at_home_still_uses_the_shops_payout(): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) {
            return $n === 'bgcouriers_speedy_ppp_payout' ? 'yes' : $d;
        });
        foreach (['', 'BG'] as $home) {
            $body = $this->body_cod($home);
            $this->assertSame('POSTAL_MONEY_TRANSFER', $body['service']['additionalServices']['cod']['processingType']);
        }
    }

    public function test_a_country_speedy_was_never_measured_for_is_refused_not_guessed(): void {
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->body('DE');
    }

    public function test_the_service_map_is_the_measured_pair(): void {
        $this->assertSame(505, BGCouriers_Speedy::service_id(''));
        $this->assertSame(505, BGCouriers_Speedy::service_id('BG'));
        $this->assertSame(202, BGCouriers_Speedy::service_id('ro'));
        $this->assertSame(642, BGCouriers_Speedy::country_id('RO'));
        $this->assertSame(0, BGCouriers_Speedy::country_id('DE'));
    }

    /**
     * International delivery is switched off in the plugin as it ships (see intl_enabled()), so the tests
     * that hold the machinery to its measured answers turn it on the way a site would.
     */
    private function intl_on(): void {
        Functions\when('apply_filters')->alias(function ($hook, $value = null) {
            return $hook === 'bgcouriers_intl_enabled' ? true : $value;
        });
    }

    public function test_ships_to_is_home_plus_what_the_merchant_switched_on(): void {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $name === 'bgcouriers_speedy_intl_countries' ? ['RO'] : $default;
        });
        $this->intl_on();
        $speedy = new BGCouriers_Speedy([]);
        $this->assertTrue(BGCouriers_Settings::ships_to('speedy', 'BG', $speedy));
        $this->assertTrue(BGCouriers_Settings::ships_to('speedy', 'RO', $speedy));
        $this->assertFalse(BGCouriers_Settings::ships_to('speedy', 'DE', $speedy), 'not offered by the courier');
        $this->assertFalse(BGCouriers_Settings::is_intl('BG'));
        $this->assertTrue(BGCouriers_Settings::is_intl('RO'));
    }

    public function test_a_courier_that_goes_nowhere_else_ships_home_only(): void {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            // Even with a country saved against it: an option left behind by a courier that cannot
            // deliver there must not keep quoting it.
            return $name === 'bgcouriers_boxnow_intl_countries' ? ['RO'] : $default;
        });
        $this->intl_on();
        // Only what the question needs: the courier is asked one thing here, what countries it offers.
        $co = new class { public function intl_countries(): array { return []; } };
        $this->assertTrue(BGCouriers_Settings::ships_to('boxnow', 'BG', $co));
        $this->assertFalse(BGCouriers_Settings::ships_to('boxnow', 'RO', $co));
        $this->assertSame(['BG'], BGCouriers_Settings::delivery_countries('boxnow', $co));
    }

    /**
     * What actually ships: nothing leaves the country. The parts above are built and measured, but the
     * feature is not finished (docs/international-shipping.md), so it is off unless a site turns the
     * filter on - and off means off even for a shop that has picked a country and put this courier's
     * method in that country's shipping zone. Every method asks ships_to() before it quotes.
     */
    public function test_nothing_leaves_the_country_while_the_feature_is_unfinished(): void {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $name === 'bgcouriers_speedy_intl_countries' ? ['RO'] : $default;
        });
        Functions\when('apply_filters')->returnArg(2);   // no site has said otherwise
        $speedy = new BGCouriers_Speedy([]);
        $this->assertFalse(BGCouriers_Settings::intl_enabled());
        $this->assertSame([], BGCouriers_Settings::intl_countries('speedy', $speedy), 'the saved choice is kept, not offered');
        $this->assertFalse(BGCouriers_Settings::ships_to('speedy', 'RO', $speedy));
        $this->assertTrue(BGCouriers_Settings::ships_to('speedy', 'BG', $speedy), 'home is untouched');
        $this->assertSame(['BG'], BGCouriers_Settings::delivery_countries('speedy', $speedy));
    }
}
