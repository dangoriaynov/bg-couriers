<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-expressone.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * Express One's answers, held to what the live test account actually returned on 2026-08-25.
 *
 * Every fixture here is a real answer with the account scrubbed out of it. The three that would cost a
 * shop money if they drifted are the ones with the longest names: a town appearing once however many
 * postcodes it has, a price handed over net of its VAT, and a cancel that already happened still
 * reading as cancelled.
 *
 * @group expressone
 */
final class ExpressoneParsersTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg(1);   // exception messages are esc_html()'d (Plugin Check)
        // The street list is cached for a day against the rate limit; a unit test always asks cold.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function fx(string $f): array {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/expressone/' . $f), true);
    }

    // ── The envelope ─────────────────────────────────────────────────────────

    public function test_a_refusal_is_an_exception_even_though_it_arrived_as_a_success(): void {
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->expectExceptionMessage('The RECEIVER_CITY field cannot be empty!');
        BGCouriers_Expressone::unwrap($this->fx('error-envelope.json'));
    }

    public function test_a_success_hands_back_its_data_and_nothing_else(): void {
        $this->assertSame(['A' => 1], BGCouriers_Expressone::unwrap(['status' => true, 'data' => ['A' => 1]]));
    }

    /** /1/list-office answers with an object keyed "1","2","3"…; /1/list-city with a plain array. */
    public function test_rows_reads_both_shapes_the_api_uses_for_a_list(): void {
        $this->assertSame([['ID' => 1], ['ID' => 2]],
            BGCouriers_Expressone::rows(['status' => true, 'data' => ['1' => ['ID' => 1], '2' => ['ID' => 2]]]));
        $this->assertSame([['ID' => 1]], BGCouriers_Expressone::rows(['status' => true, 'data' => [['ID' => 1]]]));
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    public function test_a_town_appears_once_however_many_postcodes_it_has(): void {
        $rows = BGCouriers_Expressone::parse_cities($this->fx('list-city.json'));
        $ids  = array_column($rows, 'city_id');
        $this->assertSame(array_values(array_unique($ids)), $ids,
            'the API lists a town once per postcode - Sofia is 964 rows - and the table is keyed on the id');
        $sofia = array_values(array_filter($rows, static fn($r) => $r['city_id'] === 68134));
        $this->assertCount(1, $sofia);
        $this->assertSame('СОФИЯ', $sofia[0]['name']);
        $this->assertSame('1000', $sofia[0]['post_code'], 'the lowest postcode stands for the town');
        $this->assertSame('СОФИЯ - ГРАД', $sofia[0]['region']);
        // Some rows carry no postcode at all. An empty one is not a postcode, so a real one arriving
        // later must replace it - otherwise the town is filed with no code and the checkout shows none.
        $none = array_values(array_filter($rows, static fn($r) => $r['city_id'] === 12345));
        $this->assertSame('9000', $none[0]['post_code'], 'a real postcode beats an empty one, however late it comes');
    }

    public function test_a_locker_is_an_automat_and_a_counter_is_an_office(): void {
        $rows  = BGCouriers_Expressone::parse_offices($this->fx('list-office.json'));
        $types = array_count_values(array_column($rows, 'type'));
        $this->assertSame(2, $types['automat'], 'LOCATION_TYPE 4 is an EXOBOX');
        $this->assertSame(3, $types['office'], 'LOCATION_TYPE 2 (depot) and 3 (partner counter) are both offices');
        $this->assertIsInt($rows[0]['office_id'], 'the country-wide call sends the id as a string');
        $this->assertSame(68134, $rows[0]['city_id']);
        $this->assertNotSame(0.0, $rows[0]['lat']);
    }

    /**
     * The picker stores `name` and shows `label` - that is how every courier's street list is rendered
     * here - and the type is what tells "ЖК Младост" from "УЛ. Младост".
     */
    public function test_a_street_row_is_shaped_the_way_the_checkout_renders_one(): void {
        $rows = BGCouriers_Expressone::parse_streets($this->fx('list-street.json'));
        $this->assertSame(['id', 'name', 'type', 'label'], array_keys($rows[0]));
        $this->assertSame(143, $rows[0]['id']);
        $this->assertSame('1', $rows[0]['name']);
        $this->assertSame('УЛ. 1', $rows[0]['label']);
    }

    /**
     * Express One refuses an address it was not given a street id for - "Please set RECEIVER_STREET_ID
     * when sending RECEIVER_STREET", measured - and the order only carries the name. The id therefore
     * has to be found again at the packing table, and a name the town does not know has to say so there
     * rather than produce a waybill to nowhere.
     */
    public function test_a_street_id_is_found_again_from_the_name_the_order_carries(): void {
        $co = new class ([]) extends BGCouriers_Expressone {
            public $asked = 0;
            protected function call(string $path, array $body = []): array {
                $this->asked++;
                return json_decode((string) file_get_contents(
                    dirname(__DIR__) . '/fixtures/expressone/list-street.json'), true);
            }
        };
        $hit = $co->street_match(68134, '1');
        $this->assertSame(143, $hit['id']);
        $this->assertTrue($hit['ambiguous'], 'Sofia has a "1" that is a УЛ. and a "1" that is an АЛ.');
        $this->assertSame(0, $co->street_match(68134, 'Улица, каквато няма')['id']);
        $this->assertSame(0, $co->street_match(0, '1')['id'], 'no town, no lookup');
    }

    // ── Price ────────────────────────────────────────────────────────────────

    public function test_the_price_woocommerce_is_given_is_net_of_its_vat(): void {
        $q = BGCouriers_Expressone::parse_price(BGCouriers_Expressone::unwrap($this->fx('calculate-bol-address.json')), 'EUR');
        $this->assertSame(3.97, round($q->price, 2), 'TOTAL 4.76 carries 0.79 of VAT, and WooCommerce taxes what it is given');
        $this->assertSame(0.79, round($q->tax, 2));
        $this->assertSame(4.76, round($q->total(), 2));
        $this->assertSame('EUR', $q->currency);
        $this->assertSame('live', $q->source);
    }

    public function test_a_parcel_going_to_a_point_asks_for_that_point_s_own_price(): void {
        $b = BGCouriers_Expressone::build_calculate_body(
            ['method' => 'office', 'office_id' => 220593, 'city_id' => 68134, 'weight_kg' => 1.0]);
        $this->assertSame(220593, $b['TAKE_OFFICE_ID'],
            'without it the answer is the address price - 4.76 against 4.06, charged to a customer collecting their own parcel');
        $this->assertSame(68134, $b['RECEIVER_CITY_ID']);
        $this->assertSame(100, $b['RECEIVER_COUNTRY_ID']);
        $this->assertArrayNotHasKey('COD', $b, 'no collection, no collection fee');
    }

    public function test_a_collection_is_priced_as_a_collection(): void {
        $b = BGCouriers_Expressone::build_calculate_body(['method' => 'address', 'city_id' => 68134, 'weight_kg' => 1.0, 'cod_amount' => 50.0]);
        $this->assertSame(50.0, $b['COD']);
        $this->assertArrayNotHasKey('TAKE_OFFICE_ID', $b);
        $paid = BGCouriers_Expressone::parse_price(BGCouriers_Expressone::unwrap($this->fx('calculate-bol-cod.json')), 'EUR');
        $free = BGCouriers_Expressone::parse_price(BGCouriers_Expressone::unwrap($this->fx('calculate-bol-address.json')), 'EUR');
        $this->assertGreaterThan($free->total(), $paid->total(), 'the courier charges for collecting the money');
    }

    public function test_a_quote_for_a_country_this_courier_does_not_serve_is_refused_not_guessed(): void {
        $this->expectException(BGCouriers_Api_Exception::class);
        BGCouriers_Expressone::build_calculate_body(['method' => 'address', 'country' => 'RO', 'city_id' => 1, 'weight_kg' => 1.0]);
    }

    // ── The waybill ──────────────────────────────────────────────────────────

    /** @param array<string,mixed> $over */
    private function shipment(array $over = []): array {
        Functions\when('get_option')->alias(static function ($k, $d = false) {
            return $k === 'bgcouriers_expressone_sender_object' ? 387431 : $d;
        });
        return array_merge([
            'method' => 'office', 'city_id' => 68134, 'city_name' => 'СОФИЯ', 'post_code' => '1000',
            'office_id' => 220593, 'weight_kg' => 1.0, 'parcels' => 1, 'order_number' => '11311',
            'receiver_name' => 'Тест Тестов', 'receiver_phone' => '0888123456',
        ], $over);
    }

    public function test_the_town_name_travels_with_the_town_id(): void {
        Functions\when('__')->returnArg(1);
        $b = BGCouriers_Expressone::build_shipment_body($this->shipment());
        $this->assertSame(68134, $b['RECEIVER_CITY_ID']);
        $this->assertSame('СОФИЯ', $b['RECEIVER_CITY'], 'the API refuses the id on its own: "RECEIVER_CITY cannot be empty"');
        $this->assertSame(220593, $b['TAKE_OFFICE_ID']);
        $this->assertSame(387431, $b['SEND_OFFICE_ID']);
        $this->assertArrayNotHasKey('RECEIVER_STREET', $b, 'a parcel going to a counter has no street');
    }

    public function test_an_address_carries_the_street_out_of_the_nomenclature(): void {
        $b = BGCouriers_Expressone::build_shipment_body($this->shipment([
            'method' => 'address', 'street_id' => 99, 'street' => 'ПРОФ. ЦВЕТАН ЛАЗАРОВ', 'street_no' => '117']));
        $this->assertSame(99, $b['RECEIVER_STREET_ID']);
        $this->assertSame('117', $b['RECEIVER_STREET_NO']);
        $this->assertArrayNotHasKey('TAKE_OFFICE_ID', $b);
    }

    public function test_the_order_number_goes_on_the_shipment(): void {
        $b = BGCouriers_Expressone::build_shipment_body($this->shipment());
        $this->assertSame('11311', $b['CLIENT_REFERENCE'], 'the only way a waybill can be matched back to its order');
    }

    public function test_who_pays_the_delivery_is_said_the_way_the_courier_asks_it(): void {
        $this->assertSame(0, BGCouriers_Expressone::build_shipment_body($this->shipment(['payer' => 'sender']))['PAYER']);
        $this->assertSame(1, BGCouriers_Expressone::build_shipment_body($this->shipment(['payer' => 'recipient']))['PAYER']);
    }

    public function test_the_label_arrives_with_the_waybill_and_needs_no_second_call(): void {
        $l = BGCouriers_Expressone::parse_created(BGCouriers_Expressone::unwrap($this->fx('create-bol.json')));
        $this->assertSame('29801952', $l->waybill);
        $this->assertStringStartsWith('%PDF', $l->pdf);
    }

    // ── Cancelling ───────────────────────────────────────────────────────────

    public function test_cancelling_a_shipment_that_is_already_cancelled_is_not_a_failure(): void {
        $this->assertTrue(BGCouriers_Expressone::parse_cancel(BGCouriers_Expressone::unwrap($this->fx('cancel-bol.json'))));
        $this->assertTrue(BGCouriers_Expressone::parse_cancel(BGCouriers_Expressone::unwrap($this->fx('cancel-bol-already.json'))),
            'the second cancel answers an EMPTY data - the shipment is cancelled either way, and saying otherwise is how a merchant is told a lie');
        $this->assertSame(7, (int) BGCouriers_Expressone::unwrap($this->fx('bol-info-cancelled.json'))['STATUS_ID']);
    }

    // ── Tracking ─────────────────────────────────────────────────────────────

    public function test_a_parcel_nobody_has_touched_has_no_events_at_all(): void {
        $t = BGCouriers_Expressone::parse_tracking(BGCouriers_Expressone::unwrap($this->fx('track-bol-empty.json')), '29801952');
        $this->assertSame([], $t->events, '"N/A" with a null status means nothing has happened - it is not a status to print');
        $this->assertSame('registered', $t->stage(), 'a waybill printed a minute ago is still on the merchant\'s desk');
    }

    public function test_delivered_and_returned_are_read_off_the_courier_s_own_code(): void {
        $del = BGCouriers_Expressone::parse_tracking(BGCouriers_Expressone::unwrap($this->fx('track-bol-delivered.json')), '1');
        $this->assertSame('delivered', $del->stage());
        $this->assertSame('Доставена', $del->human());
        $ret = BGCouriers_Expressone::parse_tracking(BGCouriers_Expressone::unwrap($this->fx('track-bol-returned.json')), '1');
        $this->assertSame('returned', $ret->stage(), 'status 12 - Върната към подател - puts the goods back in stock');
    }

    /**
     * The real sequence off the test account: 0 booked, 3 at the office, 5 out, 6 DELIVERED, 10
     * finalised, 7 CANCELLED. Every other courier here is read newest-event-first, and that reading
     * would tell a customer holding their parcel that the shipment was cancelled - and cancel the order.
     */
    public function test_a_delivered_parcel_stays_delivered_when_the_paperwork_is_closed_after_it(): void {
        $t = BGCouriers_Expressone::parse_tracking(
            BGCouriers_Expressone::unwrap($this->fx('track-bol-delivered-then-closed.json')), '1');
        $this->assertSame('delivered', $t->stage(), 'the parcel reached the customer; 10 and 7 are about the document');
        $this->assertSame('Доставена', $t->human());
        $this->assertCount(7, $t->events, 'and nothing is hidden - the whole history is still there');
    }

    public function test_the_events_are_oldest_first_so_the_last_one_is_the_current_one(): void {
        $t = BGCouriers_Expressone::parse_tracking(BGCouriers_Expressone::unwrap($this->fx('track-bol-returned.json')), '1');
        $dates = array_column($t->events, 'date');
        $sorted = $dates; sort($sorted);
        $this->assertSame($sorted, $dates);
        $this->assertStringContainsString('Изтекла резервация в АПС',
            implode(' | ', array_column($t->events, 'name')), 'the substatus is where a 101 keeps its meaning');
    }

    /**
     * The checkout's street box must stop offering a typed street for this courier, because a typed one
     * cannot become a waybill. Driven on dev before it was fixed: the picker offered "БУЛ. ПРОФ. ЦВЕТАН
     * ЛАЗАРОВ", select2's tag took the typed "Цветан Лазаров" instead, the order was placed happily, and
     * the label refused hours later with nobody left to ask.
     */
    /**
     * No paper size is offered, because the courier has none to give. Measured on one waybill: PDF_FORMAT
     * 0, 1 and 2 came back byte-identical - the same 162045 bytes and the same 416.69 x 282.61 pt page -
     * and only the two ZPL formats differed. A select on the tab would have been a control that changes
     * nothing, so the label layout stays where the merchant set it, in their Express One account.
     */
    public function test_no_paper_size_is_offered_because_the_courier_has_none(): void {
        $this->assertSame([], (new BGCouriers_Expressone([]))->label_formats());
        $this->assertStringNotContainsString('bgcouriers_expressone_label_paper_size',
            (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-wc-settings.php'),
            'a setting the courier cannot honour must not be on its tab');
    }

    /** Who pays the delivery reaches the waybill as the field Express One reads it from. */
    public function test_who_pays_is_asked_for_in_the_courier_s_own_terms(): void {
        $sender = BGCouriers_Expressone::build_shipment_body($this->shipment(['payer' => 'sender']));
        $recip  = BGCouriers_Expressone::build_shipment_body($this->shipment(['payer' => 'recipient']));
        $this->assertSame(0, $sender['PAYER']);
        $this->assertSame(1, $recip['PAYER'], 'booked on the test account and read back as "Получател"');
        $this->assertSame(0, $sender['PDF_FORMAT'], 'the account decides the layout, not the plugin');
    }

    /** Several parcels and a declared value, both measured as applied. */
    public function test_parcels_and_a_declared_value_travel_with_the_shipment(): void {
        $b = BGCouriers_Expressone::build_shipment_body($this->shipment(['parcels' => 3, 'insurance' => 60.0]));
        $this->assertSame(3, $b['PACK_COUNT']);
        $this->assertSame(60.0, $b['INSURANCE']);
        $plain = BGCouriers_Expressone::build_shipment_body($this->shipment());
        $this->assertSame(1, $plain['PACK_COUNT']);
        $this->assertArrayNotHasKey('INSURANCE', $plain, 'insuring costs money - it is opt-in per order');
    }

    public function test_this_courier_delivers_only_to_streets_it_lists(): void {
        $this->assertTrue((new BGCouriers_Expressone([]))->street_list_only());
        $other = new class ([]) extends BGCouriers_Abstract_Courier {
            public function id(): string { return 'other'; }
            public function label(): string { return 'Other'; }
            public function capabilities(): array { return ['address']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(0, 0, 'EUR', 'live'); }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function get_label_pdf(string $w, string $f = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('', '', []); }
            public function tracking_url(string $w): string { return ''; }
        };
        $this->assertFalse($other->street_list_only(), 'a courier that takes an address as text keeps free typing');
    }

    /**
     * A pickup this courier accepted but gave no number for is still a courier coming.
     *
     * Measured four ways on the test account: /1/request-courier answers {"ERROR":0,"ERROR_MESSAGE":false}
     * and nothing else - no REQUEST, whatever is sent. Throwing there would tell a merchant the courier
     * refused a request it had accepted; returning a number that does not exist would be worse.
     */
    public function test_a_pickup_with_no_reference_is_still_a_pickup(): void {
        $co = new class ([]) extends BGCouriers_Expressone {
            public array $sent = [];
            public array $answer = ['status' => true, 'data' => ['ERROR' => 0, 'ERROR_MESSAGE' => false]];
            protected function call(string $path, array $body = []): array { $this->sent = $body; return $this->answer; }
        };
        Functions\when('get_option')->alias(static fn($k, $d = false) => $k === 'bgcouriers_expressone_sender_object' ? 387431 : $d);

        $this->assertSame('', $co->request_pickup(['1', '2'],
            ['from' => '15:00', 'to' => '17:00', 'packs' => 2, 'weight_kg' => 3.5]));
        $this->assertSame('15:00-17:00', $co->sent['readiness'], 'the screen gives from/to, not a single time');
        $this->assertSame(2, $co->sent['count']);
        $this->assertSame(387431, $co->sent['take_office_id'], 'collected from the account address, not from nowhere');

        $co->answer = ['status' => true, 'data' => ['ERROR' => 0, 'REQUEST' => '778899']];
        $this->assertSame('778899', $co->request_pickup(['1'], []), 'and a number is used when there is one');

        $co->answer = ['status' => true, 'data' => ['ERROR' => 3, 'ERROR_MESSAGE' => 'no couriers today']];
        $this->expectException(BGCouriers_Api_Exception::class);
        $co->request_pickup(['1'], []);
    }

    public function test_the_customer_is_given_a_link_that_carries_the_number(): void {
        $this->assertSame('https://expressone.bg/bg/tracking/29801952',
            (new BGCouriers_Expressone([]))->tracking_url('29801952'));
    }
}
