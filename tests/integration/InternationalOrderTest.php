<?php
/**
 * What an international order carries, from the moment the checkout writes it to the moment the label is
 * built: the destination country survives on the order, and the shipment booked for it is the
 * international service rather than the domestic one.
 *
 * @group speedy
 */
final class InternationalOrderTest extends WP_UnitTestCase {

    private function build(WC_Order $order): array {
        $m = new ReflectionMethod('BGCouriers_Speedy', 'build_shipment_body');
        $m->setAccessible(true);
        return $m->invoke(new BGCouriers_Speedy([]), $order);
    }

    /** An order that has already been checked out to Romania, to an office. */
    private function ro_order(string $phone = '0722123456'): WC_Order {
        $order = wc_create_order();
        $order->set_billing_first_name('Andrei');
        $order->set_billing_last_name('Popescu');
        $order->set_billing_phone($phone);
        $order->set_billing_email('a@example.ro');
        BGCouriers_Checkout::apply_delivery($order, [
            'courier'   => 'speedy',
            'country'   => 'RO',
            'method'    => 'office',
            'office_id' => 926,
        ]);
        $order->save();
        return $order;
    }

    public function test_the_chosen_country_is_written_onto_the_order(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = $this->ro_order();
        $this->assertSame('RO', $order->get_meta('_bgcouriers_country'));
        $this->assertSame('RO', $order->get_shipping_country());
        $this->assertSame('RO', $order->get_billing_country());
        $this->assertSame('RO', BGCouriers_Settings::order_country($order));
    }

    /**
     * The admin order editor posts a delivery with no country field in it at all. Defaulting to the shop's
     * own country there would move a Romanian order back to Bulgaria the first time anyone opened it and
     * pressed save - and with it the service its label is booked under.
     */
    public function test_saving_the_editor_without_a_country_leaves_the_order_where_it_was(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = $this->ro_order();
        BGCouriers_Checkout::apply_delivery($order, [
            'courier'   => 'speedy',
            'method'    => 'office',
            'office_id' => 926,
        ]);
        $order->save();
        $this->assertSame('RO', $order->get_meta('_bgcouriers_country'));
        $this->assertSame('RO', $order->get_shipping_country());
    }

    /** Orders made before any of this existed carry no country meta; the address still says where they went. */
    public function test_an_order_without_the_meta_falls_back_to_its_own_address(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = wc_create_order();
        $order->set_shipping_country('BG');
        $order->save();
        $this->assertSame('BG', BGCouriers_Settings::order_country($order));
    }

    public function test_a_romanian_order_is_booked_on_the_international_service(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        update_option('bgcouriers_open_before_pay', 'open');   // forbidden abroad - must not be sent
        update_option('bgcouriers_speedy_return_voucher', 'yes');
        $body = $this->build($this->ro_order());
        delete_option('bgcouriers_open_before_pay');
        delete_option('bgcouriers_speedy_return_voucher');

        $this->assertSame(202, $body['service']['serviceId']);
        // 202 refuses a recipient payer outright: payment.csPayment.payerRole...payer-not-allowed-for-service
        $this->assertSame('SENDER', $body['payment']['courierServicePayer']);
        $add = $body['service']['additionalServices'] ?? [];
        $this->assertArrayNotHasKey('obpd', $add);
        $this->assertArrayNotHasKey('returns', $add);
        $this->assertSame(926, $body['recipient']['pickupOfficeId']);
        // A bare 07xx says nothing about which country it rings.
        $this->assertSame('+40722123456', $body['recipient']['phone1']['number']);
    }

    /**
     * The money is still collected abroad - as CASH.
     *
     * ППП is a Bulgarian postal money transfer and Speedy refuses it for a foreign address, so a label
     * asking for one is not created at all. The merchant's own pay-out contract still stands at home.
     */
    public function test_cash_on_delivery_abroad_is_collected_as_cash(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        update_option('bgcouriers_speedy_ppp_payout', 'yes');
        $order = $this->ro_order();
        $order->set_payment_method('cod');
        $order->set_total(60.0);
        $order->save();
        $body = $this->build($order);
        delete_option('bgcouriers_speedy_ppp_payout');
        $cod = $body['service']['additionalServices']['cod'] ?? [];
        $this->assertNotEmpty($cod, 'the collection is still asked for');
        $this->assertSame('CASH', $cod['processingType']);
    }

