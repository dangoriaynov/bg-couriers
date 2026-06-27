<?php
/**
 * The Econt createLabel payload must carry exactly what the order data dictates —
 * correct receiver (office code vs address), senderAgent for juridical senders,
 * and receiverClient from the order.
 *
 * @group econt
 */
final class EcontLabelIntegrityTest extends WP_UnitTestCase {
    /** Invoke build_label_body via reflection (it is static). */
    private function build(WC_Order $order, array $sender, string $office_code): array {
        $m = new ReflectionMethod('BGC_Econt', 'build_label_body');
        $m->setAccessible(true);
        return $m->invoke(null, $order, $sender, $office_code);
    }

    /** Juridical sender + office delivery. */
    public function test_office_payload_has_office_code_and_sender_agent(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }

        $order = wc_create_order();
        $order->set_billing_first_name('Мария');
        $order->set_billing_last_name('Иванова');
        $order->set_billing_phone('0888000001');
        $order->set_billing_email('m@example.bg');
        $order->update_meta_data('_bgc_courier', 'econt');
        $order->update_meta_data('_bgc_method', 'office');
        $order->update_meta_data('_bgc_office_id', 100);
        $order->save();

        $sender = [
            'client'  => ['name' => 'ЗЕЛЕНИ ООД', 'phones' => ['0700123456'], 'juridicalEntity' => true, 'molName' => 'Иван'],
            'address' => ['city' => ['id' => 41], 'street' => 'бул. Витоша', 'num' => '1', 'other' => ''],
        ];

        $body = $this->build($order, $sender, '1009');

        $this->assertSame('create', $body['mode']);
        $this->assertSame('1009', $body['label']['receiverOfficeCode']);
        $this->assertArrayNotHasKey('receiverAddress', $body['label']);
        // receiverClient from order
        $this->assertSame('Мария Иванова', $body['label']['receiverClient']['name']);
        $this->assertSame('0888000001', $body['label']['receiverClient']['phones'][0]);
        // senderAgent must be present for juridical entity
        $this->assertArrayHasKey('senderAgent', $body['label']);
        $this->assertSame('Иван', $body['label']['senderAgent']['name']);
    }

    /** Natural-person sender + address delivery. */
    public function test_address_payload_has_receiver_address_and_no_sender_agent(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }

        $order = wc_create_order();
        $order->set_billing_first_name('Иван');
        $order->set_billing_last_name('Петров');
        $order->set_billing_phone('0888123456');
        $order->set_billing_email('i@example.bg');
        $order->update_meta_data('_bgc_courier', 'econt');
        $order->update_meta_data('_bgc_method', 'address');
        $order->update_meta_data('_bgc_site_id', 41);
        $order->update_meta_data('_bgc_street_name', 'Витоша');
        $order->update_meta_data('_bgc_street_no', '5');
        $order->save();

        $sender = [
            'client'  => ['name' => 'Георги Стоянов', 'phones' => ['0888999888'], 'juridicalEntity' => false, 'molName' => ''],
            'address' => ['city' => ['id' => 41], 'street' => 'ул. Раковски', 'num' => '10', 'other' => ''],
        ];

        $body = $this->build($order, $sender, '');

        $this->assertSame('create', $body['mode']);
        $this->assertArrayNotHasKey('receiverOfficeCode', $body['label']);
        $this->assertArrayHasKey('receiverAddress', $body['label']);
        $this->assertSame(41, $body['label']['receiverAddress']['city']['id']);
        $this->assertSame('Витоша', $body['label']['receiverAddress']['street']);
        $this->assertSame('5', $body['label']['receiverAddress']['num']);
        // receiverClient from order
        $this->assertSame('Иван Петров', $body['label']['receiverClient']['name']);
        // no senderAgent for natural person
        $this->assertArrayNotHasKey('senderAgent', $body['label']);
    }

    /** Juridical sender with no molName falls back to client name. */
    public function test_sender_agent_falls_back_to_client_name_when_mol_missing(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }

        $order = wc_create_order();
        $order->set_billing_first_name('Тест');
        $order->set_billing_last_name('Потребител');
        $order->set_billing_phone('0700000000');
        $order->set_billing_email('t@example.bg');
        $order->update_meta_data('_bgc_courier', 'econt');
        $order->update_meta_data('_bgc_method', 'office');
        $order->save();

        $sender = [
            'client'  => ['name' => 'КОМПАНИЯ АД', 'phones' => ['0700111222'], 'juridicalEntity' => true, 'molName' => ''],
            'address' => ['city' => ['id' => 41], 'street' => 'ул. Примерна', 'num' => '3', 'other' => ''],
        ];

        $body = $this->build($order, $sender, '1009');

        $this->assertArrayHasKey('senderAgent', $body['label']);
        $this->assertSame('КОМПАНИЯ АД', $body['label']['senderAgent']['name']);
    }
}
