<?php
/**
 * A BOX NOW order carries its locker the way any locker order does: the id the delivery request sends,
 * the method forced to the locker kind, and the locker's name and address on the shipping lines - read
 * off the nomenclature by that id, since 2026-09-12 when BOX NOW's own widget (which used to hand the
 * checkout a name and an address of its own, kept in two meta keys of their own) was replaced by the
 * standard town + locker block. Those two keys are written by nothing now.
 *
 * @group boxnow
 */
final class BoxnowPersistenceTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); }

    public function test_boxnow_persists_locker_as_delivery_point(): void {
        $town = BGCouriers_Boxnow::town_id('София');
        BGCouriers_Nomenclature::upsert_cities('boxnow', [['city_id' => $town, 'name' => 'София', 'post_code' => '1000', 'country' => 'BG']], 'test-run');
        BGCouriers_Nomenclature::upsert_offices('boxnow', [['office_id' => 8009, 'code' => '8009', 'city_id' => $town, 'type' => 'automat',
            'name' => 'APM Sofia Center', 'address' => 'ul. Vitosha 1 София', 'lat' => 42.7, 'lng' => 23.3, 'country' => 'BG']], 'test-run');
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_boxnow']); // chosen courier = boxnow
        WC()->session->set('bgcouriers_selection_courier', 'boxnow');
        WC()->session->set('bgcouriers_method', 'office'); // stale/wrong: BoxNow is locker-only
        WC()->session->set('bgcouriers_site_id', $town);
        WC()->session->set('bgcouriers_office_id', 8009);   // the chosen locker (APM) id
        WC()->session->set('bgcouriers_post_code', '1000');
        // What a session from the old widget would still hold - nothing reads it any more.
        WC()->session->set('bgcouriers_boxnow_name', 'stale widget name');
        WC()->session->set('bgcouriers_boxnow_addr', 'stale widget address');
        $order = new WC_Order();
        (new BGCouriers_Checkout())->persist($order);
        $order->save();
        $reloaded = wc_get_order($order->get_id());

        // Method is forced to the locker method, not the leaked 'office'.
        $this->assertSame('automat', $reloaded->get_meta('_bgcouriers_method'));
        $this->assertSame('8009', (string) $reloaded->get_meta('_bgcouriers_office_id'));
        $this->assertSame('boxnow', $reloaded->get_meta('_bgcouriers_courier'));
        $this->assertSame((string) $town, (string) $reloaded->get_meta('_bgcouriers_site_id'), 'and the town, which the widget never gave the order');
        // The widget-era meta is not written - not even from the stale session.
        $this->assertSame('', (string) $reloaded->get_meta('_bgcouriers_boxnow_name'));
        $this->assertSame('', (string) $reloaded->get_meta('_bgcouriers_boxnow_addr'));
        // The shipping address block shows the locker, from the nomenclature (so the order is not blank).
        $this->assertSame('APM Sofia Center', $reloaded->get_shipping_address_1());
        $this->assertSame('ul. Vitosha 1 София', $reloaded->get_shipping_address_2());
        $this->assertSame('София', $reloaded->get_shipping_city());
        $this->assertSame('1000', $reloaded->get_shipping_postcode());
    }
}
