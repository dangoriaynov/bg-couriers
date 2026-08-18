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
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';

/**
 * A waybill only says a parcel exists. The courier comes for it on a request that NAMES those waybills
 * for a day - Speedy's own schema puts EXPLICIT_SHIPMENT_ID_LIST first among its scopes and Econt
 * attaches the numbers the same way. These hold the two bodies, and hold the line that couriers without
 * the service refuse loudly rather than pretending.
 *
 * @group core
 */
final class PickupRequestTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        // Sameday's constructor reads its host from the options; the others do not care.
        Functions\when('get_option')->alias(static function ($n, $d = false) { return $d; });
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function opts(): array {
        return ['date' => '2026-08-19', 'from' => '14:00', 'to' => '17:30',
                'contact' => 'ЗЕЛЕНИ ДОБАВКИ ООД', 'phone' => '0886301585', 'weight_kg' => 2.4, 'packs' => 3];
    }

    /** The merchant ticked particular orders; the courier must come for those and not for the account's rest. */
    public function test_speedy_asks_only_for_the_chosen_shipments(): void {
        $b = BGCouriers_Speedy::build_pickup_body(['63710932641', '63710932642'], $this->opts());
        $this->assertSame('EXPLICIT_SHIPMENT_ID_LIST', $b['pickupScope']);
        $this->assertSame(['63710932641', '63710932642'], $b['explicitShipmentIdList']);
    }

    public function test_speedy_sends_the_day_and_the_window(): void {
        $b = BGCouriers_Speedy::build_pickup_body(['63710932641'], $this->opts());
        $this->assertSame('2026-08-19T14:00:00', $b['pickupDateTime']);
        $this->assertSame('17:30', $b['visitEndTime']);
        $this->assertSame('0886301585', $b['phoneNumber']['number']);
    }

    /** Moving the date is Speedy's to offer and not ours to accept: the merchant packs for the day they chose. */
    public function test_speedy_never_lets_the_courier_move_the_date(): void {
        $b = BGCouriers_Speedy::build_pickup_body(['63710932641'], $this->opts());
        $this->assertFalse($b['autoAdjustPickupDate']);
    }

    public function test_speedy_reads_the_order_id_back(): void {
        $this->assertSame('12345', BGCouriers_Speedy::parse_pickup_id(['orders' => [['id' => 12345, 'shipmentIds' => ['63710932641']]]]));
    }

    /** Speedy answers 200 with an error node; a refused pickup must not read as a booked one. */
    public function test_speedy_refusal_is_not_a_booking(): void {
        $this->expectException(BGCouriers_Api_Exception::class);
        BGCouriers_Speedy::parse_pickup_id(['error' => ['message' => 'no courier available']]);
    }

    /** Econt's parser rejects the T: the times are Y-m-d H:i:s, not ISO 8601. */
    public function test_econt_uses_econts_own_time_format(): void {
        $b = BGCouriers_Econt::build_pickup_body(['1055229571533'], $this->opts(), []);
        $this->assertSame('2026-08-19 14:00:00', $b['requestTimeFrom']);
        $this->assertSame('2026-08-19 17:30:00', $b['requestTimeTo']);
        $this->assertStringNotContainsString('T', $b['requestTimeFrom']);
    }

    public function test_econt_attaches_the_waybills(): void {
        $b = BGCouriers_Econt::build_pickup_body(['1055229571533', '1055229571534'], $this->opts(), []);
        $this->assertSame(['1055229571533', '1055229571534'], $b['attachShipments']);
        $this->assertSame(3, $b['shipmentPackCount']);
        $this->assertSame(2.4, $b['shipmentWeight']);
    }

    public function test_econt_reads_the_request_id_back(): void {
        $this->assertSame('CR-99', BGCouriers_Econt::parse_pickup_id(['courierRequestID' => 'CR-99']));
    }

    /** BOX NOW, Pigeon and Sameday have no such API - they must refuse, not quietly do nothing. */
    public function test_couriers_without_the_service_refuse(): void {
        foreach ([new BGCouriers_Pigeon([]), new BGCouriers_Sameday([]), new BGCouriers_Boxnow([])] as $c) {
            $this->assertNotContains('pickup', $c->capabilities(), get_class($c) . ' must not advertise pickup');
            try {
                $c->request_pickup(['X1'], $this->opts());
                $this->fail(get_class($c) . ' should have refused');
            } catch (BGCouriers_Api_Exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * startingDate is MILLISECONDS. The schema says only "integer", and seconds are rejected as a date
     * before today - Speedy reads them as 1970. Verified against the live API on 2026-08-18.
     */
    public function test_speedy_asks_for_terms_in_milliseconds(): void {
        $b = BGCouriers_Speedy::build_pickup_terms_body('2026-08-19');
        $this->assertSame(strtotime('2026-08-19 00:00:00') * 1000, $b['startingDate']);
        $this->assertSame(505, $b['serviceId'], 'without a service id Speedy refuses the question');
    }

    public function test_the_two_that_can_do_it_say_so(): void {
        $this->assertContains('pickup', (new BGCouriers_Speedy([]))->capabilities());
        $this->assertContains('pickup', (new BGCouriers_Econt([]))->capabilities());
    }
}
