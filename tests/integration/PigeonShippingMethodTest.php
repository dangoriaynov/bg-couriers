<?php
/**
 * @group pigeon
 */
final class PigeonShippingMethodTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGC_Schema::create(); BGC_Rates::set('pigeon','office',4.99,'BGN'); }
    public function tear_down() { BGC_Couriers::reset(); parent::tear_down(); }

    private function throwing_courier(): BGC_Courier_Interface {
        return new class implements BGC_Courier_Interface {
            public function id(): string { return 'pigeon'; }
            public function label(): string { return 'Pigeon Express'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return false; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGC_Quote { throw new BGC_Api_Exception('down'); }
            public function create_label(\WC_Order $o): BGC_Label { return new BGC_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $format = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGC_Tracking { return new BGC_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    public function test_calculate_shipping_uses_cached_rate_when_api_down(): void {
        $fake = $this->throwing_courier();
        BGC_Couriers::reset();
        BGC_Couriers::register('pigeon', 'Pigeon Express', static function () use ($fake) { return $fake; });
        update_option('bgc_pigeon_enabled', 'yes');
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('bgc_method', 'office');

        $m = new BGC_Method_Pigeon();
        $m->calculate_shipping(['contents_weight' => 1.0]);

        $rates = $m->rates;
        $this->assertNotEmpty($rates);
        $rate = reset($rates);
        $this->assertEqualsWithDelta(4.99, (float) $rate->get_cost(), 0.001);
    }
}
