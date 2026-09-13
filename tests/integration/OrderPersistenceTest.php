<?php
/**
 * @group speedy
 */
final class OrderPersistenceTest extends WP_UnitTestCase {
    public function test_persist_writes_meta_via_crud(): void {
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_speedy']); // so chosen_is_speedy() passes
        WC()->session->set('bgcouriers_method', 'office');
        WC()->session->set('bgcouriers_site_id', 68134);
        WC()->session->set('bgcouriers_office_id', 307);
        // Per courier since 0.4.8 - every shipping method in the zone writes one of these on every
        // recalculation, so a shared key held whichever one ran last.
        WC()->session->set('bgcouriers_quote_price_speedy', 6.24);
        WC()->session->set('bgcouriers_quote_source_speedy', 'live');
        $order = new WC_Order();
        (new BGCouriers_Checkout())->persist($order);
        $order->save();
        $reloaded = wc_get_order($order->get_id());
        $this->assertSame('office', $reloaded->get_meta('_bgcouriers_method'));
        $this->assertSame('307', (string) $reloaded->get_meta('_bgcouriers_office_id'));
        $this->assertSame('speedy', $reloaded->get_meta('_bgcouriers_courier'));
        $this->assertSame('68134', (string) $reloaded->get_meta('_bgcouriers_site_id'));
        $this->assertSame('live', $reloaded->get_meta('_bgcouriers_quote_source'));
        $this->assertEqualsWithDelta(6.24, (float) $reloaded->get_meta('_bgcouriers_quote_price'), 0.001);
    }

    /**
     * A street chosen off the courier's list reaches the order with the courier's own id and its type,
     * beside the bare name - and a typed one reaches it with neither. Both go through address_fields(),
     * which is what set_selection writes the session from.
     */
    public function test_the_street_reaches_the_order_with_its_id_and_type(): void {
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_speedy']);
        WC()->session->set('bgcouriers_method', 'address');
        WC()->session->set('bgcouriers_site_id', 68134);
        foreach (BGCouriers_Ajax::address_fields(['street_name' => 'ВИТОША', 'street_id' => '1314', 'street_type' => 'ул.', 'street_no' => '10']) as $k => $v) {
            WC()->session->set('bgcouriers_addr_' . $k, $v);
        }
        $order = new WC_Order();
        (new BGCouriers_Checkout())->persist($order);
        $order->save();
        $r = wc_get_order($order->get_id());
        $this->assertSame('ВИТОША', $r->get_meta('_bgcouriers_street_name'), 'the bare name, which every courier reads');
        $this->assertSame('1314', (string) $r->get_meta('_bgcouriers_street_id'));
        $this->assertSame('ул.', $r->get_meta('_bgcouriers_street_type'));
        $this->assertSame('10', $r->get_meta('_bgcouriers_street_no'));
        $this->assertSame('ул. ВИТОША 10', $r->get_shipping_address_1(), 'the WC address line carries the type - "ВИТОША 10" is either of two streets in Sofia');

        // A typed street: no id, no type - and the previous pick's id must not linger on.
        foreach (BGCouriers_Ajax::address_fields(['street_name' => 'Шипка', 'street_id' => '-5', 'street_no' => '3']) as $k => $v) {
            WC()->session->set('bgcouriers_addr_' . $k, $v);
        }
        $order2 = new WC_Order();
        (new BGCouriers_Checkout())->persist($order2);
        $order2->save();
        $r2 = wc_get_order($order2->get_id());
        $this->assertSame('Шипка', $r2->get_meta('_bgcouriers_street_name'));
        $this->assertSame('0', (string) $r2->get_meta('_bgcouriers_street_id'));
        $this->assertSame('', $r2->get_meta('_bgcouriers_street_type'));
        $this->assertSame('Шипка 3', $r2->get_shipping_address_1(), 'a typed street: the line is what was typed');
    }
}
