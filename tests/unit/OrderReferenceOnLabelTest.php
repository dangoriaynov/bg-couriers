<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * Every shipment must say which ORDER it is.
 *
 * The merchant's daily job is the reverse of ours: they are looking at a list of parcels in the
 * courier's own panel and need to know which order each one belongs to. A courier that is told nothing
 * fills the field with its own waybill number - the one number the merchant already has, and the one
 * that finds nothing in the shop.
 *
 * Econt was that courier. `orderNumber` is in its OpenAPI spec beside `shipmentDescription`
 * (ee.econt.com/services/openapi.yaml -> ShippingLabel.orderNumber) and we simply never sent it, while
 * Speedy carried `ref1`, BOX NOW `orderNumber` and Pigeon `external_reference` from the start.
 *
 * `get_order_number()`, never `get_id()`: they are equal on a plain shop and differ the moment a shop
 * numbers its orders through a plugin - and the number the merchant can search for is the one they are
 * shown. Sameday was the one reading the post id.
 *
 * Speedy's builder is a private instance method and BOX NOW's needs a live HTTP context, so neither is
 * reachable from here; both are covered where they are built.
 *
 * **A courier added later belongs in this file.** The rule it has to satisfy, and the format to send it
 * in, is written down under "the shipment says which ORDER it is" in `docs/courier-api-notes.md`.
 *
 * @group core
 */
final class OrderReferenceOnLabelTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('wc_get_weight')->alias(static fn($v, $u) => $v);
        Functions\when('get_option')->alias(static fn($n, $d = false) => $d);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** An order whose displayed number is deliberately NOT its post id, which is what tells the two apart. */
    private function order(): WC_Order {
        $o = new WC_Order();
        $o->id     = 371;
        $o->number = 'DOB-1042';
        $o->total  = 28.48;
        $o->items  = [new WC_Order_Item_Stub(1, 2, 24.00)];
        $o->meta   = ['_bgcouriers_method' => 'office', '_bgcouriers_site_id' => 41];
        return $o;
    }

    private function sender(): array {
        return ['client' => ['name' => 'ЗЕЛЕНИ ДОБАВКИ ООД', 'phones' => ['0888123456']],
                'address' => ['city' => ['id' => 41], 'street' => '', 'num' => '73',
                              'quarter' => 'жк Гоце Делчев', 'other' => 'бл.73']];
    }

    /** The bug the owner reported: Econt's panel showed the waybill number, because we sent no order number. */
    public function test_econt_label_carries_the_order_number(): void {
        $label = BGCouriers_Econt::build_label_body($this->order(), $this->sender(), '1097')['label'];

        $this->assertSame('DOB-1042', $label['orderNumber']);
        // The contents description is a different field and must not be crowded out by it.
        $this->assertArrayHasKey('shipmentDescription', $label);
    }

    public function test_pigeon_shipment_carries_the_order_number(): void {
        $body = BGCouriers_Pigeon::build_shipment_body($this->order(), 1001);

        $this->assertSame('DOB-1042', $body['external_reference']);
    }

    /**
     * Sameday's reference has to stay UNIQUE - it keeps the reference of a cancelled AWB forever, so a
     * bare number would refuse the second attempt - but the order number must be readable in it.
     */
    public function test_sameday_reference_is_the_order_number_and_stays_unique(): void {
        $body = BGCouriers_Sameday::build_awb_body($this->order());

        $this->assertStringStartsWith('DOB-1042-', $body['clientInternalReference']);
        $this->assertNotSame('371', explode('-', $body['clientInternalReference'])[0], 'the post id is not what the merchant sees');
    }
}
