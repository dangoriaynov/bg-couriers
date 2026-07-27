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
        $this->assertSame('Awaiting delivery to Econt', $t->status);
        $this->assertCount(1, $t->events);
        $this->assertSame('Awaiting delivery to Econt', $t->events[0]['name']);
    }
}
