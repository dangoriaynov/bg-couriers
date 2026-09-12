<?php
/**
 * Checkout must be blocked - with a clear, courier-named error - whenever the delivery destination
 * isn't validly specified, and the saved selection must belong to the courier actually chosen.
 *
 * @group core
 */
final class CheckoutValidationTest extends WP_UnitTestCase {
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
}
