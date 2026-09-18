<?php
/**
 * The Speedy /shipment payload must carry exactly what the customer entered at checkout -
 * nothing added, nothing dropped. Exercises BGCouriers_Speedy::build_shipment_body against a real order.
 *
 * @group speedy
 */
final class LabelIntegrityTest extends WP_UnitTestCase {
    /** How many times the body builder went to Speedy's street list, and what it was told. */
    private int $lookups = 0;
    private array $streets = [];

    protected function setUp(): void {
        parent::setUp();
        $this->lookups = 0;
        // An address label may ask Speedy which street of that name is meant (resolve_street). The test
        // answers from $this->streets instead of the live API - which has no credentials here anyway.
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (strpos($url, '/location/street') === false) { return $pre; }
            $this->lookups++;
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['streets' => $this->streets])];
        }, 10, 3);
    }

    private function build(WC_Order $order): array {
        $m = new ReflectionMethod('BGCouriers_Speedy', 'build_shipment_body');
        $m->setAccessible(true);
        return $m->invoke(new BGCouriers_Speedy([]), $order);
    }

    private function address_order(array $meta): WC_Order {
        $order = wc_create_order();
        $order->set_billing_first_name('Иван');
        $order->set_billing_last_name('Петров');
        $order->set_billing_phone('0888123456');
        $order->set_billing_email('i@example.bg');
        $order->set_shipping_first_name('Иван');
        $order->set_shipping_last_name('Петров');
        $order->update_meta_data('_bgcouriers_courier', 'speedy');
        $order->update_meta_data('_bgcouriers_method', 'address');
        $order->update_meta_data('_bgcouriers_site_id', 68134);
        foreach ($meta as $k => $v) { $order->update_meta_data('_bgcouriers_' . $k, $v); }
        $order->save();
        return $order;
    }

    /** The checkout recorded which street: its id goes, and nobody is asked. */
    public function test_a_street_chosen_at_checkout_goes_by_its_id_with_no_lookup(): void {
        $order = $this->address_order(['street_name' => 'ВИТОША', 'street_id' => 1314, 'street_type' => 'ул.', 'street_no' => '10']);
        $addr = $this->build($order)['recipient']['address'];
        $this->assertSame(['countryId', 'siteId', 'streetNo', 'streetId'], array_keys($addr));
        $this->assertSame(1314, $addr['streetId']);
        $this->assertSame(0, $this->lookups);
    }

    /** An older order names the street only; one street of that name on the list settles it. */
    public function test_a_name_alone_on_the_list_is_looked_up_once(): void {
        $this->streets = [['id' => 2443, 'siteId' => 68134, 'type' => 'ул.', 'name' => 'ОБОРИЩЕ']];
        $order = $this->address_order(['street_name' => 'Оборище', 'street_no' => '5']);
        $addr = $this->build($order)['recipient']['address'];
        $this->assertSame(2443, $addr['streetId']);
        $this->assertArrayNotHasKey('streetName', $addr);
        $this->assertSame(1, $this->lookups);
    }

    /** Two of that name and nothing to choose by: refused here, both spelled out, before Speedy is asked. */
    public function test_two_streets_of_that_name_refuse_the_label_with_both_named(): void {
        $this->streets = [
            ['id' => 26,   'siteId' => 68134, 'type' => 'бул.', 'name' => 'ВИТОША'],
            ['id' => 1314, 'siteId' => 68134, 'type' => 'ул.',  'name' => 'ВИТОША'],
        ];
        $order = $this->address_order(['street_name' => 'ВИТОША', 'street_no' => '10']);
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->expectExceptionMessage('бул. ВИТОША, ул. ВИТОША');
        $this->build($order);
    }

    public function test_address_payload_is_exactly_the_entered_fields(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $this->streets = []; // a street Speedy does not list: goes by name, exactly as entered
        $order = wc_create_order();
        $order->set_billing_first_name('Иван');
        $order->set_billing_last_name('Петров');
        $order->set_billing_phone('0888123456');
        $order->set_billing_email('i@example.bg');
        $order->set_shipping_first_name('Иван');
        $order->set_shipping_last_name('Петров');
        $order->update_meta_data('_bgcouriers_courier', 'speedy');
        $order->update_meta_data('_bgcouriers_method', 'address');
        $order->update_meta_data('_bgcouriers_site_id', 68134);
        $order->update_meta_data('_bgcouriers_street_name', 'Витоша');
        $order->update_meta_data('_bgcouriers_street_no', '5');
        $order->update_meta_data('_bgcouriers_complex', 'Лозенец');
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
        $order->update_meta_data('_bgcouriers_courier', 'speedy');
        $order->update_meta_data('_bgcouriers_method', 'office');
        $order->update_meta_data('_bgcouriers_office_id', 307);
        $order->save();

        $recipient = $this->build($order)['recipient'];
        $this->assertSame(307, $recipient['pickupOfficeId']);
        $this->assertArrayNotHasKey('address', $recipient); // office never sends a street address
        $this->assertSame('Мария Иванова', $recipient['clientName']);
        $this->assertSame('0888000000', $recipient['phone1']['number']);
    }

    /**
     * Where the parcel STARTS is the shop's setting, not Speedy's guess. With a drop-off office chosen
     * the shipment's sender names it (sender.dropoffOfficeId - the schema's spelling), and nothing else
     * about the sender changes; without one there is no sender office and Speedy books a pickup from
     * the account's own address, as it always did.
     */
    public function test_the_shipment_starts_at_the_drop_off_office_when_one_is_chosen(): void {
        $order = $this->address_order(['street_name' => 'ВИТОША', 'street_id' => 1314, 'street_type' => 'ул.', 'street_no' => '10']);

        delete_option('bgcouriers_speedy_dropoff_office');
        $this->assertArrayNotHasKey('dropoffOfficeId', $this->build($order)['sender'] ?? []);

        update_option('bgcouriers_speedy_dropoff_office', '307');
        $sender = $this->build($order)['sender'];
        $this->assertSame(307, $sender['dropoffOfficeId']);
        $this->assertArrayNotHasKey('clientId', $sender); // the account's own client, as before
        delete_option('bgcouriers_speedy_dropoff_office');
    }
}
