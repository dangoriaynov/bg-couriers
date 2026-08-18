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
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-econt.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * Cash on delivery is not free, and the checkout used to quote as though it were: every courier was
 * asked to price a shipment carrying no money, and the customer was charged that answer. Measured live
 * against the shop's own accounts on 2026-08-18, collecting 50 EUR on a 1 kg parcel to Sofia:
 * Econt 5.06 -> 5.84, Pigeon 2.59 -> 3.34, Sameday 1.30 -> 1.80, Speedy 2.10 -> 2.50.
 *
 * Two of the four could not even express it - Sameday's estimate body hardcoded cashOnDelivery to 0 and
 * Econt's quote body never carried cdAmount - so this holds the BODIES, per courier.
 *
 * @group core
 */
final class CodIsPricedTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        Functions\when('get_option')->alias(static function ($n, $d = false) {
            $map = [
                'bgcouriers_econt_cod_enabled' => 'yes',
                'bgcouriers_econt_cd_num'      => 'CD139925',
                'bgcouriers_speedy_ppp_payout' => 'yes',
            ];
            return $map[$n] ?? $d;
        });
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function shipment(float $cod): array {
        return ['method' => 'office', 'site_id' => 41, 'office_id' => 7, 'office_code' => 'SOF7',
                'weight_kg' => 1.0, 'currency' => 'EUR', 'cod_amount' => $cod];
    }

    public function test_speedy_quote_carries_the_collection(): void {
        $b = BGCouriers_Speedy::build_calculate_body($this->shipment(50.0));
        $this->assertSame(50.0, $b['service']['additionalServices']['cod']['amount']);
    }

    /** The quote must ask for the SAME pay-out type the label will use, or it prices another shipment. */
    public function test_speedy_quote_uses_the_shops_payout_type(): void {
        $b = BGCouriers_Speedy::build_calculate_body($this->shipment(50.0));
        $this->assertSame('POSTAL_MONEY_TRANSFER', $b['service']['additionalServices']['cod']['processingType']);
    }

    public function test_speedy_prepaid_sends_no_collection(): void {
        $b = BGCouriers_Speedy::build_calculate_body($this->shipment(0.0));
        $this->assertArrayNotHasKey('cod', $b['service']['additionalServices'] ?? []);
    }

    public function test_pigeon_quote_carries_the_collection(): void {
        $b = BGCouriers_Pigeon::build_calculate_body($this->shipment(50.0), 627);
        $this->assertSame(50.0, $b['service_codes']['cod_amount']);
    }

    public function test_sameday_estimate_carries_the_collection(): void {
        $b = BGCouriers_Sameday::build_estimate_body($this->shipment(50.0) + ['service_id' => 7, 'pickup_point' => 1]);
        $this->assertSame(50.0, $b['cashOnDelivery']);
    }

    public function test_sameday_prepaid_estimate_collects_nothing(): void {
        $b = BGCouriers_Sameday::build_estimate_body($this->shipment(0.0) + ['service_id' => 7, 'pickup_point' => 1]);
        $this->assertSame(0.0, $b['cashOnDelivery']);
    }

    public function test_econt_quote_carries_the_collection(): void {
        $b = BGCouriers_Econt::build_calculate_body($this->shipment(50.0), []);
        $this->assertSame(50.0, $b['label']['services']['cdAmount']);
        $this->assertSame('get', $b['label']['services']['cdType']);
    }

    /**
     * The pay-out agreement carries a discount: Econt charges 1.54 to collect 50 without it and 0.78
     * with this shop's. A quote that omitted it would overcharge for a label that costs less.
     */
    public function test_econt_quote_carries_the_payout_agreement(): void {
        $b = BGCouriers_Econt::build_calculate_body($this->shipment(50.0), []);
        $this->assertSame('CD139925', $b['label']['services']['cdPayOptionsTemplate']);
    }

    public function test_econt_prepaid_quote_has_no_services_block(): void {
        $b = BGCouriers_Econt::build_calculate_body($this->shipment(0.0), []);
        $this->assertArrayNotHasKey('services', $b['label']);
    }
}
