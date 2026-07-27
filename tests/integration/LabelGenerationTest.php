<?php
/**
 * @group speedy
 */
final class LabelGenerationTest extends WP_UnitTestCase {
    public function test_generate_is_idempotent(): void {
        $order = new WC_Order();
        $order->update_meta_data('_bgcouriers_courier', 'speedy');
        $order->update_meta_data('_bgcouriers_method', 'office');
        $order->update_meta_data('_bgcouriers_office_id', 307);
        $order->update_meta_data('_bgcouriers_waybill', '299999990'); // already labelled
        $order->save();
        $label = BGCouriers_Labels::generate($order->get_id());
        $this->assertSame('299999990', $label->waybill); // returns existing, no API call
    }
}
