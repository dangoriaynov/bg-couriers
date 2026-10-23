<?php
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
 * Courier pickup requests name the chosen waybills for a day; couriers without the service refuse loudly.
 *
 * @group core
 */
final class PickupRequestTest extends BGCouriers_Unit_TestCase {
    protected function setUp(): void {
        parent::setUp();        Functions\when('esc_html__')->returnArg(1);
        // Sameday's constructor reads its host from the options; the others do not care.
        Functions\when('get_option')->alias(static function ($n, $d = false) { return $d; });
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Sofia')); // the shop's zone
    }

    private function opts(): array {
        return ['date' => '2026-08-19', 'from' => '14:00', 'to' => '17:30',
                'contact' => 'ПРИМЕР ООД', 'phone' => '0888123456', 'weight_kg' => 2.4, 'packs' => 3];
    }

    /** The merchant ticked particular orders; the courier must come for those and not for the account's rest. */
    public function test_speedy_asks_only_for_the_chosen_shipments(): void {
        $b = BGCouriers_Speedy::build_pickup_body(['63710932641', '63710932642'], $this->opts());
        $this->assertSame('EXPLICIT_SHIPMENT_ID_LIST', $b['pickupScope']);
        $this->assertSame(['63710932641', '63710932642'], $b['explicitShipmentIdList']);
    }

    /** pickupDateTime must carry the shop's offset; the merchant's day is never auto-adjusted. */
    public function test_speedy_sends_the_day_and_the_window(): void {
        $b = BGCouriers_Speedy::build_pickup_body(['63710932641'], $this->opts());
        $this->assertFalse($b['autoAdjustPickupDate']);
        $this->assertSame('2026-08-19T14:00:00+0300', $b['pickupDateTime'], 'August in Sofia is +0300');
        $this->assertSame('17:30', $b['visitEndTime']);
        $this->assertSame('0888123456', $b['phoneNumber']['number']);

        $b = BGCouriers_Speedy::build_pickup_body(['63710932641'], ['date' => '2026-12-01', 'from' => '09:30'] + $this->opts());
        $this->assertSame('2026-12-01T09:30:00+0200', $b['pickupDateTime'], 'December in Sofia is +0200');
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

    /** @return array<string,array{string,bool}> */
    public static function pickup_support(): array {
        return ['speedy' => ['Speedy', true], 'econt' => ['Econt', true], 'pigeon' => ['Pigeon', false], 'sameday' => ['Sameday', false], 'boxnow' => ['Boxnow', false]];
    }

    /** @dataProvider pickup_support */
    public function test_only_couriers_with_a_pickup_api_advertise_it_and_the_rest_refuse(string $courier, bool $supported): void {
        $class = 'BGCouriers_' . $courier;
        $c = new $class([]);
        $this->assertSame($supported, in_array('pickup', $c->capabilities(), true));
        if (!$supported) {
            $this->expectException(BGCouriers_Api_Exception::class);
            $this->expectExceptionMessageMatches('/\S/');
            $c->request_pickup(['X1'], $this->opts());
        }
    }

    /** startingDate is milliseconds; seconds read as 1970. */
    public function test_speedy_asks_for_terms_in_milliseconds(): void {
        $b = BGCouriers_Speedy::build_pickup_terms_body('2026-08-19');
        $this->assertSame(strtotime('2026-08-19 00:00:00') * 1000, $b['startingDate']);
        $this->assertSame(505, $b['serviceId'], 'without a service id Speedy refuses the question');
    }
}
