<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-sameday.php';

/**
 * Sameday estimate: the request body must route by delivery type (locker vs ooh vs address) and
 * the response `cost`/`currency` must map to a live BGC_Quote. Shapes pending sandbox confirmation.
 *
 * @group sameday
 */
final class SamedayQuoteTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/sameday/' . $f), true);
    }

    public function test_parse_price_reads_cost_and_currency_as_live(): void {
        $q = BGC_Sameday::parse_price($this->fx('estimate.json'), 'EUR');
        $this->assertInstanceOf(BGC_Quote::class, $q);
        $this->assertEqualsWithDelta(4.5, $q->price, 0.001);
        $this->assertSame('BGN', $q->currency); // response currency wins over the fallback
        $this->assertSame('live', $q->source);
    }

    public function test_build_estimate_body_locker_sets_locker_last_mile(): void {
        Functions\when('get_option')->justReturn('55');
        Functions\when('get_woocommerce_currency')->justReturn('BGN');
        $body = BGC_Sameday::build_estimate_body(['method' => 'automat', 'office_id' => 501, 'site_id' => 161, 'weight_kg' => 1.2, 'currency' => 'BGN']);
        $this->assertSame(501, $body['lockerLastMile']);
        $this->assertArrayNotHasKey('oohLastMile', $body);
        $this->assertEqualsWithDelta(1.2, $body['packageWeight'], 0.001);
        $this->assertSame(1, $body['awbPayment']); // sender pays the delivery
    }

    public function test_build_estimate_body_office_sets_ooh_last_mile(): void {
        Functions\when('get_option')->justReturn('55');
        Functions\when('get_woocommerce_currency')->justReturn('BGN');
        $body = BGC_Sameday::build_estimate_body(['method' => 'office', 'office_id' => 701, 'site_id' => 161, 'weight_kg' => 1.0]);
        $this->assertSame(701, $body['oohLastMile']);
        $this->assertArrayNotHasKey('lockerLastMile', $body);
    }
}
