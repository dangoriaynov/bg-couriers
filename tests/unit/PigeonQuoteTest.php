<?php
// tests/unit/PigeonQuoteTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-packer.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';
require_once dirname(__DIR__) . '/stubs/wc-tax.php';

/** @group pigeon */
final class PigeonQuoteTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pigeon/' . $f), true);
    }

    /**
     * `total_price` is the money, tax included - so the quote carries it split.
     *
     * This test used to assert the raw 13.26 as the quote's PRICE, which is where the assumption lived:
     * a quote's price is a net rate cost, and WooCommerce adds the shipping tax to it. So a shop
     * charging Pigeon with the order billed 20% more than Pigeon collects.
     *
     * Settled on the live account 2026-08-31 the only way it could be - a waybill. The parcel quoted at
     * 2.59 printed "За плащане - КУ: 2.59 EUR, Общо: 2.59 EUR", and "За плащане" is what the person
     * hands over. Nothing in the API says this; its answer is the exact sum of its own parts with no tax
     * field at all, which is precisely what a net total looks like.
     */
    public function test_the_quoted_total_is_the_money_and_is_carried_split(): void {
        $resp = $this->fx('calculate.json');
        $q    = BGCouriers_Pigeon::parse_price($resp, 'EUR');
        $this->assertInstanceOf(BGCouriers_Quote::class, $q);
        $this->assertGreaterThan(0, $q->price);
        // What the courier collects is unchanged; what is CHARGED is now the net half of it.
        $this->assertEqualsWithDelta(13.26, $q->total(), 0.01, 'the customer still pays what Pigeon quoted');
        $this->assertEqualsWithDelta(11.05, $q->price, 0.01, 'the rate cost is net, WooCommerce taxes it');
        $this->assertGreaterThan(0, $q->tax);
        $this->assertSame('EUR', $q->currency);
        $this->assertSame('live', $q->source);
    }

    // (b) build_calculate_body for office delivery
    public function test_build_calculate_body_office(): void {
        $s = [
            'method'     => 'office',
            'site_id'    => 68134,
            'office_id'  => 2001,
            'weight_kg'  => 1.5,
            'cod_amount' => 0,
        ];
        $body = BGCouriers_Pigeon::build_calculate_body($s, 1001);

        $this->assertSame('office', $body['pickup_type']);
        $this->assertSame(1001, $body['pickup_office_id']);
        $this->assertSame('office', $body['delivery_type']);
        $this->assertSame(2001, $body['delivery_office_id']);
        $this->assertSame(1.5, $body['packages'][0]['weight']);
        // packages carry length/width/height (default box) - Pigeon 422s without them
        $this->assertSame(40, $body['packages'][0]['length']);
        $this->assertSame(40, $body['packages'][0]['width']);
        $this->assertSame(40, $body['packages'][0]['height']);
        $this->assertSame('standard', $body['service_type']);
        // The merchant pays the courier (we already charged shipping at checkout)
        $this->assertSame('sender', $body['who_pays']);
        $this->assertArrayNotHasKey('delivery_address', $body);
        // cod_amount === 0 → no service_codes
        $this->assertArrayNotHasKey('service_codes', $body);
    }

    // (c) build_calculate_body for automat (locker) delivery
    public function test_build_calculate_body_automat(): void {
        $s = [
            'method'    => 'automat',
            'site_id'   => 68134,
            'office_id' => 3001,
            'weight_kg' => 0.5,
        ];
        $body = BGCouriers_Pigeon::build_calculate_body($s, 1001);

        $this->assertSame('locker', $body['delivery_type']);
        $this->assertSame(3001, $body['delivery_office_id']);
        $this->assertArrayNotHasKey('delivery_address', $body);
    }

    // (d) build_calculate_body for address delivery
    public function test_build_calculate_body_address(): void {
        $s = [
            'method'      => 'address',
            'site_id'     => 68134,
            'street_name' => 'бул. Витоша',
            'street_no'   => '1',
            'weight_kg'   => 2.0,
        ];
        $body = BGCouriers_Pigeon::build_calculate_body($s, 1001);

        $this->assertSame('address', $body['delivery_type']);
        $this->assertArrayNotHasKey('delivery_office_id', $body);
        $this->assertSame(68134, $body['delivery_address']['city_id']);
        // The API takes address delivery as city_id + a free-text additional_info (street_id optional,
        // which we do not capture). street_name/street_number are NOT sent.
        $this->assertSame('бул. Витоша 1', $body['delivery_address']['additional_info']);
        $this->assertArrayNotHasKey('street_name', $body['delivery_address']);
        $this->assertArrayNotHasKey('street_number', $body['delivery_address']);
    }

    // (e) COD adds service_codes
    public function test_build_calculate_body_cod(): void {
        $s = [
            'method'     => 'office',
            'office_id'  => 2001,
            'weight_kg'  => 1.0,
            'cod_amount' => 49.99,
        ];
        $body = BGCouriers_Pigeon::build_calculate_body($s, 1001);

        $this->assertArrayHasKey('service_codes', $body);
        $this->assertEqualsWithDelta(49.99, $body['service_codes']['cod_amount'], 0.001);
    }

    // (f) weight below minimum is clamped to 0.1
    public function test_build_calculate_body_min_weight(): void {
        $s = ['method' => 'office', 'office_id' => 2001, 'weight_kg' => 0.0];
        $body = BGCouriers_Pigeon::build_calculate_body($s, 1001);
        $this->assertSame(0.1, $body['packages'][0]['weight']);
    }
}
