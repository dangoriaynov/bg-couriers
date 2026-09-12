<?php
/**
 * Checkout must be blocked - with a clear, courier-named error - whenever the delivery destination
 * isn't validly specified, and the saved selection must belong to the courier actually chosen.
 *
 * @group core
 */
final class CheckoutValidationTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); }

    /** Put a city and its offices in the plugin's own nomenclature, the way a sync would. */
    private function sync(string $courier, int $city_id, array $office_ids): void {
        BGCouriers_Nomenclature::upsert_cities($courier, [
            ['city_id' => $city_id, 'name' => 'ТЕСТОВ ГРАД', 'post_code' => '1000', 'country' => 'BG'],
        ], 'test-run');
        foreach ($office_ids as $oid) {
            BGCouriers_Nomenclature::upsert_offices($courier, [
                ['office_id' => $oid, 'code' => (string) $oid, 'city_id' => $city_id, 'type' => 'office',
                 'name' => 'Офис ' . $oid, 'address' => 'ул. Тестова 1', 'lat' => 42.7, 'lng' => 23.3, 'country' => 'BG'],
            ], 'test-run');
        }
    }

    /**
     * @param array $post What the checkout posted. A phone by default: it has been required of every
     *                    order since 2026-08-26 (Express One will not carry a parcel to a locker
     *                    without one), and these tests posted nothing at all - so the three that assert
     *                    a valid destination PASSES had been failing on the missing phone ever since,
     *                    never reaching the destination rules they exist to check.
     */
    private function errors(string $chosen, array $session, array $post = ['billing_phone' => '0888123456']): WP_Error {
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_' . $chosen]);
        $defaults = [
            'bgcouriers_selection_courier' => '', 'bgcouriers_method' => '', 'bgcouriers_site_id' => 0, 'bgcouriers_office_id' => 0,
            'bgcouriers_addr_street_name' => '', 'bgcouriers_addr_street_no' => '',
        ];
        foreach (array_merge($defaults, $session) as $k => $v) { WC()->session->set($k, $v); }
        $e = new WP_Error();
        (new BGCouriers_Checkout())->validate($post, $e);
        return $e;
    }

    public function test_boxnow_without_locker_is_blocked(): void {
        $e = $this->errors('boxnow', ['bgcouriers_selection_courier' => 'boxnow', 'bgcouriers_office_id' => 0]);
        $this->assertNotEmpty($e->get_error_messages(), 'BoxNow with no locker must be blocked');
    }

    public function test_boxnow_with_locker_passes(): void {
        $e = $this->errors('boxnow', ['bgcouriers_selection_courier' => 'boxnow', 'bgcouriers_office_id' => 8009]);
        $this->assertEmpty($e->get_error_messages(), 'BoxNow with a locker must pass');
    }

    /** A selection made for a different courier must not satisfy the chosen one. */
    public function test_selection_from_another_courier_is_blocked(): void {
        $e = $this->errors('speedy', ['bgcouriers_selection_courier' => 'econt', 'bgcouriers_method' => 'office', 'bgcouriers_site_id' => 41, 'bgcouriers_office_id' => 100]);
        $this->assertNotEmpty($e->get_error_messages(), 'Stale cross-courier selection must be blocked');
    }

    public function test_office_delivery_needs_city_and_office(): void {
        $e = $this->errors('speedy', ['bgcouriers_selection_courier' => 'speedy', 'bgcouriers_method' => 'office', 'bgcouriers_site_id' => 0, 'bgcouriers_office_id' => 0]);
        $this->assertNotEmpty($e->get_error_messages());
        // Each refusal names the field it is about, under its own code: WooCommerce hands a WP_Error's
        // data to the notice per code, and the checkout script turns that id into a link and a red box.
        $this->assertSame(['id' => 'bgcouriers-city-speedy'], $e->get_error_data('bgc_city'));
        $this->assertSame(['id' => 'bgcouriers-office-speedy'], $e->get_error_data('bgc_office'));
    }

    public function test_a_missing_number_points_at_the_number_not_the_street(): void {
        $e = $this->errors('econt', ['bgcouriers_selection_courier' => 'econt', 'bgcouriers_method' => 'address', 'bgcouriers_site_id' => 41, 'bgcouriers_addr_street_name' => 'Витоша', 'bgcouriers_addr_street_no' => '']);
        $this->assertSame(['id' => 'bgcouriers-streetno-econt'], $e->get_error_data('bgc_street'));
    }

    public function test_office_delivery_valid_passes(): void {
        $e = $this->errors('speedy', ['bgcouriers_selection_courier' => 'speedy', 'bgcouriers_method' => 'office', 'bgcouriers_site_id' => 41, 'bgcouriers_office_id' => 100]);
        $this->assertEmpty($e->get_error_messages());
    }

    public function test_address_delivery_needs_street_and_number(): void {
        $e = $this->errors('econt', ['bgcouriers_selection_courier' => 'econt', 'bgcouriers_method' => 'address', 'bgcouriers_site_id' => 41, 'bgcouriers_addr_street_name' => '', 'bgcouriers_addr_street_no' => '']);
        $this->assertNotEmpty($e->get_error_messages());
    }

    public function test_address_delivery_valid_passes(): void {
        $e = $this->errors('econt', ['bgcouriers_selection_courier' => 'econt', 'bgcouriers_method' => 'address', 'bgcouriers_site_id' => 41, 'bgcouriers_addr_street_name' => 'Витоша', 'bgcouriers_addr_street_no' => '1']);
        $this->assertEmpty($e->get_error_messages());
    }

    /**
     * The city and office ids arrive as bare integers on a request and used to be checked only for
     * being above zero, while the courier's whole nomenclature sat in the plugin's own tables. An id
     * naming nothing produced an order with an empty shipping address that looked complete and could
     * not be turned into a waybill.
     */
    public function test_an_office_the_courier_does_not_list_is_blocked(): void {
        $this->sync('speedy', 41, [100, 101]);
        $e = $this->errors('speedy', ['bgcouriers_selection_courier' => 'speedy', 'bgcouriers_method' => 'office',
            'bgcouriers_site_id' => 41, 'bgcouriers_office_id' => 999999]);
        $this->assertNotEmpty($e->get_error_messages(), 'an office nobody lists must be blocked');
        $this->assertSame(['id' => 'bgcouriers-office-speedy'], $e->get_error_data('bgc_office'));
    }

    public function test_a_city_the_courier_does_not_list_is_blocked(): void {
        $this->sync('speedy', 41, [100]);
        $e = $this->errors('speedy', ['bgcouriers_selection_courier' => 'speedy', 'bgcouriers_method' => 'office',
            'bgcouriers_site_id' => 777777, 'bgcouriers_office_id' => 100]);
        $this->assertSame(['id' => 'bgcouriers-city-speedy'], $e->get_error_data('bgc_city'));
    }

    public function test_a_real_city_and_office_still_pass(): void {
        $this->sync('speedy', 41, [100, 101]);
        $e = $this->errors('speedy', ['bgcouriers_selection_courier' => 'speedy', 'bgcouriers_method' => 'office',
            'bgcouriers_site_id' => 41, 'bgcouriers_office_id' => 101]);
        $this->assertEmpty($e->get_error_messages());
    }

    /**
     * And a shop whose nomenclature has not been synced is never refused on the strength of it.
     * Turning our own missing data into the customer's dead end, at the last step of the checkout,
     * would be worse than the fault this closes - so an empty table means yes, not no.
     */
    public function test_an_unsynced_courier_does_not_refuse_anything(): void {
        $e = $this->errors('econt', ['bgcouriers_selection_courier' => 'econt', 'bgcouriers_method' => 'office',
            'bgcouriers_site_id' => 41, 'bgcouriers_office_id' => 100]);
        $this->assertEmpty($e->get_error_messages());
    }
}
