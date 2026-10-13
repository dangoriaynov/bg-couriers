<?php
/**
 * A tab (office or locker) holding ONE pickup point needs no dropdown; a tab with several is the customer's.
 *
 * History: Aytos (one counter, two lockers) once made this depend on the whole town holding a single
 * pickup point. Beloslav (one counter, a locker) then showed the cost: the customer opened "To office",
 * got a one-row dropdown, did not click it and the order was refused. The tab now decides.
 *
 * @group core
 */
final class AutoPickOfficeTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        bgcouriers_test_set_up_courier('speedy');
        WC()->session = WC()->session ?: new WC_Session_Handler();
    }
    // Deliberately NOT resetting the courier registry: this test registers nothing, and emptying it
    // makes render_fields() return before it prints anything - on which assertStringNotContainsString
    // passes for free. Two of these tests did exactly that until the block below started asserting
    // that something was rendered at all.

    /** @param array $offices [[office_id, type], ...] */
    private function town(int $city_id, array $offices): void {
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id' => $city_id, 'name' => 'АЙТОС', 'post_code' => '8500', 'country' => 'BG'],
        ], 'test-run');
        foreach ($offices as $i => $o) {
            BGCouriers_Nomenclature::upsert_offices('speedy', [
                ['office_id' => $o[0], 'code' => (string) $o[0], 'city_id' => $city_id, 'type' => $o[1],
                 'name' => 'АЙТОС ' . $o[0], 'address' => 'ул. Тестова ' . $i, 'lat' => 42.7, 'lng' => 27.2,
                 'country' => 'BG'],
            ], 'test-run');
        }
    }

    /** The block as the checkout renders it, for a customer who has chosen the town and nothing else. */
    private function block(int $city_id, string $method = 'office'): string {
        $s = WC()->session;
        $s->set('chosen_shipping_methods', ['bgcouriers_speedy']);
        $s->set('bgcouriers_selection_courier', 'speedy');
        $s->set('bgcouriers_method', $method);
        $s->set('bgcouriers_site_id', $city_id);
        $s->set('bgcouriers_office_id', 0);
        $rate = new WC_Shipping_Rate('bgcouriers_speedy', 'Speedy', 0.0, [], 'bgcouriers_speedy');
        ob_start();
        (new BGCouriers_Checkout())->render_fields($rate, 0);
        $html = (string) ob_get_clean();
        // The guard against a vacuous pass: every assertion below is about what is IN this markup, and
        // an empty string satisfies half of them.
        $this->assertStringContainsString('bgc-office', $html, 'the courier block did not render at all');
        return $html;
    }

    public function test_a_town_with_one_office_and_nothing_else_is_chosen_for_the_customer(): void {
        $this->town(5001, [[900, 'office']]);
        $html = $this->block(5001);
        $this->assertStringContainsString('data-auto="1"', $html, 'one pickup point in the town is not a choice');
        $this->assertStringContainsString('АЙТОС 900', $html);
    }

    public function test_the_only_counter_is_chosen_even_when_the_town_also_has_lockers(): void {
        // Beloslav: one counter and a locker. The customer on the "To office" tab has exactly one row to pick.
        $this->town(5002, [[900, 'office'], [901, 'automat'], [902, 'automat']]);
        $html = $this->block(5002);
        $this->assertStringContainsString('data-auto="1"', $html, 'the only counter on the tab was left for the customer to click');
        $this->assertStringContainsString('АЙТОС 900', $html);
        $this->assertStringNotContainsString('АЙТОС 901', $html, 'a locker was put into the counter tab');
    }

    public function test_two_lockers_on_the_locker_tab_are_left_to_the_customer(): void {
        $this->town(5005, [[900, 'office'], [901, 'automat'], [902, 'automat']]);
        $html = $this->block(5005, 'automat');
        $this->assertStringNotContainsString('data-auto="1"', $html, 'two lockers are a choice');
        $this->assertStringContainsString('<select class="bgc-office"></select>', $html);
    }

    public function test_the_only_locker_is_chosen_on_the_locker_tab(): void {
        $this->town(5006, [[900, 'office'], [901, 'office'], [902, 'automat']]);
        $html = $this->block(5006, 'automat');
        $this->assertStringContainsString('data-auto="1"', $html);
        $this->assertStringContainsString('АЙТОС 902', $html);
    }

    /** Two of the same kind was never auto-picked, and still is not. */
    public function test_a_town_with_two_offices_is_left_to_the_customer(): void {
        $this->town(5003, [[900, 'office'], [903, 'office']]);
        $this->assertStringNotContainsString('data-auto="1"', $this->block(5003));
    }
}
