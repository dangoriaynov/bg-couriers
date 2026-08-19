<?php
/**
 * Rule 10 of the international plan: abroad it is a live price for THAT destination, or no price at all.
 *
 * Every fallback the checkout has - the merchant's fixed price, the daily reference, the flat last
 * resort - is a Bulgarian number. Handing one of them to a parcel going to Romania would mean the shop
 * charges 6.99 for a delivery Speedy bills it 5.08 plus the rest of the route for, and nothing on the
 * screen would look wrong. So the quote fails instead, and the courier is simply not offered.
 *
 * @group core
 */
final class InternationalPricingTest extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        BGCouriers_Schema::create();
    }

    /** A courier whose live quote can be told to fail, the way an unreachable API does. */
    private function stub(bool $fails = false) {
        return new class($fails) extends BGCouriers_Abstract_Courier {
            public $quote_called = false;
            private $fails;
            public function __construct($fails) { $this->fails = $fails; }
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Stub'; }
            public function capabilities(): array { return ['office', 'automat', 'address', 'live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $city_id): array { return []; }
            public function quote(array $shipment): BGCouriers_Quote {
                $this->quote_called = true;
                if ($this->fails) { throw new BGCouriers_Api_Exception('Speedy is down'); }
                return new BGCouriers_Quote(5.08, 0.0, 'EUR', 'live');
            }
            public function create_label(\WC_Order $order): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
            public function cancel_label(string $waybill): bool { return false; }
            public function track(string $waybill): BGCouriers_Tracking { return new BGCouriers_Tracking($waybill, '', []); }
            public function tracking_url(string $waybill): string { return ''; }
        };
    }

    public function test_a_failed_domestic_quote_still_falls_back(): void {
        BGCouriers_Rates::set('speedy', 'office', 3.50, 'EUR');
        $q = BGCouriers_Pricing::quote($this->stub(true), ['method' => 'office', 'country' => 'BG']);
        $this->assertEqualsWithDelta(3.50, $q->price, 0.01, 'home keeps every fallback it had');
    }

    public function test_a_failed_international_quote_has_nothing_to_fall_back_to(): void {
        BGCouriers_Rates::set('speedy', 'office', 3.50, 'EUR');
        $this->expectException(BGCouriers_Api_Exception::class);
        BGCouriers_Pricing::quote($this->stub(true), ['method' => 'office', 'country' => 'RO']);
    }

    public function test_a_live_international_quote_is_used_as_it_is(): void {
        $q = BGCouriers_Pricing::quote($this->stub(), ['method' => 'office', 'country' => 'RO']);
        $this->assertEqualsWithDelta(5.08, $q->price, 0.01);
        $this->assertSame('live', $q->source);
    }

    public function test_a_fixed_domestic_price_does_not_price_a_parcel_abroad(): void {
        // The merchant typed 4.99 for their own country. It says nothing about Romania.
        update_option('bgcouriers_speedy_office_price_mode', 'fixed');
        update_option('bgcouriers_speedy_office_price', 4.99);
        $home = BGCouriers_Pricing::checkout_quote($this->stub(), 'office', 41, 100, ['weight_kg' => 1.0], 'EUR');
        $this->assertEqualsWithDelta(4.99, $home->price, 0.01);
        $this->assertSame('fixed', $home->source);

        $this->expectException(BGCouriers_Api_Exception::class);
        BGCouriers_Pricing::checkout_quote($this->stub(), 'office', 41, 100, ['weight_kg' => 1.0], 'EUR', 'RO');
    }

    public function test_two_countries_do_not_share_a_cached_price(): void {
        $home = BGCouriers_Pricing::reference_key('speedy', 'office', 1.0);
        $this->assertSame($home, BGCouriers_Pricing::reference_key('speedy', 'office', 1.0, 0.0, 'BG'),
            'home must keep the bare key it has always had');
        $this->assertNotSame($home, BGCouriers_Pricing::reference_key('speedy', 'office', 1.0, 0.0, 'RO'));
    }

    public function test_the_destination_country_is_the_shops_own_until_something_says_otherwise(): void {
        $this->assertSame('BG', BGCouriers_Pricing::destination_country());
        $this->assertSame('RO', BGCouriers_Pricing::destination_country(['destination' => ['country' => 'RO']]));
    }
}
