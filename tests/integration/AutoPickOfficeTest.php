<?php
/**
 * A town with ONE pickup point needs no dropdown; a town with several must not answer for the customer.
 *
 * Reported from Айтос: Speedy has one counter and two lockers there, and choosing the town from the
 * list ticked the counter. It was the only counter, which is what the check asked - and the wrong
 * question, because the customer could have had either locker and was never shown that they had a
 * choice. Counted across the delivery options the courier actually offers now, so a locker the merchant
 * has switched off still does not count as an alternative.
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

    public function test_a_town_with_a_counter_and_two_lockers_is_left_to_the_customer(): void {
        // Айтос itself.
        $this->town(5002, [[900, 'office'], [901, 'automat'], [902, 'automat']]);
        $html = $this->block(5002);
        $this->assertStringNotContainsString('data-auto="1"', $html,
            'the only counter is not the only pickup point - the customer was never asked');
        // The CITY is ticked - the customer chose it. The office box is the one that must be empty.
        $this->assertStringContainsString('<select class="bgc-office"></select>', $html,
            'the office box has something in it, and nobody chose it');
    }

    /** Two of the same kind was never auto-picked, and still is not. */
    public function test_a_town_with_two_offices_is_left_to_the_customer(): void {
        $this->town(5003, [[900, 'office'], [903, 'office']]);
        $this->assertStringNotContainsString('data-auto="1"', $this->block(5003));
    }

    /**
     * A locker the merchant has switched off is not an alternative the customer could have taken, so
     * the single counter beside it is still the only thing there is.
     */
    public function test_a_locker_the_shop_does_not_offer_does_not_count(): void {
        update_option('bgcouriers_speedy_automat_enabled', 'no');
        $this->town(5004, [[900, 'office'], [901, 'automat']]);
        $html = $this->block(5004);
        update_option('bgcouriers_speedy_automat_enabled', 'yes');
        $this->assertStringContainsString('data-auto="1"', $html);
    }
}
