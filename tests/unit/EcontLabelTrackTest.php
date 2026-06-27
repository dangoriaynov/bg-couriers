<?php
// tests/unit/EcontLabelTrackTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-econt.php';

/**
 * Pure parser tests for BGC_Econt label creation and tracking responses.
 *
 * @group econt
 */
final class EcontLabelTrackTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/econt/' . $f), true);
    }

    public function test_parse_shipment_id(): void {
        $resp = $this->fx('create.json');
        $this->assertSame('1055191332613', BGC_Econt::parse_shipment_id($resp));
    }

    public function test_parse_tracking_returns_bgc_tracking(): void {
        $resp = $this->fx('tracking.json');
        $t = BGC_Econt::parse_tracking($resp);
        $this->assertInstanceOf(BGC_Tracking::class, $t);
        $this->assertSame('1055191332613', $t->waybill);
        $this->assertSame('Awaiting delivery to Econt', $t->status);
        $this->assertCount(1, $t->events);
        $this->assertSame('Awaiting delivery to Econt', $t->events[0]['name']);
    }
}
