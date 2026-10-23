<?php
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow-webhook.php';   // the state wordings

/**
 * BOX NOW parsers against the shapes the live account returns (locker ids are numeric strings).
 *
 * @group boxnow
 */
final class BoxnowParsersTest extends BGCouriers_Unit_TestCase {

    private function fx(string $f): array {
        return $this->fixture('boxnow', $f);
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
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row['office_id'], 'numeric string ids survive the int cast');
            $this->assertSame((string) $row['office_id'], $row['code']);
        }
    }

    /** Towns are derived from lockers: one per name, id hashed from the name, the lowest real postcode. */
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

    /** Asked for one town, only that town's lockers come back; asked for none, every locker does. */
    public function test_a_town_gets_its_own_lockers_only(): void {
        $fx = $this->fx('destinations.json');
        $co = new class($fx) extends BGCouriers_Boxnow {
            private $fx;
            public function __construct(array $fx) { parent::__construct([]); $this->fx = $fx; }
            protected function get_json(string $path, array $query = []): array { return $this->fx; }
        };
        $this->assertCount(4, $co->fetch_offices(0), 'the sync takes every locker');
        $sofia = $co->fetch_offices(BGCouriers_Boxnow::town_id('София'));
        $this->assertCount(3, $sofia, 'Sofia has three');
        foreach ($sofia as $o) { $this->assertSame('София', $o['town']); }
        $this->assertCount(1, $co->fetch_offices(BGCouriers_Boxnow::town_id('Варна')));
        $this->assertSame([], $co->fetch_offices(BGCouriers_Boxnow::town_id('Ямбол')), 'a town with no locker: none, not all');
    }

    public function test_parse_parcel_id_reads_the_waybill_or_nothing(): void {
        $this->assertSame('415-02914-308', BGCouriers_Boxnow::parse_parcel_id($this->fx('delivery-request.json')));
        $this->assertSame('', BGCouriers_Boxnow::parse_parcel_id(['referenceNumber' => 'BN-REF-123']));
    }

    public function test_parse_tracking_reads_state_and_events(): void {
        $parcel = $this->fx('parcel.json')['data'][0];
        $t = BGCouriers_Boxnow::parse_tracking($parcel, '415-02914-308');
        $this->assertSame('415-02914-308', $t->waybill);
        $this->assertSame('boxnow_new', $t->phase, 'prefixed, like the other couriers own codes');
        $this->assertSame('registered', $t->stage());
        $this->assertSame('registered', $t->human(), 'the wording, not the code');
        $this->assertCount(1, $t->events);
        $this->assertSame('new', $t->events[0]['name']);
        $this->assertSame('2026-06-07T12:33:18Z', $t->events[0]['time']);
    }

    /** @return array<string,array{string,string}> Webhook Guide v5 event => stage */
    public static function webhook_events(): array {
        $out = [];
        foreach (['final-destination' => 'ready', 'expired' => 'returned', 'in-depot' => 'transit',
                  'accepted-to-locker' => 'transit', 'accepted-for-return' => 'returning', 'cancelled' => 'cancelled'] as $e => $st) {
            $out[$e] = [$e, $st];
        }
        return $out;
    }

    /** @dataProvider webhook_events */
    public function test_the_webhook_guides_event_spellings_resolve_to_stages(string $event, string $stage): void {
        $t = BGCouriers_Boxnow::parse_tracking(['state' => $event], 'P');
        $this->assertSame($stage, $t->stage());
        $this->assertNotSame($event, $t->human(), 'a wording, not the code');
    }

    /** BOX NOW refuses with a bare code; the manual's wording is shown instead of the JSON. */
    public function test_a_documented_refusal_is_said_in_words(): void {
        $t = BGCouriers_Boxnow::refusal_text('{"code":"P411","status":400}');
        $this->assertStringContainsString('not allowed to collect cash on delivery', $t);
        $this->assertStringEndsWith('(P411)', $t, 'the code stays on the end, for BOX NOW support');
        $this->assertStringNotContainsString('{', $t, 'no JSON on the screen');
        $this->assertStringContainsString('already been used', BGCouriers_Boxnow::refusal_text('{"code":"p410","status":400}'), 'case does not matter');
    }

    /** @return array<string,array{string,string}> */
    public static function refusals_left_as_they_are(): array {
        return [
            'undocumented code' => ['{"code":"P999","status":400}', '{"code":"P999","status":400}'],
            'not json'          => ['not json at all', 'not json at all'],
            'own words win'     => ['{"code":"P411","message":"token expired"}', 'token expired'],
        ];
    }

    /** @dataProvider refusals_left_as_they_are */
    public function test_a_refusal_without_a_documented_code_keeps_what_came(string $body, string $text): void {
        $this->assertSame($text, BGCouriers_Boxnow::refusal_text($body));
    }

    public function test_parse_tracking_survives_an_empty_parcel(): void {
        $t = BGCouriers_Boxnow::parse_tracking([], '415-02914-308');
        $this->assertSame('unknown', $t->status);
        $this->assertSame('', $t->phase, 'no state, no phase - the stage falls back to reading the text');
        $this->assertSame([], $t->events);
    }

    public function test_the_customer_is_given_a_link_to_a_page_that_exists(): void {
        $this->assertSame('https://boxnow.bg/track?track=0960382208', (new BGCouriers_Boxnow([]))->tracking_url('0960382208'));
    }
}
