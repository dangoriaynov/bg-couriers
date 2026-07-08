<?php
/**
 * Checkout pricing must NOT hit a courier's API before a city is chosen — it uses the fast cached daily
 * reference, so switching couriers stays snappy. Once a real city is picked it does the exact live quote.
 *
 * @group core
 */
final class PricingCheckoutQuoteTest extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        BGC_Schema::create(); // ensure bgc_standard_rates exists for the reference
    }

    /** A courier stub that records whether its live quote() was invoked. */
    private function stub() {
        return new class extends BGC_Abstract_Courier {
            public $quote_called = false;
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Stub'; }
            public function capabilities(): array { return ['office', 'automat', 'address', 'live_quote']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $city_id): array { return []; }
            public function quote(array $shipment): BGC_Quote { $this->quote_called = true; return new BGC_Quote(9.99, 0.0, 'EUR', 'live'); }
            public function create_label(\WC_Order $order): BGC_Label { return new BGC_Label(''); }
            public function get_label_pdf(string $waybill): string { return ''; }
            public function cancel_label(string $waybill): bool { return false; }
            public function track(string $waybill): BGC_Tracking { return new BGC_Tracking($waybill, '', []); }
            public function tracking_url(string $waybill): string { return ''; }
        };
    }

    public function test_no_destination_uses_reference_without_a_live_call(): void {
        BGC_Rates::set('speedy', 'office', 3.50, 'EUR');
        $c = $this->stub();
        $q = BGC_Pricing::checkout_quote($c, 'office', 0, 0, ['weight_kg' => 1.0], 'EUR');
        $this->assertFalse($c->quote_called, 'must not call the courier API before a city is chosen');
        $this->assertEqualsWithDelta(3.50, $q->price, 0.01);
        $this->assertSame('reference', $q->source);
    }

    public function test_chosen_city_does_the_exact_live_quote(): void {
        $c = $this->stub();
        $q = BGC_Pricing::checkout_quote($c, 'office', 41, 100, ['weight_kg' => 1.0], 'EUR');
        $this->assertTrue($c->quote_called, 'a chosen city must produce the exact live quote');
        $this->assertEqualsWithDelta(9.99, $q->price, 0.01);
    }
}
