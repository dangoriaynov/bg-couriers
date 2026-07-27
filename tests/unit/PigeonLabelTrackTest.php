<?php
// tests/unit/PigeonLabelTrackTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';

/**
 * Pure parser tests for BGCouriers_Pigeon label creation and tracking responses.
 *
 * @group pigeon
 */
final class PigeonLabelTrackTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pigeon/' . $f), true);
    }

    public function test_parse_shipment_id_returns_reference_number(): void {
        $resp = $this->fx('create.json');
        $this->assertSame('455791936383', BGCouriers_Pigeon::parse_shipment_id($resp));
    }

    public function test_parse_tracking_returns_bgc_tracking_instance(): void {
        $resp = $this->fx('track.json');
        $t    = BGCouriers_Pigeon::parse_tracking($resp);
        $this->assertInstanceOf(BGCouriers_Tracking::class, $t);
    }

    public function test_parse_tracking_waybill(): void {
        $resp = $this->fx('track.json');
        $t    = BGCouriers_Pigeon::parse_tracking($resp);
        $this->assertSame('455791936383', $t->waybill);
    }

    public function test_parse_tracking_status(): void {
        $resp = $this->fx('track.json');
        $t    = BGCouriers_Pigeon::parse_tracking($resp);
        $this->assertSame('Доставена', $t->status);
    }

    public function test_parse_tracking_event_count(): void {
        $resp = $this->fx('track.json');
        $t    = BGCouriers_Pigeon::parse_tracking($resp);
        $this->assertCount(2, $t->events);
    }

    public function test_parse_tracking_first_event_fields(): void {
        $resp = $this->fx('track.json');
        $t    = BGCouriers_Pigeon::parse_tracking($resp);
        $e0   = $t->events[0];
        $this->assertSame('registered', $e0['code']);
        $this->assertSame('Регистрирана пратка', $e0['name']);
        $this->assertSame('2026-02-26T17:17:15+02:00', $e0['date']);
    }

    public function test_parse_tracking_second_event_fields(): void {
        $resp = $this->fx('track.json');
        $t    = BGCouriers_Pigeon::parse_tracking($resp);
        $e1   = $t->events[1];
        $this->assertSame('delivered', $e1['code']);
        $this->assertSame('Доставена', $e1['name']);
        $this->assertSame('2026-02-27T11:20:00+02:00', $e1['date']);
    }
}
