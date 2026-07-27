<?php
/**
 * @group core
 */
final class PricingTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); }

    private function courier(bool $throws): BGCouriers_Courier_Interface {
        return new class($throws) implements BGCouriers_Courier_Interface {
            public function __construct(public bool $throws) {}
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGCouriers_Quote {
                if ($this->throws) { throw new BGCouriers_Api_Exception('down'); }
                return new BGCouriers_Quote(4.0, 0.8, 'BGN', 'live');
            }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $format = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    public function test_live_quote_used_when_available(): void {
        $q = BGCouriers_Pricing::quote($this->courier(false), ['method'=>'office']);
        $this->assertSame('live', $q->source);
        $this->assertEqualsWithDelta(4.0, $q->price, 0.001);
    }
    public function test_falls_back_to_cached_standard_rate(): void {
        BGCouriers_Rates::set('speedy', 'office', 7.5, 'BGN');
        $q = BGCouriers_Pricing::quote($this->courier(true), ['method'=>'office']);
        $this->assertSame('standard', $q->source);
        $this->assertEqualsWithDelta(7.5, $q->total(), 0.001);
    }
    public function test_falls_back_to_configured_per_method_price(): void {
        update_option('woocommerce_currency', 'BGN');
        update_option('bgcouriers_speedy_address_price', '5.50');
        update_option('bgcouriers_speedy_address_currency', 'BGN');
        // live throws, no cached 'address' rate -> the fixed/default price (default 'fallback' mode).
        $q = BGCouriers_Pricing::quote($this->courier(true), ['method' => 'address']);
        $this->assertSame('fixed', $q->source);
        $this->assertEqualsWithDelta(5.50, $q->price, 0.001);
    }
    public function test_fixed_mode_uses_fixed_price_without_calling_the_api(): void {
        update_option('bgcouriers_speedy_office_price_mode', 'fixed');
        update_option('bgcouriers_speedy_office_price', '3.20');
        // courier(false) would return a live 4.0 if the API were called; fixed mode must not call it.
        $q = BGCouriers_Pricing::quote($this->courier(false), ['method' => 'office']);
        $this->assertSame('fixed', $q->source);
        $this->assertEqualsWithDelta(3.20, $q->price, 0.001);
    }
    public function test_no_live_quote_capability_uses_cache(): void {
        BGCouriers_Rates::set('speedy', 'office', 9.0, 'BGN');
        $fake = new class implements BGCouriers_Courier_Interface {
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['office']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGCouriers_Quote {
                throw new \RuntimeException('quote() must not be called when live_quote capability is absent');
            }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $format = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
        $q = BGCouriers_Pricing::quote($fake, ['method' => 'office']);
        $this->assertSame('standard', $q->source);
        $this->assertEqualsWithDelta(9.0, $q->price, 0.001);
    }
}
