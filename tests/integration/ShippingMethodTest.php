<?php
/**
 * @group speedy
 */
final class ShippingMethodTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); BGCouriers_Rates::set('speedy','office',5.55,'BGN'); }
    public function tear_down() { BGCouriers_Couriers::reset(); parent::tear_down(); }

    private function throwing_courier(): BGCouriers_Courier_Interface {
        return new class implements BGCouriers_Courier_Interface {
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return false; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('down'); }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $format = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    public function test_calculate_shipping_uses_cached_rate_when_api_down(): void {
        $fake = $this->throwing_courier();
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () use ($fake) { return $fake; });
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('bgcouriers_method', 'office');

        $m = new BGCouriers_Method_Speedy();
        $m->calculate_shipping(['contents_weight' => 1.0]);

        $rates = $m->rates;
        $this->assertNotEmpty($rates);
        $rate = reset($rates);
        $this->assertEqualsWithDelta(5.55, (float) $rate->get_cost(), 0.001);
    }

    /**
     * The same courier, the same cached rate, an address in a country nobody switched on: no rate at
     * all. 5.55 is a Bulgarian price and offering it here would sell a delivery the shop cannot make.
     */
    public function test_a_country_the_courier_does_not_serve_gets_no_rate(): void {
        $fake = $this->throwing_courier();
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () use ($fake) { return $fake; });
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('bgcouriers_method', 'office');
        WC()->session->set('bgcouriers_country', 'RO');

        $m = new BGCouriers_Method_Speedy();
        $m->calculate_shipping(['contents_weight' => 1.0, 'destination' => ['country' => 'RO']]);
        WC()->session->set('bgcouriers_country', '');

        $this->assertEmpty($m->rates, 'a courier that does not go there must not be offered');
    }

    /**
     * Switched on for Romania, but the courier cannot be reached: still no rate. The cached 5.55 is the
     * price of a Bulgarian parcel and there is no international price to fall back on.
     */
    public function test_an_enabled_country_with_no_live_price_gets_no_rate(): void {
        update_option('bgcouriers_speedy_intl_countries', ['RO']);
        $fake = new class extends BGCouriers_Abstract_Courier {
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return false; }
            public function fetch_cities(string $country = ''): array { return []; }
            public function fetch_offices(int $c, string $country = ''): array { return []; }
            public function intl_countries(): array { return ['RO']; }
            public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('down'); }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $format = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () use ($fake) { return $fake; });
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('bgcouriers_method', 'office');
        WC()->session->set('bgcouriers_country', 'RO');

        $m = new BGCouriers_Method_Speedy();
        $m->calculate_shipping(['contents_weight' => 1.0, 'destination' => ['country' => 'RO']]);
        WC()->session->set('bgcouriers_country', '');
        delete_option('bgcouriers_speedy_intl_countries');

        $this->assertEmpty($m->rates, 'no live price abroad means no delivery offered, not a home price');
    }
}
