<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * Who pays Econt's fee decides how much cash the courier collects, so the two must move together. If the
 * recipient pays the delivery at the door, the cash on delivery has to drop to the goods alone - otherwise
 * the customer is charged the delivery twice, once in the collected amount and again by the courier.
 *
 * Econt also REJECTS the label unless the опис (packing list) totals exactly the collected amount, so the
 * balancing line has to follow the same number rather than the order total.
 *
 * @group core
 */
final class EcontPayerCodTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('wc_get_weight')->alias(static fn($v, $u) => $v);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $opts */
    private function options(array $opts): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($opts) {
            return array_key_exists($n, $opts) ? $opts[$n] : $d;
        });
    }

    /** Goods 24.00 + delivery 4.48 = 28.48 collected when the merchant charged shipping at checkout. */
    private function order(float $total, float $shipping): WC_Order {
        $o = new WC_Order();
        $o->total          = $total;
        $o->shipping_total = $shipping;
        $o->payment_method = 'cod';
        $o->items          = [new WC_Order_Item_Stub(1, 2, 24.00)];
        $o->meta           = ['_bgcouriers_method' => 'office'];
        return $o;
    }

    private function sender(): array {
        return ['client' => ['name' => 'ЗЕЛЕНИ ДОБАВКИ ООД', 'phones' => ['0888123456']],
                'address' => ['city' => ['id' => 41], 'street' => '', 'num' => '73',
                              'quarter' => 'жк Гоце Делчев', 'other' => 'бл.73']];
    }

    /** The way it has always worked: delivery charged with the order, so the courier collects it all. */
    public function test_merchant_pays_collects_goods_and_delivery(): void {
        $this->options(['bgcouriers_econt_cod_enabled' => 'yes', 'bgcouriers_econt_ship_in_total' => 'yes']);
        $label = BGCouriers_Econt::build_label_body($this->order(28.48, 4.48), $this->sender(), '1097')['label'];

        $this->assertArrayNotHasKey('paymentReceiverMethod', $label, 'the merchant is billed by default');
        $this->assertSame(28.48, $label['services']['cdAmount']);
        $this->assertSame(28.48, $this->packing_total($label), 'опис must equal the collected amount');
    }

    /** Recipient pays at the door: only the goods may be collected, never the delivery as well. */
    public function test_recipient_pays_collects_goods_only(): void {
        $this->options(['bgcouriers_econt_cod_enabled' => 'yes', 'bgcouriers_econt_ship_in_total' => 'no']);
        // Nothing was charged for shipping at checkout, so the order total IS the goods.
        $label = BGCouriers_Econt::build_label_body($this->order(24.00, 0.0), $this->sender(), '1097')['label'];

        $this->assertSame('cash', $label['paymentReceiverMethod']);
        $this->assertTrue($label['paymentReceiverAmountIsPercent']);
        $this->assertSame(100, $label['paymentReceiverAmount'], 'the whole fee, not a share of it');
        $this->assertSame(24.00, $label['services']['cdAmount']);
        $this->assertSame(24.00, $this->packing_total($label));
    }

    /**
     * The dangerous case: recipient pays, yet a shipping line still sits on the order (an edited order, or
     * the setting flipped after checkout). The collected amount must still exclude it.
     */
    public function test_recipient_pays_never_collects_a_stray_shipping_line(): void {
        $this->options(['bgcouriers_econt_cod_enabled' => 'yes', 'bgcouriers_econt_ship_in_total' => 'no']);
        $label = BGCouriers_Econt::build_label_body($this->order(28.48, 4.48), $this->sender(), '1097')['label'];

        $this->assertSame(24.00, $label['services']['cdAmount'], 'delivery must not be collected too');
        $this->assertSame(24.00, $this->packing_total($label));
    }

    /**
     * A prepaid order is never charged again on delivery - but it still gets an опис. The list describes
     * what is IN the parcel, which has nothing to do with how it was paid for; it used to be built inside
     * the cash-on-delivery block, so prepaid shipments went out with no itemised contents at all.
     */
    public function test_prepaid_order_has_no_cash_on_delivery_but_still_lists_its_contents(): void {
        $this->options(['bgcouriers_econt_cod_enabled' => 'yes', 'bgcouriers_econt_ship_in_total' => 'no']);
        $o = $this->order(24.00, 0.0);
        $o->payment_method = 'bacs';
        $label = BGCouriers_Econt::build_label_body($o, $this->sender(), '1097')['label'];

        $this->assertArrayNotHasKey('cdAmount', $label['services'] ?? []);
        $this->assertSame('cash', $label['paymentReceiverMethod'], 'who pays the courier is independent of COD');
        $this->assertCount(1, $label['packingList'], 'the goods, and nothing to balance against');
        $this->assertSame(24.00, $this->packing_total($label));
        foreach ($label['packingList'] as $line) {
            $this->assertNotSame('S', $line['inventoryNum'], 'no balancing line when nothing is collected');
        }
    }

    /** Econt sums the опис as price x count - that is the number it compares against cdAmount. */
    private function packing_total(array $label): float {
        $sum = 0.0;
        foreach ($label['packingList'] ?? [] as $line) { $sum += $line['price'] * $line['count']; }
        return round($sum, 2);
    }
}
