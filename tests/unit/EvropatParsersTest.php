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
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-evropat.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * A Европът whose calls are answered from the fixtures instead of from the courier.
 *
 * Only call() is replaced, so everything above it - the envelope, the body builders, the caching, the
 * account questions - is the real code being tested.
 */
final class Evropat_Fixture_Client extends BGCouriers_Evropat {
    /** @var array<string,array> path => the answer's `response` */
    public $answers = [];
    /** @var array<int,array{path:string,body:array}> every call that was made */
    public $sent = [];
    /** @var array<string,array> the whole envelope, for the calls that do not go through call() */
    public $envelopes = [];
    protected function call(string $path, array $body = []): array {
        $this->sent[] = ['path' => $path, 'body' => $body];
        if (!array_key_exists($path, $this->answers)) {
            throw new BGCouriers_Api_Exception('no fixture for ' . $path);
        }
        return $this->answers[$path];
    }
    protected function post_raw(string $path, array $body): string {
        $this->sent[] = ['path' => $path, 'body' => $body];
        return '%PDF-1.4 pretend';
    }
    /** The seam under post_json(), so cancel_label()'s own envelope handling is the code under test. */
    protected function http_post(string $url, array $body) {
        $this->sent[] = ['path' => $url, 'body' => $body];
        $path = (string) parse_url($url, PHP_URL_PATH);
        return ['body' => json_encode($this->envelopes[$path] ?? ['error' => null, 'response' => null])];
    }
}

/**
 * Европът's answers, held to what the shop's own account actually returned on 2026-08-31.
 *
 * Every fixture here is a real answer with the account scrubbed out of it. The ones that would cost a
 * shop money if they drifted are the delivery type (it moves the price by 40%), the currency the price
 * comes back in (the account decides which of the two is EUR), the collection fee reaching the quote,
 * and a ППП the account cannot do being turned into a наложен платеж instead of being dropped.
 *
 * @group evropat
 */
