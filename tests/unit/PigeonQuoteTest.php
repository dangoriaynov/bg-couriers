<?php
// tests/unit/PigeonQuoteTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-pigeon.php';

/** @group pigeon */
final class PigeonQuoteTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pigeon/' . $f), true);
    }

    // (a) parse_price returns BGC_Quote with price=13.26, source='live', currency='EUR'
    public function test_parse_price_returns_quote(): void {
        $resp = $this->fx('calculate.json');
        $q    = BGC_Pigeon::parse_price($resp, 'EUR');
        $this->assertInstanceOf(BGC_Quote::class, $q);
        $this->assertGreaterThan(0, $q->price);
        $this->assertEqualsWithDelta(13.26, $q->price, 0.001);
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
        $body = BGC_Pigeon::build_calculate_body($s, 1001);

        $this->assertSame('office', $body['pickup_type']);
        $this->assertSame(1001, $body['pickup_office_id']);
        $this->assertSame('office', $body['delivery_type']);
        $this->assertSame(2001, $body['delivery_office_id']);
        $this->assertSame(1.5, $body['packages'][0]['weight']);
        $this->assertSame('standard', $body['service_type']);
        $this->assertSame('receiver', $body['who_pays']);
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
        $body = BGC_Pigeon::build_calculate_body($s, 1001);

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
        $body = BGC_Pigeon::build_calculate_body($s, 1001);

        $this->assertSame('address', $body['delivery_type']);
        $this->assertArrayNotHasKey('delivery_office_id', $body);
        $this->assertSame(68134, $body['delivery_address']['city_id']);
        $this->assertSame('бул. Витоша', $body['delivery_address']['street_name']);
        $this->assertSame('1', $body['delivery_address']['street_number']);
    }

    // (e) COD adds service_codes
    public function test_build_calculate_body_cod(): void {
        $s = [
            'method'     => 'office',
            'office_id'  => 2001,
            'weight_kg'  => 1.0,
            'cod_amount' => 49.99,
        ];
        $body = BGC_Pigeon::build_calculate_body($s, 1001);

        $this->assertArrayHasKey('service_codes', $body);
        $this->assertEqualsWithDelta(49.99, $body['service_codes']['cod_amount'], 0.001);
    }

    // (f) weight below minimum is clamped to 0.1
    public function test_build_calculate_body_min_weight(): void {
        $s = ['method' => 'office', 'office_id' => 2001, 'weight_kg' => 0.0];
        $body = BGC_Pigeon::build_calculate_body($s, 1001);
        $this->assertSame(0.1, $body['packages'][0]['weight']);
    }
}
