<?php
/**
 * The Speedy /shipment payload must carry exactly what the customer entered at checkout -
 * nothing added, nothing dropped. Exercises BGC_Speedy::build_shipment_body against a real order.
 *
 * @group speedy
 */
final class LabelIntegrityTest extends WP_UnitTestCase {
    private function build(WC_Order $order): array {
        $m = new ReflectionMethod('BGC_Speedy', 'build_shipment_body');
        $m->setAccessible(true);
        return $m->invoke(new BGC_Speedy([]), $order);
    }

    public function test_address_payload_is_exactly_the_entered_fields(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = wc_create_order();
        $order->set_billing_first_name('Иван');
        $order->set_billing_last_name('Петров');
        $order->set_billing_phone('0888123456');
        $order->set_billing_email('i@example.bg');
        $order->set_shipping_first_name('Иван');
        $order->set_shipping_last_name('Петров');
        $order->update_meta_data('_bgc_courier', 'speedy');
        $order->update_meta_data('_bgc_method', 'address');
        $order->update_meta_data('_bgc_site_id', 68134);
        $order->update_meta_data('_bgc_street_name', 'Витоша');
        $order->update_meta_data('_bgc_street_no', '5');
        $order->update_meta_data('_bgc_complex', 'Лозенец');
        // block/entrance/floor/apartment/note intentionally left empty → must NOT be sent.
        $order->save();

        $addr = $this->build($order)['recipient']['address'];
        // Exactly the entered keys, in build_address() order - no empties added, none missing.
        $this->assertSame(['countryId', 'siteId', 'complexName', 'streetName', 'streetNo'], array_keys($addr));
        $this->assertSame(100, $addr['countryId']);
        $this->assertSame(68134, $addr['siteId']);
        $this->assertSame('Витоша', $addr['streetName']);
        $this->assertSame('5', $addr['streetNo']);
        $this->assertSame('Лозенец', $addr['complexName']);
    }

    public function test_office_payload_uses_pickup_office_not_address(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = wc_create_order();
        $order->set_billing_first_name('Мария');
        $order->set_billing_last_name('Иванова');
        $order->set_billing_phone('0888000000');
        $order->set_billing_email('m@example.bg');
        $order->set_shipping_first_name('Мария');
        $order->set_shipping_last_name('Иванова');
        $order->update_meta_data('_bgc_courier', 'speedy');
        $order->update_meta_data('_bgc_method', 'office');
        $order->update_meta_data('_bgc_office_id', 307);
        $order->save();

        $recipient = $this->build($order)['recipient'];
        $this->assertSame(307, $recipient['pickupOfficeId']);
        $this->assertArrayNotHasKey('address', $recipient); // office never sends a street address
        $this->assertSame('Мария Иванова', $recipient['clientName']);
        $this->assertSame('0888000000', $recipient['phone1']['number']);
    }
}
