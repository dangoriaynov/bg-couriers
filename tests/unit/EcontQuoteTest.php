<?php
// tests/unit/EcontQuoteTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-econt.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-packer.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';
require_once dirname(__DIR__) . '/stubs/wc-tax.php';

/** @group econt */
final class EcontQuoteTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        // build_calculate_body reads options (shipment description) - default-return.
        \Brain\Monkey\Functions\when('get_option')->alias(static function ($name, $default = false) { return $default; });
    }
    protected function tearDown(): void { \Brain\Monkey\tearDown(); parent::tearDown(); }

    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/econt/' . $f), true);
    }

    /**
     * `totalPrice` is what Econt collects, so the quote carries it split.
     *
     * This asserted the raw 4.68 as the quote's PRICE, and a quote's price is a NET rate cost that
     * WooCommerce then taxes - so the checkout was adding 20% to a figure Econt does not add 20% to.
     * The waybill settled it: a shipment quoted at 5.06 printed "Куриерска услуга: 5.06 EUR" and
     * "Общо: събери 5.17 EUR". Two percent above the quote, not twenty; a net 5.06 would have been
     * collected as 6.07.
     */
    public function test_the_quoted_total_is_the_money_and_is_carried_split(): void {
        $resp = $this->fx('calculate.json');
        $q = BGCouriers_Econt::parse_price($resp, 'EUR');
        $this->assertInstanceOf(BGCouriers_Quote::class, $q);
        $this->assertEqualsWithDelta(4.68, $q->total(), 0.01, 'what Econt collects is unchanged');
        $this->assertLessThan(4.68, $q->price, 'the rate cost is the net half of it');
        $this->assertGreaterThan(0, $q->tax);
        $this->assertSame('EUR', $q->currency);
        $this->assertSame('live', $q->source);
    }

    /** An account that DOES break the VAT out is the courier speaking about itself, and it wins. */
    public function test_an_account_that_reports_its_own_vat_is_believed(): void {
        $resp = ['label' => ['totalPrice' => 10.0, 'totalPriceWithVAT' => 12.0, 'currency' => 'EUR']];
        $q = BGCouriers_Econt::parse_price($resp, 'EUR');
        $this->assertSame(10.0, $q->price);
        $this->assertSame(2.0, $q->tax);
    }

    // (b) build_calculate_body for office delivery
    public function test_build_calculate_body_office(): void {
        $s = [
            'method'     => 'office',
            'site_id'    => 41,
            'office_code' => '1000',
            'weight_kg'  => 1.0,
        ];
        $sender = [
            'client'  => ['name' => 'S', 'phones' => ['1']],
            'address' => ['city' => ['id' => 41], 'street' => 'x', 'num' => '1'],
        ];
        $body = BGCouriers_Econt::build_calculate_body($s, $sender);

        $this->assertSame('calculate', $body['mode']);
        $this->assertSame('1000', $body['label']['receiverOfficeCode']);
        $this->assertSame('S', $body['label']['senderClient']['name']);
        $this->assertArrayNotHasKey('receiverAddress', $body['label']);
    }

    // (c) build_calculate_body for address delivery
    public function test_build_calculate_body_address(): void {
        $s = [
            'method'      => 'address',
            'site_id'     => 41,
            'street_name' => 'бул. Витоша',
            'street_no'   => '1',
            'weight_kg'   => 1.0,
        ];
        $sender = [
            'client'  => ['name' => 'S', 'phones' => ['1']],
            'address' => ['city' => ['id' => 41], 'street' => 'x', 'num' => '1'],
        ];
        $body = BGCouriers_Econt::build_calculate_body($s, $sender);

        $this->assertSame('calculate', $body['mode']);
        $this->assertSame(41, $body['label']['receiverAddress']['city']['id']);
        $this->assertArrayNotHasKey('receiverOfficeCode', $body['label']);
    }
}
