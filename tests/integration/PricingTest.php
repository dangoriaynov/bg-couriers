<?php
final class PricingTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGC_Schema::create(); }

    private function courier(bool $throws): BGC_Courier_Interface {
        return new class($throws) implements BGC_Courier_Interface {
            public function __construct(public bool $throws) {}
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGC_Quote {
                if ($this->throws) { throw new BGC_Api_Exception('down'); }
                return new BGC_Quote(4.0, 0.8, 'BGN', 'live');
            }
            public function create_label(\WC_Order $o): BGC_Label { return new BGC_Label(''); }
            public function get_label_pdf(string $w): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGC_Tracking { return new BGC_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    public function test_live_quote_used_when_available(): void {
        $q = BGC_Pricing::quote($this->courier(false), ['method'=>'office']);
        $this->assertSame('live', $q->source);
        $this->assertEqualsWithDelta(4.0, $q->price, 0.001);
    }
    public function test_falls_back_to_cached_standard_rate(): void {
        BGC_Rates::set('speedy', 'office', 7.5, 'BGN');
        $q = BGC_Pricing::quote($this->courier(true), ['method'=>'office']);
        $this->assertSame('standard', $q->source);
        $this->assertEqualsWithDelta(7.5, $q->total(), 0.001);
    }
}
