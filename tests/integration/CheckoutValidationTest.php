<?php
/**
 * Checkout must be blocked - with a clear, courier-named error - whenever the delivery destination
 * isn't validly specified, and the saved selection must belong to the courier actually chosen.
 *
 * @group core
 */
final class CheckoutValidationTest extends WP_UnitTestCase {
    private function errors(string $chosen, array $session): WP_Error {
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgc_' . $chosen]);
        $defaults = [
            'bgc_selection_courier' => '', 'bgc_method' => '', 'bgc_site_id' => 0, 'bgc_office_id' => 0,
            'bgc_addr_street_name' => '', 'bgc_addr_street_no' => '',
        ];
        foreach (array_merge($defaults, $session) as $k => $v) { WC()->session->set($k, $v); }
        $e = new WP_Error();
        (new BGC_Checkout())->validate([], $e);
        return $e;
    }

    public function test_boxnow_without_locker_is_blocked(): void {
        $e = $this->errors('boxnow', ['bgc_selection_courier' => 'boxnow', 'bgc_office_id' => 0]);
        $this->assertNotEmpty($e->get_error_messages(), 'BoxNow with no locker must be blocked');
    }

    public function test_boxnow_with_locker_passes(): void {
        $e = $this->errors('boxnow', ['bgc_selection_courier' => 'boxnow', 'bgc_office_id' => 8009]);
        $this->assertEmpty($e->get_error_messages(), 'BoxNow with a locker must pass');
    }

    /** A selection made for a different courier must not satisfy the chosen one. */
    public function test_selection_from_another_courier_is_blocked(): void {
        $e = $this->errors('speedy', ['bgc_selection_courier' => 'econt', 'bgc_method' => 'office', 'bgc_site_id' => 41, 'bgc_office_id' => 100]);
        $this->assertNotEmpty($e->get_error_messages(), 'Stale cross-courier selection must be blocked');
    }

    public function test_office_delivery_needs_city_and_office(): void {
        $e = $this->errors('speedy', ['bgc_selection_courier' => 'speedy', 'bgc_method' => 'office', 'bgc_site_id' => 0, 'bgc_office_id' => 0]);
        $this->assertNotEmpty($e->get_error_messages());
    }

    public function test_office_delivery_valid_passes(): void {
        $e = $this->errors('speedy', ['bgc_selection_courier' => 'speedy', 'bgc_method' => 'office', 'bgc_site_id' => 41, 'bgc_office_id' => 100]);
        $this->assertEmpty($e->get_error_messages());
    }

    public function test_address_delivery_needs_street_and_number(): void {
        $e = $this->errors('econt', ['bgc_selection_courier' => 'econt', 'bgc_method' => 'address', 'bgc_site_id' => 41, 'bgc_addr_street_name' => '', 'bgc_addr_street_no' => '']);
        $this->assertNotEmpty($e->get_error_messages());
    }

    public function test_address_delivery_valid_passes(): void {
        $e = $this->errors('econt', ['bgc_selection_courier' => 'econt', 'bgc_method' => 'address', 'bgc_site_id' => 41, 'bgc_addr_street_name' => 'Витоша', 'bgc_addr_street_no' => '1']);
        $this->assertEmpty($e->get_error_messages());
    }
}
