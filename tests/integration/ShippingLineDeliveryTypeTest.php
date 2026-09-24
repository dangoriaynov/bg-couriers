<?php
/**
 * The delivery type on the order's own shipping line.
 *
 * The WooCommerce app shows a shipping method and an address and nothing else about the delivery, and
 * for an office order the address IS the office's - a name and a street that read exactly like somebody's
 * home with a company line above it. There was no way to tell an office order from a locker one or from
 * a delivery to the customer's door (owner, with a screenshot, 2026-09-24).
 *
 * @group core
 */
final class ShippingLineDeliveryTypeTest extends WP_UnitTestCase {

    private function order_with_rate(string $method_id, string $name): WC_Order {
        $order = wc_create_order();
        $item  = new WC_Order_Item_Shipping();
        $item->set_method_id($method_id);
        $item->set_method_title($name);
        $item->set_total(0);
        $order->add_item($item);
        $order->save();
        return $order;
    }

    private function shipping_name(WC_Order $order): string {
        foreach ($order->get_items('shipping') as $item) { return $item->get_name(); }
        return '';
    }

    public function test_an_office_order_says_so_on_its_shipping_line(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = $this->order_with_rate('bgcouriers_speedy', 'Speedy');
        BGCouriers_Checkout::apply_delivery($order, ['courier' => 'speedy', 'method' => 'office', 'office_id' => 2]);
        $this->assertSame('Speedy: To office', $this->shipping_name($order));
    }

    /**
     * An order edited in the admin runs apply_delivery() again, on a line that already carries a type.
     * Appending would read "Speedy: To office: To office", once per save; the name is rebuilt from the
     * base kept in the line's own meta instead.
     */
    public function test_re_saving_does_not_stack_the_type(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = $this->order_with_rate('bgcouriers_speedy', 'Speedy');
        BGCouriers_Checkout::apply_delivery($order, ['courier' => 'speedy', 'method' => 'office', 'office_id' => 2]);
        BGCouriers_Checkout::apply_delivery($order, ['courier' => 'speedy', 'method' => 'office', 'office_id' => 2]);
        $this->assertSame('Speedy: To office', $this->shipping_name($order));

        // Changed to a delivery at the customer's address: the line says the new type, and only it.
        BGCouriers_Checkout::apply_delivery($order, ['courier' => 'speedy', 'method' => 'address', 'site_id' => 68134]);
        $this->assertSame('Speedy: To address', $this->shipping_name($order));
    }

    /** Somebody else's shipping method on the same order is not ours to rename. */
    public function test_another_plugins_shipping_line_is_left_alone(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = $this->order_with_rate('flat_rate', 'Flat rate');
        BGCouriers_Checkout::apply_delivery($order, ['courier' => 'speedy', 'method' => 'office', 'office_id' => 2]);
        $this->assertSame('Flat rate', $this->shipping_name($order));
    }
}
