<?php
// tests/unit/EcontLabelTrackTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-econt.php';

/**
 * Pure parser tests for BGCouriers_Econt label creation and tracking responses.
 *
 * @group econt
 */
final class EcontLabelTrackTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/econt/' . $f), true);
    }

    public function test_parse_shipment_id(): void {
        $resp = $this->fx('create.json');
        $this->assertSame('1055191332613', BGCouriers_Econt::parse_shipment_id($resp));
    }

    public function test_parse_tracking_returns_bgc_tracking(): void {
        $resp = $this->fx('tracking.json');
        $t = BGCouriers_Econt::parse_tracking($resp);
        $this->assertInstanceOf(BGCouriers_Tracking::class, $t);
        $this->assertSame('1055191332613', $t->waybill);
        // Bulgarian is what the merchant reads on the orders screen, so shortDeliveryStatus wins over
        // its En twin. The events keep the English wording - those are matched against phrase lists.
        $this->assertSame('Очаква предаване към Еконт', $t->status);
        $this->assertCount(1, $t->events);
        $this->assertSame('Awaiting delivery to Econt', $t->events[0]['name']);
    }

    /**
     * The fixture is a REAL first-event response: the parcel is still waiting to be handed to Econt
     * (deliveryTime null, event code 'prepared'). It must not read as terminal - on order 11178 it did,
     * and the order was completed a day after it was placed.
     */
    public function test_awaiting_handover_is_not_a_finished_shipment(): void {
        $t = BGCouriers_Econt::parse_tracking($this->fx('tracking.json'));
        $this->assertSame('', $t->phase, 'no deliveryTime -> no phase');
        // Not merely "not delivered": with no sendTime the parcel has not been collected at all, which
        // is its own stage. It is what keeps the waybill editable - a parcel still on the merchant's
        // desk can be corrected and re-issued, one already in transit cannot.
        $this->assertSame('registered', $t->stage());
        $this->assertFalse($t->handover, 'no sendTime -> nobody has picked it up');
    }

    /** deliveryTime is Econt's own delivered flag, and it decides regardless of the prose status. */
    public function test_delivery_time_marks_the_shipment_delivered(): void {
        $resp = $this->fx('tracking.json');
        $resp['shipmentStatuses'][0]['status']['deliveryTime'] = 1785325538000;
        $t = BGCouriers_Econt::parse_tracking($resp);
        $this->assertSame('DELIVERED', $t->phase);
        $this->assertSame('delivered', $t->stage());
    }
}
