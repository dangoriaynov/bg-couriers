<?php
/**
 * BOX NOW has the same checkout block as every other courier.
 *
 * It was the one courier with a block of its own - BOX NOW's map widget in an iframe: a different
 * window, centred on Athens, asking the browser for a location, unable to take the town the customer
 * had named for the other couriers or on the combined map, and edited on the order screen through
 * three bare text boxes (owner, 2026-09-12: "may we have it unified with other couriers?"). BOX NOW
 * publishes no town list, so the towns are read off its lockers - each carries its town and postal
 * code - and from there the town, the locker, the map, the carry-over and the order all take the path
 * every courier takes.
 *
 * @group core
 */
final class BoxnowStandardBlockTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        bgcouriers_test_set_up_courier('speedy');
        bgcouriers_test_set_up_courier('boxnow');
        update_option('bgcouriers_boxnow_partner_id', 'p');
        update_option('bgcouriers_boxnow_warehouse_id', 'w');
        foreach (['speedy' => 'Speedy', 'boxnow' => 'BOX NOW'] as $id => $label) {
            if (!BGCouriers_Couriers::get($id)) {
                BGCouriers_Couriers::register($id, $label, static function () use ($id) { return new BGCouriers_Block_Probe($id); });
            }
        }
        WC()->session = WC()->session ?: new WC_Session_Handler();

        // What the sync writes for BOX NOW: towns read off the lockers, lockers stamped with their town.
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/boxnow/destinations.json'), true);
        $lockers = BGCouriers_Boxnow::parse_destinations($fixture);
        BGCouriers_Nomenclature::upsert_cities('boxnow', BGCouriers_Boxnow::towns_of($lockers), 'test-run');
        BGCouriers_Nomenclature::upsert_offices('boxnow', $lockers, 'test-run');
        // And Speedy's Sofia, as Speedy spells and codes it.
        BGCouriers_Nomenclature::upsert_cities('speedy', [['city_id' => 68134, 'name' => 'СОФИЯ', 'post_code' => '1000', 'country' => 'BG']], 'test-run');
    }

    private function block(string $courier): string {
        $rate = new WC_Shipping_Rate('bgcouriers_' . $courier, $courier, 0.0, [], 'bgcouriers_' . $courier);
        ob_start();
        (new BGCouriers_Checkout())->render_fields($rate, 0);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('bgc-fields', $html, 'the courier block did not render at all');
        return $html;
    }

    public function test_the_towns_and_lockers_are_in_the_tables_like_any_courier(): void {
        $sofia = BGCouriers_Boxnow::town_id('София');
        $this->assertSame('София', BGCouriers_Nomenclature::city_by_id('boxnow', $sofia)['name']);
        $this->assertCount(3, BGCouriers_Nomenclature::offices('boxnow', $sofia, 'automat'), 'all three Sofia lockers, under Sofia');
        $this->assertNull(BGCouriers_Nomenclature::office_by_id('boxnow', 2), 'the wildcard origin is not a destination');
        $idx = BGCouriers_Nomenclature::city_index('boxnow');
        $this->assertNotEmpty($idx['automat'], 'the preloaded town list the checkout searches has BOX NOW towns');
    }

    /**
     * The code BOX NOW's town is listed under is the one the other couriers use for it: its own lockers
     * say 1407 and 1000 for Sofia here, but on the live account they start at 1111 where Speedy, Econt
     * and the rest all say 1000 - and a "София (1111)" beside "СОФИЯ (1000)" on the combined map is
     * one town shown twice (the e2e map specs caught it on dev, 2026-09-13).
     */
    public function test_a_town_takes_the_code_the_other_couriers_give_it(): void {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/boxnow/destinations.json'), true);
        // The lockers' lowest real Sofia code in the fixture is 1000, so the other couriers are given a
        // code the lockers do not carry - only then does the outcome say whose code won. Two of them
        // agree and one differs: the most common one is taken.
        BGCouriers_Nomenclature::upsert_cities('speedy', [['city_id' => 68134, 'name' => 'СОФИЯ', 'post_code' => '1234', 'country' => 'BG']], 'test-run');
        BGCouriers_Nomenclature::upsert_cities('econt', [['city_id' => 41, 'name' => 'София', 'post_code' => '1234', 'country' => 'BG']], 'test-run');
        BGCouriers_Nomenclature::upsert_cities('pigeon', [['city_id' => 7, 'name' => 'София', 'post_code' => '1999', 'country' => 'BG']], 'test-run');
        $co = new class($fixture) extends BGCouriers_Boxnow {
            private $fx;
            public function __construct(array $fx) { parent::__construct([]); $this->fx = $fx; }
            protected function get_json(string $path, array $query = []): array { return $this->fx; }
        };
        $by = array_column($co->fetch_cities(), null, 'name');
        $this->assertSame('1234', $by['София']['post_code'], 'the code the other couriers agree on, not the lockers own 1000');
        $this->assertSame('9000', $by['Варна']['post_code'], 'a town no other courier lists keeps its lowest locker code');
        $this->assertSame('1234', BGCouriers_Nomenclature::post_code_by_name('София', 'BG', ['boxnow']));
        $this->assertSame('', BGCouriers_Nomenclature::post_code_by_name('Никъде', 'BG', ['boxnow']));
        // Control: BOX NOW's own rows never vote on BOX NOW's code.
        BGCouriers_Nomenclature::upsert_cities('boxnow', [['city_id' => BGCouriers_Boxnow::town_id('София'), 'name' => 'София', 'post_code' => '1111', 'country' => 'BG']], 'test-run');
        $this->assertSame('1234', BGCouriers_Nomenclature::post_code_by_name('София', 'BG', ['boxnow']));
    }

    public function test_boxnow_renders_the_standard_block_not_a_widget(): void {
        $s = WC()->session;
        $s->set('chosen_shipping_methods', ['bgcouriers_boxnow']);
        $s->set('bgcouriers_selection_courier', '');
        $html = $this->block('boxnow');
        $this->assertStringNotContainsString('bgc-boxnow-pick', $html, 'no widget button');
        $this->assertStringNotContainsString('bgc-boxnow', $html);
        $this->assertStringContainsString('class="bgc-city"', $html, 'a town field');
        $this->assertStringContainsString('class="bgc-office"', $html, 'a locker field');
        $this->assertStringContainsString('data-methods="automat"', $html, 'lockers only, as the courier declares');
    }

    /**
     * The town carries over by NAME when the code does not match. Speedy codes Sofia 1000; here BOX NOW
     * lists it under a district code, which is what a town whose lowest locker is not in the centre
     * gets - and the customer still finds Sofia already chosen when they switch to BOX NOW.
     */
    public function test_a_town_named_for_speedy_is_already_chosen_for_boxnow(): void {
        $sofia = BGCouriers_Boxnow::town_id('София');
        BGCouriers_Nomenclature::upsert_cities('boxnow', [['city_id' => $sofia, 'name' => 'София', 'post_code' => '1407', 'country' => 'BG']], 'test-run');
        $s = WC()->session;
        $s->set('chosen_shipping_methods', ['bgcouriers_boxnow']);
        $s->set('bgcouriers_selection_courier', 'speedy');
        $s->set('bgcouriers_method', 'office');
        $s->set('bgcouriers_site_id', 68134);
        $s->set('bgcouriers_office_id', 0);
        $s->set('bgcouriers_post_code', '1000');
        $s->set('bgcouriers_sel_by_courier', []);
        $html = $this->block('boxnow');
        $this->assertStringContainsString('value="' . $sofia . '" selected', $html, 'Sofia, as BOX NOW numbers it, already chosen');
        $this->assertStringContainsString('София (1407)', $html, 'labelled as BOX NOW lists it, like every courier own row');
        $this->assertStringContainsString('class="bgc-postcode" value="1000"', $html, 'the code the customer gave rides along, for the next carry');
    }

    /** Control for the carry: a town Speedy has and BOX NOW does not is not carried. */
    public function test_a_town_boxnow_does_not_serve_is_not_carried(): void {
        BGCouriers_Nomenclature::upsert_cities('speedy', [['city_id' => 5, 'name' => 'АЙТОС', 'post_code' => '8500', 'country' => 'BG']], 'test-run');
        $s = WC()->session;
        $s->set('chosen_shipping_methods', ['bgcouriers_boxnow']);
        $s->set('bgcouriers_selection_courier', 'speedy');
        $s->set('bgcouriers_method', 'office');
        $s->set('bgcouriers_site_id', 5);
        $s->set('bgcouriers_post_code', '8500');
        $s->set('bgcouriers_sel_by_courier', []);
        $html = $this->block('boxnow');
        $this->assertStringContainsString('<select class="bgc-city"><option value=""></option></select>', $html);
    }

    /** The order carries the locker's name and address off the nomenclature, as any office order does. */
    public function test_the_order_reads_the_locker_off_the_nomenclature(): void {
        $o = new WC_Order();
        $o->save();
        BGCouriers_Checkout::apply_delivery($o, ['courier' => 'boxnow', 'method' => 'automat',
            'site_id' => BGCouriers_Boxnow::town_id('София'), 'office_id' => 5365, 'post_code' => '1000']);
        $this->assertSame(5365, (int) $o->get_meta('_bgcouriers_office_id'), 'the locker id the delivery request sends');
        $this->assertSame('automat', $o->get_meta('_bgcouriers_method'));
        $this->assertSame('Test Locker 1', $o->get_meta('_bgcouriers_boxnow_name'), 'the name the order screen shows, from the row');
        $this->assertSame('Цар Симеон 170 София', $o->get_meta('_bgcouriers_boxnow_addr'));
        $this->assertSame('Test Locker 1', $o->get_shipping_address_1());
        $this->assertSame('Цар Симеон 170 София', $o->get_shipping_address_2());
        $this->assertSame('София', $o->get_shipping_city(), 'and the town, which the widget never gave the order');
    }

    /** No locker, no order - the same refusal every office courier gives, pointing at the locker field. */
    public function test_an_order_without_a_locker_is_refused_at_the_locker_field(): void {
        $s = WC()->session;
        $s->set('chosen_shipping_methods', ['bgcouriers_boxnow']);
        $s->set('bgcouriers_selection_courier', 'boxnow');
        $s->set('bgcouriers_method', 'automat');
        $s->set('bgcouriers_site_id', BGCouriers_Boxnow::town_id('София'));
        $s->set('bgcouriers_office_id', 0);
        $errors = new WP_Error();
        (new BGCouriers_Checkout())->validate(['billing_phone' => '0888123456'], $errors);
        $this->assertContains('bgc_office', $errors->get_error_codes());
        $this->assertSame(['id' => 'bgcouriers-office-boxnow'], $errors->get_error_data('bgc_office'), 'points at the locker field of the standard block');
    }
}

final class BGCouriers_Block_Probe extends BGCouriers_Abstract_Courier {
    private $id;
    public function __construct(string $id) { $this->id = $id; }
    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function capabilities(): array { return $this->id === 'boxnow' ? ['automat'] : ['office', 'address', 'automat']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
