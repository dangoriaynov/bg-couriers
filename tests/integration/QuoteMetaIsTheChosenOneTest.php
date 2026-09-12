<?php
/**
 * The price recorded on an order must be the price of the courier the customer CHOSE.
 *
 * Every shipping method wrote what it had just quoted into one session key, and WooCommerce calculates
 * every method in the zone on every recalculation - so the key held whichever courier happened to run
 * last. The order then carried that number, and the source beside it, whoever the customer had picked.
 *
 * It is the same shape of fault this plugin has already been bitten by twice: one session key shared by
 * every courier the customer has opened. The selection itself was fixed by tagging it with the courier
 * it belongs to; the quote was not.
 *
 * @group core
 */
final class QuoteMetaIsTheChosenOneTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        WC()->session = WC()->session ?: new WC_Session_Handler();
        // Two couriers of this test's own, the same way ShippingMethodTest does it. A method whose
        // courier is not registered quotes nothing at all, so without this the session would keep
        // whatever the previous test left and this would pass or fail on running order - which is
        // exactly what happened the first time it was written. Registering the REAL adapters instead
        // would leave them behind for every test after this one, and those reach live APIs.
        BGCouriers_Couriers::reset();
        foreach (['speedy' => 'Speedy', 'econt' => 'Econt'] as $cid => $name) {
            $fake = new class($cid) implements BGCouriers_Courier_Interface {
                public function __construct(private string $cid) {}
                public function id(): string { return $this->cid; }
                public function label(): string { return $this->cid; }
                public function capabilities(): array { return ['office', 'live_quote']; }
                public function available_methods(): array { return ['office']; }
                public function check_credentials(): bool { return true; }
                public function fetch_cities(): array { return []; }
                public function fetch_offices(int $c): array { return []; }
                // Never reached: both couriers are in fixed price mode, which returns before the API.
                public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('not used'); }
                public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
                public function label_formats(): array { return []; }
                public function get_label_pdf(string $w, string $f = ''): string { return ''; }
                public function cancel_label(string $w): bool { return true; }
                public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('', '', []); }
                public function tracking_url(string $w): string { return ''; }
            };
            BGCouriers_Couriers::register($cid, $name, static function () use ($fake) { return $fake; });
        }
        foreach (['speedy', 'econt'] as $c) {
            bgcouriers_test_set_up_courier($c);
            update_option('bgcouriers_' . $c . '_ship_in_total', 'yes');   // so the rate carries the cost
            update_option('bgcouriers_' . $c . '_office_price_mode', 'fixed');   // no live call in a test
        }
        // Two different fixed prices, so which courier answered is visible in the number itself.
        update_option('bgcouriers_speedy_office_price', '3.33');
        update_option('bgcouriers_econt_office_price', '7.77');
    }
    public function tear_down() {
        // Left as this test found it - empty - which is what every other test here that registers its
        // own courier does, and what the ones that need a real courier already put back for themselves.
        BGCouriers_Couriers::reset();
        WC()->session->set('bgcouriers_quote_price_speedy', null);
        WC()->session->set('bgcouriers_quote_price_econt', null);
        parent::tear_down();
    }

    /** Run both couriers' shipping methods, the way WooCommerce does on every recalculation. */
    private function calculate_both(): void {
        WC()->session->set('bgcouriers_method', 'office');
        (new BGCouriers_Method_Speedy())->calculate_shipping(['contents_weight' => 1.0, 'destination' => ['country' => 'BG']]);
        (new BGCouriers_Method_Econt())->calculate_shipping(['contents_weight' => 1.0, 'destination' => ['country' => 'BG']]);
    }

    /** The customer picked Speedy, and Econt was simply the last one WooCommerce happened to price. */
    public function test_the_order_records_the_chosen_couriers_price_not_the_last_one_calculated(): void {
        $this->calculate_both();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_speedy']);
        WC()->session->set('bgcouriers_selection_courier', 'speedy');

        $order = new WC_Order();
        $order->save();
        (new BGCouriers_Checkout())->persist($order);

        $this->assertSame('speedy', (string) $order->get_meta('_bgcouriers_courier'), 'the chosen courier is recorded');
        $this->assertEqualsWithDelta(3.33, (float) $order->get_meta('_bgcouriers_quote_price'), 0.001,
            'the order carries what the CHOSEN courier quoted, not what the last one did');
    }

    /** And the other way round, so the test cannot pass by the ordering happening to suit it. */
    public function test_the_same_holds_when_the_last_one_calculated_is_the_chosen_one(): void {
        $this->calculate_both();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_econt']);
        WC()->session->set('bgcouriers_selection_courier', 'econt');

        $order = new WC_Order();
        $order->save();
        (new BGCouriers_Checkout())->persist($order);

        $this->assertSame('econt', (string) $order->get_meta('_bgcouriers_courier'));
        $this->assertEqualsWithDelta(7.77, (float) $order->get_meta('_bgcouriers_quote_price'), 0.001);
    }

    /** The source travels with the price - it is the answer to "was that a live price or a fallback?". */
    public function test_the_source_recorded_belongs_to_the_same_courier(): void {
        $this->calculate_both();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_speedy']);
        WC()->session->set('bgcouriers_selection_courier', 'speedy');

        $order = new WC_Order();
        $order->save();
        (new BGCouriers_Checkout())->persist($order);

        $this->assertSame('fixed', (string) $order->get_meta('_bgcouriers_quote_source'),
            'both couriers are in fixed mode here, and the recorded source is the chosen one own');
    }
}
