<?php
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * "I hand parcels in myself" puts Sameday's PDO tax code on the estimate and the AWB, only for a service whose contract offers it.
 *
 * @group sameday
 */
final class SamedayDropoffTest extends BGCouriers_Unit_TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wc_get_weight')->alias(static fn($v, $u) => $v);
    }

    /** Two services as /api/client/services lists them: 24H offers PDO, LN does not. */
    private function courier(): BGCouriers_Sameday {
        return new class([]) extends BGCouriers_Sameday {
            protected function get_paged(string $path): array {
                return [
                    ['id' => 7, 'serviceCode' => '24', 'serviceOptionalTaxes' => [
                        ['taxCode' => 'OPCG', 'id' => 230071, 'packageType' => 0],
                        ['taxCode' => 'PDO', 'id' => 230078, 'packageType' => 0],
                    ]],
                    ['id' => 15, 'serviceCode' => 'LN', 'serviceOptionalTaxes' => []],
                ];
            }
        };
    }

    public function test_a_service_takes_the_drop_off_only_when_the_shop_hands_in_and_the_contract_offers_it(): void {
        $this->options([]);
        $c = $this->courier();
        $this->assertFalse($c->dropoff_for(7), 'setting off');
        $this->options(['bgcouriers_sameday_dropoff' => 'yes']);
        $this->assertTrue($c->dropoff_for(7));
        $this->assertFalse($c->dropoff_for(15), 'LN has no PDO on this contract');
        $this->assertSame(15, $c->service_id('automat'));
    }

    public function test_the_estimate_carries_the_tax_code_only_when_asked(): void {
        $this->options([]);
        $s = ['method' => 'address', 'site_id' => 110448, 'weight_kg' => 1.0, 'currency' => 'EUR', 'region' => 'Пловдив', 'service_id' => 7];
        $this->assertArrayNotHasKey('serviceTaxes', BGCouriers_Sameday::build_estimate_body($s));
        $this->assertSame(['PDO'], BGCouriers_Sameday::build_estimate_body($s + ['dropoff' => true])['serviceTaxes']);
    }

    public function test_the_awb_carries_the_tax_code_only_when_asked(): void {
        $this->options([]);
        $o = new WC_Order();
        $o->payment_method = 'bacs';
        $o->meta = ['_bgcouriers_method' => 'automat', '_bgcouriers_office_id' => 20007];
        $this->assertArrayNotHasKey('serviceTaxes', BGCouriers_Sameday::build_awb_body($o, 15, 231650));
        $body = BGCouriers_Sameday::build_awb_body($o, 15, 231650, true);
        $this->assertSame(['PDO'], $body['serviceTaxes']);
        $this->assertSame(231650, $body['pickupPoint'], 'the pickup point stays: it is the sender on the AWB');
        $this->assertSame(20007, $body['lockerLastMile']);
    }
}
