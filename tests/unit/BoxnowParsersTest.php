<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';

/**
 * BOX NOW was the one courier whose parsers had no fixture test, while its fixtures sat in the tree
 * unread. The shapes below are the ones the account actually returns - the ids in particular, which
 * the fixture used to spell "apm-1001" from the specification. They are numeric strings ("5365"), and
 * that matters: parse_destinations() casts the id to int for the offices table, and a non-numeric id
 * would silently land every locker under office_id 0.
 *
 * @group boxnow
 */
final class BoxnowParsersTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/boxnow/' . $f), true);
    }

    public function test_parse_destinations_normalises_lockers(): void {
        $rows = BGCouriers_Boxnow::parse_destinations($this->fx('destinations.json'));
        $this->assertCount(2, $rows, 'the row without an id is skipped');
        $this->assertSame(5365, $rows[0]['office_id']);
        $this->assertSame('5365', $rows[0]['code']);
        $this->assertSame('automat', $rows[0]['type'], 'every BOX NOW point is a locker');
        $this->assertSame('Test Locker 1', $rows[0]['name']);
        $this->assertSame('Цар Симеон 170 София', $rows[0]['address'], 'both address lines, joined');
        $this->assertSame('1000', $rows[0]['post_code']);
        $this->assertEqualsWithDelta(42.70295, $rows[0]['lat'], 0.00001);
        $this->assertEqualsWithDelta(23.31272, $rows[0]['lng'], 0.00001);
    }

    /** A numeric id survives the int cast the offices table needs. Regression guard for that cast. */
    public function test_every_locker_keeps_a_usable_office_id(): void {
        foreach (BGCouriers_Boxnow::parse_destinations($this->fx('destinations.json')) as $row) {
            $this->assertGreaterThan(0, $row['office_id']);
            $this->assertSame((string) $row['office_id'], $row['code']);
        }
    }

    public function test_parse_parcel_id_reads_the_waybill_off_a_created_delivery(): void {
        $this->assertSame('415-02914-308', BGCouriers_Boxnow::parse_parcel_id($this->fx('delivery-request.json')));
    }

    public function test_parse_parcel_id_is_empty_when_no_parcel_came_back(): void {
        $this->assertSame('', BGCouriers_Boxnow::parse_parcel_id(['referenceNumber' => 'BN-REF-123']));
    }

    public function test_parse_tracking_reads_state_and_events(): void {
        $parcel = $this->fx('parcel.json')['data'][0];
        $t = BGCouriers_Boxnow::parse_tracking($parcel, '415-02914-308');
        $this->assertSame('415-02914-308', $t->waybill);
        $this->assertSame('new', $t->status);
        $this->assertCount(1, $t->events);
        $this->assertSame('new', $t->events[0]['name']);
        $this->assertSame('2026-06-07T12:33:18Z', $t->events[0]['time']);
    }

    /** A parcel BOX NOW knows nothing about must still answer, rather than fatal on a missing key. */
    public function test_parse_tracking_survives_an_empty_parcel(): void {
        $t = BGCouriers_Boxnow::parse_tracking([], '415-02914-308');
        $this->assertSame('unknown', $t->status);
        $this->assertSame([], $t->events);
    }
}
