<?php
/**
 * An order placed through the checkout block keeps the address the courier selection gave it.
 *
 * WooCommerce's own address fields are hidden on the block, so its customer object holds blanks, and one
 * of the Store API's syncs of customer to order can run after the plugin's persist() - seen once on
 * 2026-09-14: courier meta on the order, an empty shipping address. The last hook before payment puts
 * it back where it is missing, and leaves an order that has one alone.
 *
 * @group core
 */
final class BlocksAddressSurvivesTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); bgcouriers_test_set_up_courier('speedy'); }
    public function tear_down() { BGCouriers_Couriers::reset(); parent::tear_down(); }

    private function session_selection(): void {
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_speedy']);
        WC()->session->set('bgcouriers_selection_courier', 'speedy');
        WC()->session->set('bgcouriers_method', 'address');
        WC()->session->set('bgcouriers_site_id', 68134);
        foreach (['street_name' => 'ВИТОША', 'street_type' => 'ул.', 'street_id' => 1314, 'street_no' => '10'] as $k => $v) { WC()->session->set('bgcouriers_addr_' . $k, $v); }
    }

    public function test_an_order_whose_address_was_blanked_gets_it_back(): void {
        $this->session_selection();
        $blocks = new BGCouriers_Blocks(new BGCouriers_Checkout());
        $order = wc_create_order();
        $blocks->persist($order);
        $this->assertSame('ул. ВИТОША 10', $order->get_shipping_address_1(), 'persist writes the address');
        // WooCommerce's sync runs over it with the customer's blanks.
        $order->set_shipping_address_1(''); $order->set_shipping_city(''); $order->set_shipping_postcode('');
        $order->save();
        $blocks->ensure_address($order);
        $fresh = wc_get_order($order->get_id());
        $this->assertSame('ул. ВИТОША 10', $fresh->get_shipping_address_1());
        $this->assertSame('1314', (string) $fresh->get_meta('_bgcouriers_street_id'), 'the meta was there all along');
    }

    public function test_an_order_with_an_address_is_left_alone(): void {
        $this->session_selection();
        $blocks = new BGCouriers_Blocks(new BGCouriers_Checkout());
        $order = wc_create_order();
        $order->update_meta_data('_bgcouriers_courier', 'speedy');
        $order->set_shipping_address_1('Something else 5'); $order->set_shipping_city('Пловдив');
        $order->save();
        $blocks->ensure_address($order);
        $this->assertSame('Something else 5', wc_get_order($order->get_id())->get_shipping_address_1());
    }

    public function test_an_order_that_is_not_ours_is_left_alone(): void {
        $this->session_selection();
        $blocks = new BGCouriers_Blocks(new BGCouriers_Checkout());
        $order = wc_create_order();
        $order->save();
        $blocks->ensure_address($order);
        $this->assertSame('', wc_get_order($order->get_id())->get_shipping_address_1());
    }
}
