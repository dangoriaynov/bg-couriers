<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow-webhook.php';   // the state wordings

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
    protected function setUp(): void { parent::setUp(); \Brain\Monkey\setUp(); \Brain\Monkey\Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { \Brain\Monkey\tearDown(); parent::tearDown(); }

    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/boxnow/' . $f), true);
    }

    public function test_parse_destinations_normalises_lockers(): void {
        $rows = BGCouriers_Boxnow::parse_destinations($this->fx('destinations.json'));
        $this->assertCount(4, $rows, 'the row without an id and the wildcard origin with no town are skipped');
        $this->assertSame(5365, $rows[0]['office_id']);
        $this->assertSame('5365', $rows[0]['code']);
        $this->assertSame('automat', $rows[0]['type'], 'every BOX NOW point is a locker');
        $this->assertSame('Test Locker 1', $rows[0]['name']);
        $this->assertSame('Цар Симеон 170 София', $rows[0]['address'], 'both address lines, joined');
        $this->assertSame('1000', $rows[0]['post_code']);
        $this->assertEqualsWithDelta(42.70295, $rows[0]['lat'], 0.00001);
        $this->assertEqualsWithDelta(23.31272, $rows[0]['lng'], 0.00001);
        $this->assertSame('София', $rows[0]['town']);
        $this->assertSame(BGCouriers_Boxnow::town_id('София'), $rows[0]['city_id'], 'the locker carries its town id');
        $this->assertSame($rows[0]['city_id'], $rows[2]['city_id'], 'two Sofia lockers, one Sofia');
        $this->assertNotSame($rows[0]['city_id'], $rows[1]['city_id'], 'Varna is another town');
    }

    /**
     * BOX NOW has no town list; the checkout block, the carry-over between couriers and the combined
     * map all need one, so it is read off the lockers. One town per name, its id a hash of the name
     * (a resync gives the same town the same id, whatever lockers came or went), and the LOWEST postal
     * code of its lockers - the round one the other couriers list the town under.
     */
    public function test_towns_are_read_off_the_lockers(): void {
        $towns = BGCouriers_Boxnow::towns_of(BGCouriers_Boxnow::parse_destinations($this->fx('destinations.json')));
        $this->assertCount(2, $towns, 'Sofia three times and Varna once make two towns; the wildcard origin makes none');
        $by = array_column($towns, null, 'name');
        $this->assertSame('1000', $by['София']['post_code'], 'the lowest REAL code of its lockers: not the district one, and not the "-1000" BOX NOW carries on three Sofia lockers');
        $this->assertSame('9000', $by['Варна']['post_code']);
        $this->assertSame('BG', $by['София']['country']);
        $this->assertSame(BGCouriers_Boxnow::town_id('София'), $by['София']['city_id']);
        $this->assertSame(BGCouriers_Boxnow::town_id('СОФИЯ'), BGCouriers_Boxnow::town_id('софия'), 'one town, whichever case');
        $this->assertGreaterThan(0, $by['Варна']['city_id']);
        $this->assertNotSame($by['Варна']['city_id'], $by['София']['city_id']);
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
        // The state travels as the PHASE - a machine value the stage is read from outright - and the
        // status is the merchant's wording of it. It used to be the bare state in the status, which the
        // orders list then printed as "in-final-destination".
        $this->assertSame('boxnow_new', $t->phase, 'prefixed, like the other couriers own codes');
        $this->assertSame('registered', $t->stage());
        $this->assertSame('registered', $t->human(), 'the wording, not the code');
        $this->assertCount(1, $t->events);
        $this->assertSame('new', $t->events[0]['name']);
        $this->assertSame('2026-06-07T12:33:18Z', $t->events[0]['time']);
    }

    /** A parcel BOX NOW knows nothing about must still answer, rather than fatal on a missing key. */
    public function test_parse_tracking_survives_an_empty_parcel(): void {
        $t = BGCouriers_Boxnow::parse_tracking([], '415-02914-308');
        $this->assertSame('unknown', $t->status);
        $this->assertSame('', $t->phase, 'no state, no phase - the stage falls back to reading the text');
        $this->assertSame([], $t->events);
    }
}
