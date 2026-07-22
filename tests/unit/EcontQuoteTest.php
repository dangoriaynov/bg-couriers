<?php
// tests/unit/EcontQuoteTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-econt.php';

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

    // (a) parse_price returns BGC_Quote with price=4.68, source='live', currency='EUR'
    public function test_parse_price_returns_quote(): void {
        $resp = $this->fx('calculate.json');
        $q = BGC_Econt::parse_price($resp, 'EUR');
        $this->assertInstanceOf(BGC_Quote::class, $q);
        $this->assertGreaterThan(0, $q->price);
        $this->assertEqualsWithDelta(4.68, $q->price, 0.001);
        $this->assertSame('EUR', $q->currency);
        $this->assertSame('live', $q->source);
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
        $body = BGC_Econt::build_calculate_body($s, $sender);

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
        $body = BGC_Econt::build_calculate_body($s, $sender);

        $this->assertSame('calculate', $body['mode']);
        $this->assertSame(41, $body['label']['receiverAddress']['city']['id']);
        $this->assertArrayNotHasKey('receiverOfficeCode', $body['label']);
    }
}
