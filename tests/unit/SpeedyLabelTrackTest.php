<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-speedy.php';

/**
 * @group speedy
 */
final class SpeedyLabelTrackTest extends TestCase {
    public function test_parse_shipment_id(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/shipment.json'), true);
        $this->assertSame('299999990', BGC_Speedy::parse_shipment_id($resp));
    }
    /** The fixture mirrors Speedy's published schema: parcelId + operations[{operationCode,dateTime,description}]. */
    public function test_parse_tracking_status(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/track.json'), true);
        $t = BGC_Speedy::parse_tracking($resp);
        $this->assertSame('16', $t->status); // last operationCode - the change-detection key
        $this->assertSame('299999990', $t->waybill);
        $this->assertCount(2, $t->events);
    }
    /** Regression: `description`/`dateTime` were read as `name`/`date`, so notes printed the raw code. */
    public function test_parse_tracking_uses_description_for_the_human_status(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/track.json'), true);
        $t = BGC_Speedy::parse_tracking($resp);
        $this->assertSame('Доставена пратка', $t->human());
        $this->assertSame('2026-06-21T10:00:00', $t->events[1]['date']);
        $this->assertSame('delivered', BGC_Tracking::classify($t->human()));
    }
    /** An operation with no description at all still yields the code, never an empty note. */
    public function test_parse_tracking_falls_back_to_the_code_when_unnamed(): void {
        $t = BGC_Speedy::parse_tracking(['parcels' => [['parcelId' => '1', 'operations' => [['operationCode' => 148]]]]]);
        $this->assertSame('148', $t->human());
    }
    public function test_tracking_url_contains_waybill(): void {
        $c = new BGC_Speedy([]);
        $this->assertStringContainsString('299999990', $c->tracking_url('299999990'));
    }
}
