<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-speedy.php';

final class SpeedyLabelTrackTest extends TestCase {
    public function test_parse_shipment_id(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/shipment.json'), true);
        $this->assertSame('299999990', BGC_Speedy::parse_shipment_id($resp));
    }
    public function test_parse_tracking_status(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/track.json'), true);
        $t = BGC_Speedy::parse_tracking($resp);
        $this->assertSame('DELIVERED', $t->status);
        $this->assertNotEmpty($t->events);
    }
    public function test_tracking_url_contains_waybill(): void {
        $c = new BGC_Speedy(['env' => 'demo']);
        $this->assertStringContainsString('299999990', $c->tracking_url('299999990'));
    }
}
