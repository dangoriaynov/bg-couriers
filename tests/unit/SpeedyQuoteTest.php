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
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';

/**
 * @group speedy
 */
final class SpeedyQuoteTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('get_option')->alias(static fn($n, $d = false) => $d); // no settings: the plain body
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_build_calculate_body_for_office(): void {
        $body = BGCouriers_Speedy::build_calculate_body([
            'method' => 'office', 'site_id' => 68134, 'office_id' => 307,
            'weight_kg' => 0.6, 'cod_amount' => 0.0, 'currency' => 'BGN',
        ]);
        $this->assertSame(307, $body['recipient']['pickupOfficeId']);
        $this->assertSame(0.6, $body['content']['totalWeight']);
        $this->assertSame(1, $body['content']['parcelsCount']);
        $this->assertArrayNotHasKey('addressLocation', $body['recipient']);
    }
    public function test_build_calculate_body_for_address(): void {
        $body = BGCouriers_Speedy::build_calculate_body([
            'method' => 'address', 'site_id' => 68134,
            'weight_kg' => 0.6, 'cod_amount' => 0.0, 'currency' => 'BGN',
        ]);
        $this->assertSame(100, $body['recipient']['addressLocation']['countryId']);
        $this->assertSame(68134, $body['recipient']['addressLocation']['siteId']);
        $this->assertArrayNotHasKey('pickupOfficeId', $body['recipient']);
    }
    /**
     * The quote starts where the shipment will. With no drop-off office there is NO sender on the
     * calculation at all (an empty one is refused as an array); with one, the sender is exactly the
     * office - measured 2026-09-18: the id alone is accepted, no clientId, no `dropoff` flag.
     */
    public function test_the_quote_starts_at_the_drop_off_office_when_one_is_chosen(): void {
        $shipment = ['method' => 'office', 'site_id' => 68134, 'office_id' => 337, 'weight_kg' => 0.5, 'cod_amount' => 0.0, 'currency' => 'EUR'];
        Functions\when('get_option')->alias(static fn($n, $d = false) => $n === 'bgcouriers_speedy_dropoff_office' ? '' : $d);
        $this->assertArrayNotHasKey('sender', BGCouriers_Speedy::build_calculate_body($shipment));

        Functions\when('get_option')->alias(static fn($n, $d = false) => $n === 'bgcouriers_speedy_dropoff_office' ? '307' : $d);
        $this->assertSame(['dropoffOfficeId' => 307], BGCouriers_Speedy::build_calculate_body($shipment)['sender']);
    }
    public function test_parse_price_picks_total(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/calculate.json'), true);
        $q = BGCouriers_Speedy::parse_price($resp, 'BGN');
        $this->assertInstanceOf(BGCouriers_Quote::class, $q);
        $this->assertEqualsWithDelta(5.20, $q->price, 0.001);
        $this->assertEqualsWithDelta(1.04, $q->tax, 0.001);
        $this->assertSame('live', $q->source);
    }
}
