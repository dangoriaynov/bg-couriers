<?php
/**
 * @group boxnow
 */
final class BoxnowPersistenceTest extends WP_UnitTestCase {
    public function test_boxnow_persists_locker_as_delivery_point(): void {
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_boxnow']); // chosen courier = boxnow
        WC()->session->set('bgcouriers_selection_courier', 'boxnow');
        WC()->session->set('bgcouriers_method', 'office'); // stale/wrong: BoxNow is locker-only
        WC()->session->set('bgcouriers_office_id', 8009);   // the chosen locker (APM) id
        WC()->session->set('bgcouriers_boxnow_name', 'APM Sofia Center');
        WC()->session->set('bgcouriers_boxnow_addr', 'ul. Vitosha 1, Sofia');
        $order = new WC_Order();
        (new BGCouriers_Checkout())->persist($order);
        $order->save();
        $reloaded = wc_get_order($order->get_id());

        // Method is forced to the locker method, not the leaked 'office'.
        $this->assertSame('automat', $reloaded->get_meta('_bgcouriers_method'));
        $this->assertSame('8009', (string) $reloaded->get_meta('_bgcouriers_office_id'));
        $this->assertSame('boxnow', $reloaded->get_meta('_bgcouriers_courier'));
        // Locker label/address are saved for display on the order.
        $this->assertSame('APM Sofia Center', $reloaded->get_meta('_bgcouriers_boxnow_name'));
        $this->assertSame('ul. Vitosha 1, Sofia', $reloaded->get_meta('_bgcouriers_boxnow_addr'));
        // And the shipping address block shows the locker (so the order is not blank).
        $this->assertSame('APM Sofia Center', $reloaded->get_shipping_address_1());
        $this->assertSame('ul. Vitosha 1, Sofia', $reloaded->get_shipping_address_2());
    }
}
