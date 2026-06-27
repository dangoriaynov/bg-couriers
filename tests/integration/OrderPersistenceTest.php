<?php
/**
 * @group speedy
 */
final class OrderPersistenceTest extends WP_UnitTestCase {
    public function test_persist_writes_meta_via_crud(): void {
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgc_speedy']); // so chosen_is_speedy() passes
        WC()->session->set('bgc_method', 'office');
        WC()->session->set('bgc_site_id', 68134);
        WC()->session->set('bgc_office_id', 307);
        WC()->session->set('bgc_quote_price', 6.24);
        WC()->session->set('bgc_quote_source', 'live');
        $order = new WC_Order();
        (new BGC_Checkout())->persist($order);
        $order->save();
        $reloaded = wc_get_order($order->get_id());
        $this->assertSame('office', $reloaded->get_meta('_bgc_method'));
        $this->assertSame('307', (string) $reloaded->get_meta('_bgc_office_id'));
        $this->assertSame('speedy', $reloaded->get_meta('_bgc_courier'));
        $this->assertSame('68134', (string) $reloaded->get_meta('_bgc_site_id'));
        $this->assertSame('live', $reloaded->get_meta('_bgc_quote_source'));
        $this->assertEqualsWithDelta(6.24, (float) $reloaded->get_meta('_bgc_quote_price'), 0.001);
    }
}