    /**
     * And with it the payment method itself: a shop whose cash-on-delivery is legal only BECAUSE the
     * courier does the ППП has no such arrangement abroad, so an international order there is a prepaid
     * one. Offering COD anyway would take money the shop cannot lawfully receipt.
     */
    public function test_cash_on_delivery_is_not_offered_abroad_to_a_shop_that_relies_on_ppp(): void {
        if (!function_exists('WC') || !class_exists('WC_Session_Handler')) { $this->markTestSkipped('WC not loaded'); }
        WC()->session = WC()->session ?: new WC_Session_Handler();
        WC()->session->set('chosen_shipping_methods', ['bgcouriers_speedy:1']);
        update_option('bgcouriers_cod_fiscalization', 'ppp');
        update_option('bgcouriers_speedy_ppp_payout', 'yes');
        $checkout = new BGCouriers_Checkout();
        $gateways = ['cod' => new WC_Gateway_COD(), 'bacs' => new WC_Gateway_BACS()];

        WC()->session->set('bgcouriers_country', 'BG');
        $this->assertArrayHasKey('cod', $checkout->ppp_filter_gateways($gateways), 'at home nothing changes');

        WC()->session->set('bgcouriers_country', 'RO');
        $abroad = $checkout->ppp_filter_gateways($gateways);
        $this->assertArrayNotHasKey('cod', $abroad, 'no ППП abroad, so no cash on delivery');
        $this->assertArrayHasKey('bacs', $abroad, 'prepaid is exactly what is left');

        // And with NO shipping method chosen at all, which is the state a shop with no prepaid gateway is
        // actually in abroad: every rate for the foreign address is refused, so there is no chosen courier
        // to ask about the ППП. Asking one was the bug - cash on delivery stayed on screen underneath a
        // message saying the order could only be prepaid. The destination alone decides.
        WC()->session->set('chosen_shipping_methods', []);
        $this->assertArrayNotHasKey('cod', $checkout->ppp_filter_gateways($gateways),
            'nothing was chosen because nothing was on offer - that is not a reason to allow COD abroad');
        WC()->session->set('bgcouriers_country', 'BG');
        $this->assertArrayHasKey('cod', $checkout->ppp_filter_gateways($gateways),
            'at home with no courier chosen the shop\'s own arrangement stands');

        // Cleared, not set back to Bulgaria: an empty session country is the state every other test here
        // starts from, and leaving a value behind makes destination_country() answer for them too.
        WC()->session->set('bgcouriers_country', '');
        delete_option('bgcouriers_cod_fiscalization');
        delete_option('bgcouriers_speedy_ppp_payout');
    }

    /**
     * And the merchant hears it where the countries are chosen, not from a customer's empty checkout.
     *
     * The field already explains that an international order can only be a prepaid one. This is the same
     * fact once it has stopped being advice: with no prepaid method enabled, the countries picked here
     * are picked for nothing - no rate is offered for them at all.
     */
    public function test_the_country_field_warns_when_the_shop_has_nothing_to_prepay_with(): void {
        if (!class_exists('BGCouriers_WC_Settings')) { $this->markTestSkipped('settings page not loaded'); }
        if (BGCouriers_Settings::has_prepaid_gateway()) {
            $this->markTestSkipped('this test shop has a prepaid gateway enabled, so there is nothing to warn about');
        }
        update_option('bgcouriers_cod_fiscalization', 'ppp');
        update_option('bgcouriers_speedy_ppp_payout', 'yes');
        $desc = new ReflectionMethod('BGCouriers_WC_Settings', 'intl_countries_desc');
        $desc->setAccessible(true);

        update_option('bgcouriers_speedy_intl_countries', []);
        $this->assertStringNotContainsString('no prepaid payment method enabled', $desc->invoke(null, 'speedy', 'Speedy'),
            'a Bulgaria-only shop is not doing anything wrong and must not be told it is');

        update_option('bgcouriers_speedy_intl_countries', ['RO']);
        $this->assertStringContainsString('no prepaid payment method enabled', $desc->invoke(null, 'speedy', 'Speedy'),
            'countries are chosen but nothing can pay for them - that has to be said here');

        delete_option('bgcouriers_speedy_intl_countries');
        delete_option('bgcouriers_cod_fiscalization');
        delete_option('bgcouriers_speedy_ppp_payout');
    }

    public function test_a_domestic_order_is_booked_exactly_as_before(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $order = wc_create_order();
        $order->set_billing_first_name('Иван');
        $order->set_billing_last_name('Петров');
        $order->set_billing_phone('0888123456');
        BGCouriers_Checkout::apply_delivery($order, [
            'courier'   => 'speedy',
            'country'   => 'BG',
            'method'    => 'office',
            'office_id' => 307,
        ]);
        $order->save();

        // The merchant's own who-pays setting still decides, which abroad it cannot: 202 refuses a
        // recipient payer outright, so the two paths have to be told apart rather than shared.
        update_option('bgcouriers_speedy_ship_in_total', 'no');
        $body = $this->build($order);
        delete_option('bgcouriers_speedy_ship_in_total');
        $this->assertSame(505, $body['service']['serviceId']);
        $this->assertSame('RECIPIENT', $body['payment']['courierServicePayer']);
        $this->assertSame('0888123456', $body['recipient']['phone1']['number']); // untouched
        $this->assertSame('BG', $order->get_shipping_country());
    }
}
