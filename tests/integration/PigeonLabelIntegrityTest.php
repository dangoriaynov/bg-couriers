<?php
/**
 * The Pigeon build_shipment_body payload must carry exactly what the order data
 * dictates - correct receiver_name/phone/email, delivery type (office/address/locker),
 * pickup office id, and inventory_items from order line items.
 *
 * Mirror of EcontLabelIntegrityTest for Pigeon.
 *
 * @group pigeon
 */
final class PigeonLabelIntegrityTest extends WP_UnitTestCase {
    /** Invoke build_shipment_body via reflection (it is static). */
    private function build(WC_Order $order, int $pickup_office_id): array {
        $m = new ReflectionMethod('BGC_Pigeon', 'build_shipment_body');
        $m->setAccessible(true);
        return $m->invoke(null, $order, $pickup_office_id);
    }

    /** Office delivery: receiver fields + delivery_type + office_id + inventory_items. */
    public function test_office_payload_receiver_and_delivery(): void {
        if (!function_exists('wc_create_order')) {
            $this->markTestSkipped('WC not loaded');
        }

        $order = wc_create_order();
        $order->set_billing_first_name('Мария');
        $order->set_billing_last_name('Иванова');
        $order->set_billing_phone('0888000001');
        $order->set_billing_email('m@example.bg');
        $order->update_meta_data('_bgc_courier', 'pigeon');
        $order->update_meta_data('_bgc_method', 'office');
        $order->update_meta_data('_bgc_office_id', 2001);
        $order->update_meta_data('_bgc_site_id', 68134);
        $order->update_meta_data('_bgc_weight_kg', 1.5);
        $order->save();

        update_option('bgc_send_email', 'yes'); // opt in to forwarding the customer e-mail to the courier
        $body = $this->build($order, 1001);

        // Pickup always office
        $this->assertSame('office', $body['pickup_type']);
        $this->assertSame(1001, $body['pickup_office_id']);

        // Delivery: office
        $this->assertSame('office', $body['delivery_type']);
        $this->assertSame(2001, $body['delivery_office_id']);
        $this->assertArrayNotHasKey('delivery_address', $body);

        // Receiver fields from order billing
        $this->assertSame('Мария Иванова', $body['receiver_name']);
        $this->assertSame('0888000001', $body['receiver_phone']);
        $this->assertSame('m@example.bg', $body['receiver_email']);

        // inventory_items fallback (no order items) → [{description:'Goods',quantity:1}]
        $this->assertArrayHasKey('inventory_items', $body);
        $this->assertCount(1, $body['inventory_items']);
        $this->assertSame('Goods', $body['inventory_items'][0]['description']);
        $this->assertSame(1, $body['inventory_items'][0]['quantity']);
    }

    /** Address delivery: delivery_address block, receiver fields. */
    public function test_address_payload_has_delivery_address(): void {
        if (!function_exists('wc_create_order')) {
            $this->markTestSkipped('WC not loaded');
        }

        $order = wc_create_order();
        $order->set_billing_first_name('Иван');
        $order->set_billing_last_name('Петров');
        $order->set_billing_phone('0888123456');
        $order->set_billing_email('i@example.bg');
        $order->update_meta_data('_bgc_courier', 'pigeon');
        $order->update_meta_data('_bgc_method', 'address');
        $order->update_meta_data('_bgc_site_id', 68134);
        $order->update_meta_data('_bgc_street_name', 'бул. Витоша');
        $order->update_meta_data('_bgc_street_no', '5');
        $order->update_meta_data('_bgc_weight_kg', 2.0);
        $order->save();

        update_option('bgc_send_email', 'yes'); // opt in to forwarding the customer e-mail to the courier
        $body = $this->build($order, 1001);

        $this->assertSame('address', $body['delivery_type']);
        $this->assertArrayNotHasKey('delivery_office_id', $body);
        $this->assertArrayHasKey('delivery_address', $body);
        $this->assertSame(68134, $body['delivery_address']['city_id']);
        // The API takes the street as free text: additional_info = "street_name street_no".
        $this->assertSame('бул. Витоша 5', $body['delivery_address']['additional_info']);

        // Receiver fields from order billing
        $this->assertSame('Иван Петров', $body['receiver_name']);
        $this->assertSame('0888123456', $body['receiver_phone']);
        $this->assertSame('i@example.bg', $body['receiver_email']);
    }

    /** inventory_items come from order line items when present. */
    public function test_inventory_items_from_order_line_items(): void {
        if (!function_exists('wc_create_order')) {
            $this->markTestSkipped('WC not loaded');
        }
        $product = new WC_Product_Simple();
        $product->set_name('Тест Продукт');
        $product->set_regular_price('20.00');
        $product->save();

        $order = wc_create_order();
        $order->set_billing_first_name('Тест');
        $order->set_billing_last_name('Купувач');
        $order->set_billing_phone('0700000001');
        $order->set_billing_email('t@example.bg');
        $order->update_meta_data('_bgc_courier', 'pigeon');
        $order->update_meta_data('_bgc_method', 'office');
        $order->update_meta_data('_bgc_office_id', 2001);
        $order->update_meta_data('_bgc_weight_kg', 1.0);
        $order->add_product($product, 3);
        $order->save();

        $body = $this->build($order, 1001);

        $this->assertArrayHasKey('inventory_items', $body);
        $this->assertCount(1, $body['inventory_items']);
        $this->assertSame('Тест Продукт', $body['inventory_items'][0]['description']);
        $this->assertSame(3, $body['inventory_items'][0]['quantity']);
    }
}
