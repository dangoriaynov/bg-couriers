<?php
/**
 * An office with no town is not rendered as a selection.
 *
 * The session can hold exactly that: the checkout's clear button on the town never reached the
 * session (WooCommerce's selectWoo fires no select2:clear - see bgc-checkout.js), so the next save
 * sent the office the customer still saw with the town they had cleared. The block was then rendered
 * with the office and no town, and the browser disabled the office field for want of a town: one
 * office, greyed out, no list to open. Measured on dev on 2026-09-12 (owner: "1 office auto-selected
 * and greyed out"). The town is what makes an office mean anything; without it, neither is shown.
 *
 * @group core
 */
final class OfficeWithoutTownTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        bgcouriers_test_set_up_courier('speedy');
        // Other tests in this suite empty the courier registry, and render_fields() prints nothing for
        // a courier it cannot find - on which "not contains" would pass for free. So the courier is
        // put back here when it is gone; the block reads the nomenclature tables, not the adapter.
        if (!BGCouriers_Couriers::get('speedy')) {
            BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Town_Probe(); });
        }
        WC()->session = WC()->session ?: new WC_Session_Handler();
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id' => 56784, 'name' => 'ПЛОВДИВ', 'post_code' => '4000', 'country' => 'BG'],
        ], 'test-run');
        BGCouriers_Nomenclature::upsert_offices('speedy', [
            ['office_id' => 2, 'code' => '2', 'city_id' => 56784, 'type' => 'office', 'name' => 'ПЛОВДИВ - 2 - РО РПУ',
             'address' => 'ул. ХРИСТО ЧЕРНОПЕЕВ No 5', 'lat' => 42.15, 'lng' => 24.75, 'country' => 'BG'],
            ['office_id' => 3, 'code' => '3', 'city_id' => 56784, 'type' => 'office', 'name' => 'ПЛОВДИВ - АСЕН ХРИСТОФОРОВ',
             'address' => 'ул. АСЕН ХРИСТОФОРОВ No 23', 'lat' => 42.15, 'lng' => 24.75, 'country' => 'BG'],
        ], 'test-run');
    }

    private function block(int $site_id, int $office_id): string {
        $s = WC()->session;
        $s->set('chosen_shipping_methods', ['bgcouriers_speedy']);
        $s->set('bgcouriers_selection_courier', 'speedy');
        $s->set('bgcouriers_method', 'office');
        $s->set('bgcouriers_site_id', $site_id);
        $s->set('bgcouriers_office_id', $office_id);
        $s->set('bgcouriers_post_code', '');
        $rate = new WC_Shipping_Rate('bgcouriers_speedy', 'Speedy', 0.0, [], 'bgcouriers_speedy');
        ob_start();
        (new BGCouriers_Checkout())->render_fields($rate, 0);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('bgc-office', $html, 'the courier block did not render at all');
        return $html;
    }

    public function test_an_office_saved_with_its_town_is_shown(): void {
        $html = $this->block(56784, 2);
        $this->assertStringContainsString('РО РПУ', $html, 'control: with the town, the office is rendered');
        $this->assertStringContainsString('value="56784" selected', $html);
    }

    public function test_an_office_saved_without_a_town_is_not_shown(): void {
        $html = $this->block(0, 2);
        $this->assertStringNotContainsString('РО РПУ', $html, 'an office with no town is half a selection, not one');
        $this->assertStringContainsString('<select class="bgc-office"></select>', $html, 'the office box is empty');
        $this->assertStringContainsString('<select class="bgc-city"><option value=""></option></select>', $html, 'and so is the town');
    }
}

final class BGCouriers_Town_Probe extends BGCouriers_Abstract_Courier {
    public function id(): string { return 'speedy'; }
    public function label(): string { return 'Speedy'; }
    public function capabilities(): array { return ['office', 'address']; }
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
