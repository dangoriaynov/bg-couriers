<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * Sameday estimate: the request body must route by delivery type (locker vs ooh vs address) and
 * the response must map to a live BGCouriers_Quote. Shapes LIVE-CONFIRMED on the BG demo env (2026-07-23):
 * response {"amount","currency","time"}; body needs packageNumber + thirdPartyPickup and the
 * recipient's `city` (id) + `countyString`.
 *
 * @group sameday
 */
final class SamedayQuoteTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/sameday/' . $f), true);
    }

    public function test_parse_price_reads_amount_matching_store_currency(): void {
        $q = BGCouriers_Sameday::parse_price(['amount' => 4.5, 'currency' => 'EUR', 'time' => 96], 'EUR');
        $this->assertInstanceOf(BGCouriers_Quote::class, $q);
        $this->assertEqualsWithDelta(4.5, $q->price, 0.001);
        $this->assertSame('EUR', $q->currency);
        $this->assertSame('live', $q->source);
    }

    public function test_parse_price_rejects_bgn_now_that_there_is_no_second_currency(): void {
        // BGN used to be converted over the fixed peg. Bulgaria dropped the dual BGN/EUR requirement on
        // 2026-08-09 and the plugin now knows only the store's currency, so a BGN quote is as foreign as
        // any other - it throws, and the pricing pipeline falls back to the reference/fixed price.
        $this->expectException(BGCouriers_Api_Exception::class);
        BGCouriers_Sameday::parse_price(['amount' => 19.5583, 'currency' => 'BGN'], 'EUR');
    }

    public function test_parse_price_rejects_a_foreign_currency(): void {
        // The shared demo tarifficator answers in RON (live-observed fixture) - never chargeable in a BG store.
        $this->expectException(BGCouriers_Api_Exception::class);
        BGCouriers_Sameday::parse_price($this->fx('estimate.json'), 'EUR');
    }

    public function test_build_estimate_body_locker_sets_locker_last_mile(): void {
        Functions\when('get_option')->justReturn('55');
        Functions\when('get_woocommerce_currency')->justReturn('BGN');
        $body = BGCouriers_Sameday::build_estimate_body(['method' => 'automat', 'office_id' => 501, 'site_id' => 161, 'weight_kg' => 1.2, 'currency' => 'BGN', 'region' => 'София-град', 'service_id' => 15]);
        $this->assertSame('15', $body['service']); // auto-discovered from the account, not a typed-in setting
        $this->assertSame(501, $body['lockerLastMile']);
        $this->assertArrayNotHasKey('oohLastMile', $body);
        $this->assertEqualsWithDelta(1.2, $body['packageWeight'], 0.001);
        $this->assertSame(1, $body['awbPayment']); // sender pays the delivery
        // Live-confirmed required fields + recipient shape.
        $this->assertSame(1, $body['packageNumber']);
        $this->assertSame(0, $body['thirdPartyPickup']);
        $this->assertSame(161, $body['awbRecipient']['city']);
        $this->assertSame('София-град', $body['awbRecipient']['countyString']);
        $this->assertArrayNotHasKey('cityId', $body['awbRecipient']);
    }

    public function test_build_estimate_body_office_sets_ooh_last_mile(): void {
        Functions\when('get_option')->justReturn('55');
        Functions\when('get_woocommerce_currency')->justReturn('BGN');
        $body = BGCouriers_Sameday::build_estimate_body(['method' => 'office', 'office_id' => 701, 'site_id' => 161, 'weight_kg' => 1.0]);
        $this->assertSame(701, $body['oohLastMile']);
        $this->assertArrayNotHasKey('lockerLastMile', $body);
    }
}
