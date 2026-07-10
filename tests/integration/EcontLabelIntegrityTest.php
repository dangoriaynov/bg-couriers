<?php
/**
 * The Econt createLabel payload must carry exactly what the order data dictates -
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
        // Free-text fallback so Econt accepts the address even when the street is not in its nomenclature.
        $this->assertSame('Витоша 5', $body['label']['receiverAddress']['other']);
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

    /**
     * COD enabled: the label must carry наложен платеж via the postal-money-transfer agreement (ППП),
     * the delivery-fee payer (за чий рахунок = sender, on account), and the itemised packing list
     * (seq / name / weight kg / qty / price). This is the money-critical path.
     */
    public function test_cod_enabled_payload_has_ppp_who_pays_and_packing_list(): void {
        if (!function_exists('wc_create_order') || !class_exists('WC_Product_Simple')) {
            $this->markTestSkipped('WC not loaded');
        }
        update_option('woocommerce_weight_unit', 'kg');
        update_option('bgc_econt_cod_enabled', 'yes');
        update_option('bgc_econt_cd_num', 'CD139879'); // the moneyTransfer agreement (пощенски паричен превод)

        $product = new WC_Product_Simple();
        $product->set_name('Тест продукт');
        $product->set_regular_price('10.00');
        $product->set_weight('0.5');
        $product->set_sku('SKU-1');
        $product->save();

        $order = wc_create_order();
        $order->set_billing_first_name('Мария');
        $order->set_billing_last_name('Иванова');
        $order->set_billing_phone('0888000001');
        $order->set_currency('EUR');
        $order->add_product($product, 2);
        $order->update_meta_data('_bgc_courier', 'econt');
        $order->update_meta_data('_bgc_method', 'office');
        $order->update_meta_data('_bgc_office_id', 100);
        $order->calculate_totals();
        $order->save();

        $sender = [
            'client'  => ['name' => 'ЗЕЛЕНИ ООД', 'phones' => ['0700123456'], 'juridicalEntity' => true, 'molName' => 'Иван'],
            'address' => ['city' => ['id' => 41], 'street' => 'бул. Витоша', 'num' => '1', 'other' => ''],
        ];

        $label = $this->build($order, $sender, '1009')['label'];

        // наложен платеж via the ППП agreement
        $this->assertSame('CD139879', $label['services']['cdPayOptionsTemplate']);
        $this->assertSame('get', $label['services']['cdType']);
        $this->assertSame('EUR', $label['services']['cdCurrency']);
        $this->assertEqualsWithDelta((float) $order->get_total(), (float) $label['services']['cdAmount'], 0.001);
        // за чий рахунок - left to Econt's default (the API client / sender is billed); NOT set
        // explicitly, because paymentSenderMethod='credit' makes Econt demand a payer client number
        // the profile doesn't carry (live-confirmed rejection 2026-07-06).
        $this->assertArrayNotHasKey('paymentSenderMethod', $label);
        // packing list: seq / name / weight (kg) / qty / price
        $this->assertSame('digital', $label['packingListType']);
        $this->assertNotEmpty($label['packingList']);
        $item = $label['packingList'][0];
        $this->assertSame('SKU-1', $item['inventoryNum']);
        $this->assertSame('Тест продукт', $item['description']);
        $this->assertSame(2, $item['count']);
        $this->assertEqualsWithDelta(0.5, (float) $item['weight'], 0.001);   // per unit
        $this->assertEqualsWithDelta(10.0, (float) $item['price'], 0.01);    // per unit (tax-inclusive; no tax in test env)
        // Econt invariant: sum(price × count) must equal the наложен платеж (cdAmount), else it rejects.
        $sum = 0.0; foreach ($label['packingList'] as $pl) { $sum += (float) $pl['price'] * (int) $pl['count']; }
        $this->assertEqualsWithDelta((float) $label['services']['cdAmount'], $sum, 0.01);

        update_option('bgc_econt_cod_enabled', 'no');
        update_option('bgc_econt_cd_num', '');
    }

    /** COD disabled: no наложен платеж / packing list / payment-sender leaks onto the label. */
    public function test_cod_disabled_payload_has_no_cod_fields(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        update_option('bgc_econt_cod_enabled', 'no');

        $order = wc_create_order();
        $order->set_billing_first_name('Иван');
        $order->set_billing_last_name('Петров');
        $order->update_meta_data('_bgc_courier', 'econt');
        $order->update_meta_data('_bgc_method', 'office');
        $order->save();

        $sender = ['client' => ['name' => 'X', 'phones' => ['0700123456']], 'address' => ['city' => ['id' => 41], 'street' => 's', 'num' => '1']];
        $label = $this->build($order, $sender, '1009')['label'];

        $this->assertArrayNotHasKey('services', $label);
        $this->assertArrayNotHasKey('packingList', $label);
        $this->assertArrayNotHasKey('paymentSenderMethod', $label);
    }
}
