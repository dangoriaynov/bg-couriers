<?php
/**
 * Checkout pricing must NOT hit a courier's API before a city is chosen - it uses the fast cached daily
 * reference, so switching couriers stays snappy. Once a real city is picked it does the exact live quote.
 *
 * @group core
 */
final class PricingCheckoutQuoteTest extends WP_UnitTestCase {
    public function setUp(): void {
        parent::setUp();
        BGCouriers_Schema::create(); // ensure bgcouriers_standard_rates exists for the reference
    }

    /** A courier stub that records whether its live quote() was invoked. */
    private function stub() {
        return new class extends BGCouriers_Abstract_Courier {
            public $quote_called = false;
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Stub'; }
            public function capabilities(): array { return ['office', 'automat', 'address', 'live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $city_id): array { return []; }
            public function quote(array $shipment): BGCouriers_Quote { $this->quote_called = true; return new BGCouriers_Quote(9.99, 0.0, 'EUR', 'live'); }
            public function create_label(\WC_Order $order): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
            public function cancel_label(string $waybill): bool { return false; }
            public function track(string $waybill): BGCouriers_Tracking { return new BGCouriers_Tracking($waybill, '', []); }
            public function tracking_url(string $waybill): string { return ''; }
        };
    }

    public function test_no_destination_uses_reference_without_a_live_call(): void {
        BGCouriers_Rates::set('speedy', 'office', 3.50, get_woocommerce_currency());
        $c = $this->stub();
        $q = BGCouriers_Pricing::checkout_quote($c, 'office', 0, 0, ['weight_kg' => 1.0], 'EUR');
        $this->assertFalse($c->quote_called, 'must not call the courier API before a city is chosen');
        $this->assertEqualsWithDelta(3.50, $q->price, 0.01);
        $this->assertSame('reference', $q->source);
    }

    public function test_chosen_city_does_the_exact_live_quote(): void {
        $c = $this->stub();
        $q = BGCouriers_Pricing::checkout_quote($c, 'office', 41, 100, ['weight_kg' => 1.0], 'EUR');
        $this->assertTrue($c->quote_called, 'a chosen city must produce the exact live quote');
        $this->assertEqualsWithDelta(9.99, $q->price, 0.01);
    }

    /**
     * The reference must carry the tax the live quote worked out, or on a shop that adds no shipping tax
     * the pre-city price is ~20% low for every courier that reports its own tax (Speedy, Express One) or
     * has it added (Sameday) - it read net there, then jumped to gross the moment a town was chosen. The
     * cache already holds the live quote; it used to keep only the price and drop the tax.
     */
    public function test_the_reference_price_carries_the_couriers_own_tax(): void {
        $key = BGCouriers_Pricing::reference_key('speedy', 'office', 1.0, 0.0, '', 'EUR');
        set_transient($key, ['p' => 1.37, 't' => 0.27], HOUR_IN_SECONDS); // a warm live quote, tax and all
        $c = $this->stub();
        $q = BGCouriers_Pricing::checkout_quote($c, 'office', 0, 0, ['weight_kg' => 1.0], 'EUR');
        $this->assertFalse($c->quote_called, 'a warm reference must not call the courier API');
        $this->assertSame('reference', $q->source);
        $this->assertEqualsWithDelta(1.37, $q->price, 0.001);
        $this->assertEqualsWithDelta(0.27, $q->tax, 0.001, 'the reference kept the tax, so it matches the live price');
    }

    /**
     * A reference cache entry written before the tax was kept has only a price. It must degrade to a net
     * reading (tax 0), not error - a warm three-hour cache spans the upgrade.
     */
    public function test_an_old_reference_cache_entry_without_a_tax_reads_as_net(): void {
        $key = BGCouriers_Pricing::reference_key('speedy', 'office', 1.0, 0.0, '', 'EUR');
        set_transient($key, ['p' => 1.37], HOUR_IN_SECONDS); // the old shape, no 't'
        $q = BGCouriers_Pricing::checkout_quote($this->stub(), 'office', 0, 0, ['weight_kg' => 1.0], 'EUR');
        $this->assertSame('reference', $q->source);
        $this->assertEqualsWithDelta(1.37, $q->price, 0.001);
        $this->assertEqualsWithDelta(0.0, $q->tax, 0.001);
    }
}
