<?php
final class ShippingMethodTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGC_Schema::create(); BGC_Rates::set('speedy','office',5.55,'BGN'); }

    private function throwing_courier(): BGC_Courier_Interface {
        return new class implements BGC_Courier_Interface {
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function check_credentials(): bool { return false; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGC_Quote { throw new BGC_Api_Exception('down'); }
            public function create_label(\WC_Order $o): BGC_Label { return new BGC_Label(''); }
            public function get_label_pdf(string $w): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGC_Tracking { return new BGC_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    public function test_calculate_shipping_uses_cached_rate_when_api_down(): void {
        $fake = $this->throwing_courier();
        add_filter('bgc_courier', function ($c, $id) use ($fake) { return $id === 'speedy' ? $fake : $c; }, 10, 2);
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('bgc_method', 'office');

        $m = new BGC_Method_Speedy();
        $m->calculate_shipping(['contents_weight' => 1.0]);

        $rates = $m->rates;
        $this->assertNotEmpty($rates);
        $rate = reset($rates);
        $this->assertEqualsWithDelta(5.55, (float) $rate->get_cost(), 0.001);
    }
}