final class EvropatParsersTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html')->returnArg(1);   // exception messages are esc_html()'d (Plugin Check)
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('__')->returnArg(1);
        if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function fx(string $f): array {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/evropat/' . $f), true);
    }

    /** The `response` of a fixture - what call() hands back. */
    private function body(string $f): array {
        return (array) $this->fx($f)['response'];
    }

    private function client(array $answers = []): Evropat_Fixture_Client {
        $c = new Evropat_Fixture_Client(['password' => 'test-key']);
        $c->answers = $answers;
        return $c;
    }

    // ── The envelope ─────────────────────────────────────────────────────────

    public function test_a_refusal_is_an_exception_even_though_it_arrived_as_a_success(): void {
        // Their whole API answers HTTP 200. Reading this as an empty success is a price of nothing.
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->expectExceptionMessage('Невалидно населено място');
        BGCouriers_Evropat::unwrap($this->fx('error-envelope.json'));
    }

    public function test_the_error_code_is_kept_so_a_log_can_be_searched_for_it(): void {
        try {
            BGCouriers_Evropat::unwrap($this->fx('error-envelope.json'));
            $this->fail('expected a refusal');
        } catch (BGCouriers_Api_Exception $e) {
            $this->assertStringContainsString('INVALID_FROM_DESTINATION_ID', $e->getMessage());
        }
    }

    // ── The one field that moves the price ───────────────────────────────────

    public function test_delivery_type_encodes_both_ends_of_the_journey(): void {
        // 4.59 office-to-office against 6.52 door-to-door for the same parcel: getting this wrong
        // overcharges (or undercharges) every customer the shop has.
        Functions\when('get_option')->justReturn('office');
        $this->assertSame(1, BGCouriers_Evropat::delivery_type('office'));
        $this->assertSame(2, BGCouriers_Evropat::delivery_type('address'));
        Functions\when('get_option')->justReturn('door');
        $this->assertSame(3, BGCouriers_Evropat::delivery_type('office'));
        $this->assertSame(4, BGCouriers_Evropat::delivery_type('address'));
    }

    public function test_an_unset_sender_end_means_the_merchant_hands_parcels_over(): void {
        // The cheaper of the two, and the one a shop that has never opened the setting is doing.
        Functions\when('get_option')->alias(function ($k, $d = '') { return $d; });
        $this->assertSame('office', BGCouriers_Evropat::sender_end());
    }

    // ── Price ────────────────────────────────────────────────────────────────

    public function test_the_price_is_net_and_carries_no_tax_of_its_own(): void {
        // Nothing in the whole API mentions VAT: no field, no example, no error. `price` is the exact
        // sum of the parts it lists, so WooCommerce adds the tax once, as it does for every courier here.
        $q = BGCouriers_Evropat::parse_price($this->body('calculateprice-office.json'), 'EUR');
        $this->assertSame(0.0, $q->tax);
        $this->assertSame('live', $q->source);
        $this->assertSame($q->price, $q->total());
    }

    public function test_the_price_is_the_sum_of_the_parts_the_answer_lists(): void {
        $d = $this->body('calculateprice-office.json');
        $sum = (float) $d['mainServicePrice'] + (float) $d['fuelTaxPrice'] + (float) $d['cashOnDeliveryPrice'];
        $this->assertEqualsWithDelta($sum, (float) $d['price'], 0.001);
        // And the fuel surcharge really is that percentage of the service, not a flat addition.
        $this->assertEqualsWithDelta(
            (float) $d['mainServicePrice'] * (float) $d['fuelTaxValue'] / 100,
            (float) $d['fuelTaxPrice'], 0.001);
    }

    public function test_a_shop_selling_in_the_second_currency_is_quoted_in_it(): void {
        // The account decides which of the two is EUR - this one answers EURO/BGN while their own
        // documented example answers BGN/EURO. Reading `price` blindly is a 1.95583x error.
        $d = $this->body('calculateprice-office.json');
        $eur = BGCouriers_Evropat::parse_price($d, 'EUR');
        $bgn = BGCouriers_Evropat::parse_price($d, 'BGN');
        $this->assertEqualsWithDelta((float) $d['price'], $eur->price, 0.01);
        $this->assertEqualsWithDelta((float) $d['priceSecondCurrency'], $bgn->price, 0.01);
        $this->assertGreaterThan($eur->price, $bgn->price);
    }

    public function test_a_price_of_nothing_is_refused_rather_than_quoted(): void {
        $this->expectException(BGCouriers_Api_Exception::class);
        BGCouriers_Evropat::parse_price(['price' => '0.00000', 'mainCurrency' => 'EURO'], 'EUR');
    }

    public function test_delivering_to_the_door_costs_more_than_delivering_to_an_office(): void {
        // The fixtures are two real answers for the same parcel; if these ever converge, the delivery
        // type has stopped reaching the quote.
        $office = BGCouriers_Evropat::parse_price($this->body('calculateprice-office.json'), 'EUR');
        $door   = BGCouriers_Evropat::parse_price($this->body('calculateprice-address.json'), 'EUR');
        $this->assertGreaterThan($office->price, $door->price);
    }

    public function test_collecting_money_is_priced_and_reaches_the_quote(): void {
        // 0.61 on a 50 EUR collection, and it lifts the fuel surcharge with it. A quote without it is a
        // quote the shop pays the difference on - the 0.3.6 fault, for every courier.
        $plain = $this->body('calculateprice-office.json');
        $cod   = $this->body('calculateprice-cod.json');
        $this->assertGreaterThan(0, (float) $cod['cashOnDeliveryPrice']);
        $this->assertGreaterThan((float) $plain['price'], (float) $cod['price']);
    }

    public function test_the_money_to_collect_is_sent_with_the_request_for_a_price(): void {
        $c = $this->client(['/getclientaddresses' => $this->body('getclientaddresses.json')]);
        Functions\when('get_option')->alias(function ($k, $d = '') { return $d; });
        $body = $c->build_calculate_body(['method' => 'office', 'city_id' => 273, 'weight_kg' => 1, 'cod_amount' => 50.0]);
        $this->assertSame(50.0, $body['cashOnDelivery'] ?? null);
        $this->assertArrayNotHasKey('postalMoneyOrder', $body);
    }

    public function test_the_price_is_asked_for_from_the_town_the_shop_sends_from(): void {
        // fromDestID is absent from their published parameter list and refused when missing.
        $c = $this->client(['/getclientaddresses' => $this->body('getclientaddresses.json')]);
        Functions\when('get_option')->alias(function ($k, $d = '') { return $d; });
        $body = $c->build_calculate_body(['method' => 'office', 'city_id' => 52, 'weight_kg' => 1]);
        $this->assertSame(273, $body['fromDestID']);   // the account's own town
        $this->assertSame(52, $body['toDestID']);
    }

    // ── Cash on delivery the account cannot actually do ──────────────────────

    public function test_a_ppp_the_account_is_not_allowed_becomes_a_plain_collection(): void {
        // Their API prices a ППП it cannot do at 0.00 and books the shipment WITHOUT one - no error, no
        // flag. A waybill built on that answer travels with no money to collect.
        $c = $this->client(['/getclientaddresses' => $this->body('getclientaddresses.json')]);
        Functions\when('get_option')->alias(function ($k, $d = '') {
            if (strpos($k, 'cod_fiscalization') !== false) { return 'ppp'; }
            if (strpos($k, 'ppp_payout') !== false) { return 'yes'; }
            return $d;
        });
        $this->assertFalse($c->ppp_allowed(), 'the fixture account has allowedPostalMoneyOrder 0');
        $body = $c->build_calculate_body(['method' => 'office', 'city_id' => 273, 'weight_kg' => 1, 'cod_amount' => 40.0]);
        $this->assertArrayNotHasKey('postalMoneyOrder', $body, 'a ППП the account cannot do must not be asked for');
        $this->assertSame(40.0, $body['cashOnDelivery']);
    }

    // ── Weight ───────────────────────────────────────────────────────────────

    public function test_a_big_light_parcel_is_charged_on_what_it_displaces(): void {
        // Their tariff weight is the greater of the real and the volumetric (w x l x h / 6000).
        Functions\when('get_option')->alias(function ($k, $d = 0) {
            return strpos($k, '_length') !== false || strpos($k, '_width') !== false ? 60 : (strpos($k, '_height') !== false ? 40 : $d);
        });
        // 60 x 60 x 40 / 6000 = 24 kg of volume against 1 kg of weight.
        $this->assertSame(24.0, BGCouriers_Evropat::tariff_weight(['weight_kg' => 1.0]));
    }

    public function test_a_heavy_small_parcel_is_charged_on_the_scales(): void {
        Functions\when('get_option')->alias(function ($k, $d = 0) { return $d; }); // default 10x10x2 = 0.033 kg
        $this->assertSame(5.0, BGCouriers_Evropat::tariff_weight(['weight_kg' => 5.0]));
    }

    // ── Nomenclature ─────────────────────────────────────────────────────────

    public function test_towns_carry_the_office_that_serves_them(): void {
        $rows = BGCouriers_Evropat::parse_cities($this->body('getdestinations.json'));
        $this->assertNotEmpty($rows);
        $this->assertSame(273, $rows[0]['city_id']);
        $this->assertSame('СОФИЯ', $rows[0]['name']);
        $this->assertSame('1000', $rows[0]['post_code']);
        $this->assertGreaterThan(0, $rows[0]['office_id'], 'a town with no counter still has one serving it');
    }

    public function test_every_point_is_a_counter_because_bulgaria_has_no_evropat_lockers(): void {
        foreach (BGCouriers_Evropat::parse_offices($this->body('getoffices.json')) as $o) {
            $this->assertSame('office', $o['type']);
            $this->assertGreaterThan(0, $o['city_id']);
        }
    }

    public function test_an_office_keeps_its_hours_and_its_place_on_the_map(): void {
        $o = BGCouriers_Evropat::parse_offices($this->body('getoffices.json'))[0];
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2} - \d{2}:\d{2}$/', $o['hours']);
        $this->assertGreaterThan(0, $o['lat']);
        $this->assertGreaterThan(0, $o['lng']);
    }

    public function test_a_street_keeps_the_id_the_waybill_needs_and_the_prefix_a_customer_reads(): void {
        $s = BGCouriers_Evropat::parse_streets($this->body('getaddresses.json'))[0];
        $this->assertGreaterThan(0, $s['id']);
        $this->assertNotSame('', $s['name']);
        $this->assertStringContainsString($s['type'], $s['label']);
    }

    // ── Tracking ─────────────────────────────────────────────────────────────

    public function test_a_delivered_parcel_stays_delivered_under_later_paperwork(): void {
        // What happened to the PARCEL outranks what happened to the DOCUMENT - the Express One lesson.
        $events = [
            ['code' => 1,  'name' => 'Създадена',  'date' => '2026-08-30 10:00:00'],
            ['code' => 19, 'name' => 'Разнесена',  'date' => '2026-08-31 12:00:00'],
            ['code' => 18, 'name' => 'Анулирана',  'date' => '2026-08-31 18:00:00'],
        ];
        $t = BGCouriers_Evropat::verdict($events, '9100000000');
        $this->assertSame('evropat_19', $t->phase);
    }

    public function test_a_waybill_nobody_has_touched_reads_as_created_and_not_as_moving(): void {
        $t = BGCouriers_Evropat::verdict([['code' => 1, 'name' => 'Създадена', 'date' => '2026-08-31 10:00:00']], '9100000000');
        $this->assertSame('registered', $t->stage());
    }

    public function test_a_printed_label_has_still_not_been_collected(): void {
        $t = BGCouriers_Evropat::verdict([
            ['code' => 1, 'name' => 'Създадена',   'date' => '2026-08-31 10:00:00'],
            ['code' => 2, 'name' => 'Разпечатана', 'date' => '2026-08-31 10:05:00'],
        ], '9100000000');
        $this->assertSame('registered', $t->stage());
    }

    public function test_a_refusal_is_not_a_cancellation(): void {
        // "Отказана" reads as cancelled to the text rules, and the parcel is still in the van.
        $t = BGCouriers_Evropat::verdict([['code' => 6, 'name' => 'Отказана', 'date' => '2026-08-31 10:00:00']], '9100000000');
        $this->assertNotSame('cancelled', $t->stage());
    }

    public function test_an_empty_history_says_nothing_rather_than_guessing(): void {
        $t = BGCouriers_Evropat::verdict([], '9100000000');
        $this->assertSame('', $t->phase);
    }

    // ── The account ──────────────────────────────────────────────────────────

    public function test_this_courier_issues_one_key_and_no_username(): void {
        // creds_present() would otherwise refuse to enable a courier that is perfectly configured.
        $this->assertSame(['password'], (new BGCouriers_Evropat(['password' => 'k']))->credential_fields());
    }

    public function test_no_lockers_are_offered_because_bulgaria_has_none(): void {
        $caps = (new BGCouriers_Evropat(['password' => 'k']))->capabilities();
        $this->assertNotContains('automat', $caps);
        $this->assertContains('office', $caps);
        $this->assertContains('address', $caps);
        $this->assertContains('live_quote', $caps);
    }

    public function test_a_street_must_come_from_their_own_list(): void {
        $this->assertTrue((new BGCouriers_Evropat(['password' => 'k']))->street_list_only());
    }

    // ── What the live account taught us that the documentation did not ───────

    public function test_the_waybill_declares_its_weight_under_the_name_this_endpoint_uses(): void {
        // /calculateprice takes `weight`; /createshipment takes `shipmentWeight` and its published
        // parameter list has no weight field at all. Sending the documented one is INVALID_SHIPMENT_WEIGHT.
        $c = $this->client(['/getclientaddresses' => $this->body('getclientaddresses.json')]);
        Functions\when('get_option')->alias(function ($k, $d = '') { return $d; });
        $body = $c->build_shipment_body(['method' => 'office', 'city_id' => 273, 'office_id' => 268, 'weight_kg' => 2.0]);
        $this->assertArrayHasKey('shipmentWeight', $body);
        $this->assertArrayNotHasKey('weight', $body);
        $this->assertSame(2.0, $body['shipmentWeight']);
    }

    public function test_the_label_is_asked_for_by_the_name_their_error_echoes(): void {
        // Their documented POST /print does not exist (SERVICE_NOT_FOUND) and the endpoint that does
        // reads ONE `shipmentBarCode`, not an array of `barcodes`.
        $c = $this->client();
        Functions\when('get_option')->alias(function ($k, $d = '') { return $d; });
        $c->get_label_pdf('9100000000', 'A6');
        $call = end($c->sent);
        $this->assertSame('/printshipment', $call['path']);
        $this->assertSame('9100000000', $call['body']['shipmentBarCode']);
        $this->assertArrayNotHasKey('barcodes', $call['body']);
        $this->assertSame('A6', $call['body']['format']);
    }

    public function test_one_waybill_per_printout_means_no_native_batch(): void {
        $this->assertFalse((new BGCouriers_Evropat(['password' => 'k']))->has_native_batch());
    }

    public function test_a_cancel_reads_the_couriers_answer_rather_than_assuming_it(): void {
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(function ($r) { return $r['body']; });
        $c = $this->client();
        $c->envelopes['/cancelshipment'] = ['error' => null, 'errorMessage' => null, 'response' => true];
        $this->assertTrue($c->cancel_label('9100000000'));
        $c->envelopes['/cancelshipment'] = ['error' => null, 'errorMessage' => null, 'response' => false];
        $this->assertFalse($c->cancel_label('9100000000'));
    }

    public function test_a_created_waybill_hands_back_the_barcode_and_leaves_the_label_to_be_fetched(): void {
        $l = BGCouriers_Evropat::parse_created($this->body('createshipment.json'));
        $this->assertSame('9100000000', $l->waybill);
        $this->assertSame('', $l->pdf, '/createshipment returns the record, not the document');
    }

    public function test_the_history_reads_the_status_id_the_live_answer_carries(): void {
        // Their documented example omits statusID; the live answer has it, and reading it beats matching
        // Bulgarian status names.
        $c = $this->client([
            '/getshipmenthistory' => $this->body('getshipmenthistory-cancelled.json'),
            '/shipment-statuses-nomenclature' => $this->body('statuses.json'),
        ]);
        $t = $c->track('9100000000');
        $this->assertSame('evropat_18', $t->phase);
        $this->assertSame('cancelled', $t->stage());
    }

    public function test_a_cancelled_waybill_is_recognised_as_cancelled(): void {
        // 18 is what an API cancel actually produced on the live account, which is what lets a repeated
        // cancel - refused outright with INVALID_SHIPMENT_STATE - be told from a cancel that failed.
        $c = $this->client([
            '/getshipmenthistory' => $this->body('getshipmenthistory-cancelled.json'),
            '/shipment-statuses-nomenclature' => $this->body('statuses.json'),
        ]);
        $this->assertTrue($c->is_cancelled('9100000000'));
    }
}
