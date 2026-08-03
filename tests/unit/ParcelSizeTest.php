<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * The parcel size is not cosmetic: it decides which locker compartment is reserved and charged, and for
 * Econt it feeds the volumetric weight and the sizeUnder60cm price band. A default that is one size too
 * big is paid for on every order that has no dimensions of its own - which here is most of them.
 *
 * @group core
 */
final class ParcelSizeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('wc_get_weight')->alias(static fn($v, $u) => $v);
        Functions\when('wc_get_dimension')->alias(static fn($v, $to, $from) => $v);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,mixed> $opts */
    private function options(array $opts = []): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($opts) {
            return array_key_exists($n, $opts) ? $opts[$n] : $d;
        });
    }

    /** The shipped default is the flat box this shop actually posts. */
    public function test_default_parcel_is_ten_by_ten_by_two(): void {
        $this->options();
        $this->assertSame(['length' => 10, 'width' => 10, 'height' => 2], BGCouriers_Settings::box_dims());
    }

    /** A stored value always wins over the default, in every dimension. */
    public function test_stored_dimensions_win(): void {
        $this->options(['bgcouriers_box_length' => 30, 'bgcouriers_box_width' => 20, 'bgcouriers_box_height' => 15]);
        $this->assertSame(['length' => 30, 'width' => 20, 'height' => 15], BGCouriers_Settings::box_dims());
    }

    /** Econt used to be told nothing at all, so it priced on assumption. */
    public function test_econt_is_told_the_parcel_size(): void {
        $this->options(['bgcouriers_econt_cod_enabled' => 'no']);
        $order = new WC_Order();
        $order->items = [new WC_Order_Item_Stub(1, 1, 10.0)];
        $order->meta  = ['_bgcouriers_method' => 'office'];
        $sender = ['client' => ['name' => 'X', 'phones' => ['0888']],
                   'address' => ['city' => ['id' => 41], 'street' => '', 'num' => '1', 'quarter' => 'q', 'other' => '']];
        $label = BGCouriers_Econt::build_label_body($order, $sender, '1097')['label'];

        $this->assertSame(10.0, $label['shipmentDimensionsL']);
        $this->assertSame(10.0, $label['shipmentDimensionsW']);
        $this->assertSame(2.0, $label['shipmentDimensionsH']);
        $this->assertTrue($label['sizeUnder60cm'], 'a 10x10x2 box is well under the 60cm band');
    }

    /** A genuinely large parcel must not claim the cheaper band. */
    public function test_econt_reports_a_large_parcel_honestly(): void {
        $this->options(['bgcouriers_box_length' => 80, 'bgcouriers_box_width' => 10, 'bgcouriers_box_height' => 10]);
        $order = new WC_Order();
        $order->items = [new WC_Order_Item_Stub(1, 1, 10.0)];
        $order->meta  = ['_bgcouriers_method' => 'office'];
        $sender = ['client' => ['name' => 'X', 'phones' => ['0888']],
                   'address' => ['city' => ['id' => 41], 'street' => '', 'num' => '1', 'quarter' => 'q', 'other' => '']];
        $label = BGCouriers_Econt::build_label_body($order, $sender, '1097')['label'];
        $this->assertFalse($label['sizeUnder60cm']);
    }
}
