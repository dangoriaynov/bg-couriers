<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-phone.php';

/**
 * BOX NOW accepts E.164 and nothing else. A Bulgarian shopper types 0888123456, and BOX NOW answers the
 * whole request with {"code":"P405"} - no field named, no reason given - so every single BOX NOW order
 * would have failed with a code the merchant cannot act on. Confirmed live against the stage API on
 * 2026-08-04: identical body, local number -> 400 P405, the same body with +359... -> 200 and a parcel.
 *
 * @group core
 */
final class PhoneNormalisationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('wc_get_weight')->alias(static fn($v, $u) => $v);
        Functions\when('get_bloginfo')->justReturn('Shop');
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @return array<string,array{0:string,1:string}> */
    public function numbers(): array {
        return [
            'bulgarian mobile as everyone types it' => ['0888123456',      '+359888123456'],
            'with spaces'                           => ['0888 123 456',    '+359888123456'],
            'with dashes'                           => ['0888-123-456',    '+359888123456'],
            'already E.164'                         => ['+359888123456',   '+359888123456'],
            'E.164 with spaces'                     => ['+359 888 123 456', '+359888123456'],
            'double-zero international prefix'      => ['00359888123456',  '+359888123456'],
            'country code but no plus'              => ['359888123456',    '+359888123456'],
            'landline with area code'               => ['029876543',       '+35929876543'],
            'a foreign number keeps its country'    => ['+306912345678',   '+306912345678'],
        ];
    }

    /**
     * @dataProvider numbers
     */
    public function test_numbers_become_e164(string $raw, string $expected): void {
        $this->assertSame($expected, BGCouriers_Phone::e164($raw));
    }

    /** @return array<string,array{0:string}> */
    public function rubbish(): array {
        return [
            'empty'            => [''],
            'spaces only'      => ['   '],
            'no digits'        => ['n/a'],
            'far too short'    => ['123'],
            'all zeroes'       => ['0000000000'],
            'longer than E.164'=> ['+3598881234567890123'],
        ];
    }

    /**
     * @dataProvider rubbish
     */
    public function test_unusable_input_yields_nothing(string $raw): void {
        $this->assertSame('', BGCouriers_Phone::e164($raw), 'better an empty string than a half-converted one');
        $this->assertFalse(BGCouriers_Phone::usable($raw));
    }

    // ---------------------------------------------------------------- BOX NOW request body

    private function order(string $phone): WC_Order {
        $o = new WC_Order();
        $o->total          = 30.00;
        $o->payment_method = 'cod';
        $o->phone          = $phone;
        $o->name           = 'Тест Боксноу';
        $o->items          = [new WC_Order_Item_Stub(1, 1, 30.00)];
        $o->meta           = ['_bgcouriers_office_id' => 5365];
        return $o;
    }

    private function options(string $sender_phone): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($sender_phone) {
            if ($n === 'bgcouriers_boxnow_sender_phone') { return $sender_phone; }
            if ($n === 'admin_email') { return 'shop@example.com'; }
            return $d;
        });
    }

    public function test_both_phones_go_out_in_e164(): void {
        $this->options('0888123456');
        $body = BGCouriers_Boxnow::build_delivery_request($this->order('0888123456'), '5726');

        $this->assertSame('+359888123456', $body['destination']['contactNumber'], 'the recipient - what P405 was about');
        $this->assertSame('+359888123456', $body['origin']['contactNumber']);
    }

    public function test_a_number_already_in_e164_is_left_alone(): void {
        $this->options('+359888123456');
        $body = BGCouriers_Boxnow::build_delivery_request($this->order('+359888123456'), '5726');
        $this->assertSame('+359888123456', $body['destination']['contactNumber']);
        $this->assertSame('+359888123456', $body['origin']['contactNumber']);
    }

    /**
     * An order with no usable phone must fail HERE, naming the problem - not at BOX NOW as a bare code.
     */
    public function test_an_order_without_a_usable_phone_is_refused_with_a_reason(): void {
        $this->options('0888123456');
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->expectExceptionMessageMatches('/recipient/i');
        BGCouriers_Boxnow::build_delivery_request($this->order('n/a'), '5726');
    }

    /**
     * BOX NOW refuses a delivery request whose orderNumber it has seen before - {"code":"P410"}, with
     * the earlier parcel ids echoed back - and cancelling the first shipment does NOT release the
     * number. So a re-issue must present a new one, while the first request keeps the plain order
     * number that the merchant and BOX NOW support will both be looking for.
     */
    public function test_a_reissue_presents_a_new_order_number(): void {
        $this->options('0888123456');
        $order = $this->order('0888123456');

        $first = BGCouriers_Boxnow::build_delivery_request($order, '5726', 1);
        $this->assertSame((string) $order->get_order_number(), $first['orderNumber'], 'the first one stays plain');

        $second = BGCouriers_Boxnow::build_delivery_request($order, '5726', 2);
        $this->assertSame($order->get_order_number() . '-2', $second['orderNumber']);
        $this->assertNotSame($first['orderNumber'], $second['orderNumber']);

        $third = BGCouriers_Boxnow::build_delivery_request($order, '5726', 3);
        $this->assertSame($order->get_order_number() . '-3', $third['orderNumber']);
    }

    /**
     * BOX NOW makes a SEPARATE PARCEL out of every items[] entry, each with its own id, while the plugin
     * records only parcels[0]. A two-line order therefore shipped two parcels of which one was invisible
     * to the shop - never printed, never tracked, and still travelling and being billed after the order
     * was cancelled. Verified on the stage API: order #237, two lines -> 3539518244 AND 7934933088.
     */
    public function test_an_order_ships_as_exactly_one_parcel(): void {
        $this->options('0888123456');
        $o = $this->order('0888123456');
        $o->items = [new WC_Order_Item_Stub(1, 2, 24.00), new WC_Order_Item_Stub(2, 1, 6.00)];

        $body = BGCouriers_Boxnow::build_delivery_request($o, '5726');

        $this->assertCount(1, $body['items'], 'two order lines must NOT become two parcels');
        $this->assertSame('30.00', $body['items'][0]['value'], 'the whole order, not one line of it');
        $this->assertGreaterThanOrEqual(0.1, $body['items'][0]['weight'], 'couriers reject a lighter parcel');
    }

    /** Same for the merchant's own missing number, which is a settings problem and says so. */
    public function test_no_sender_phone_is_refused_with_a_reason(): void {
        $this->options('');
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->expectExceptionMessageMatches('/sender phone/i');
        BGCouriers_Boxnow::build_delivery_request($this->order('0888123456'), '5726');
    }
}
