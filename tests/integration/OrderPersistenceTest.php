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
        WC()->session->set('bgcouriers_quote_price', 6.24);
        WC()->session->set('bgcouriers_quote_source', 'live');
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
}
