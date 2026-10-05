<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/** A product as the measurement sees it: a type and three dimensions. */
final class BGC_Product_Stub {
    public function __construct(
        private string $type = 'simple',
        private float $l = 0.0, private float $w = 0.0, private float $h = 0.0
    ) {}
    public function get_type(): string { return $this->type; }
    public function get_length() { return $this->l; }
    public function get_width()  { return $this->w; }
    public function get_height() { return $this->h; }
}

/** An order line pointing at one of those. */
final class BGC_Item_Stub {
    public function __construct(private $product, private int $qty = 1) {}
    public function get_product() { return $this->product; }
    public function get_quantity() { return $this->qty; }
}

/**
 * Turning an ORDER into units is the half of the measurement that deals with what a shop's catalogue
 * actually looks like: variations that carry the real sizes, products that carry none, and bundle lines
 * that are a heading above the things they hold.
 *
 * @group core
 */
final class ParcelUnitsTest extends TestCase {
    private const DEFAULT_BOX = ['length' => 10, 'width' => 10, 'height' => 2];

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('get_option')->alias(static fn($n, $d = false) => $d);
        Functions\when('wc_get_dimension')->alias(static fn($v, $to, $from) => $v);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function order(array $items): WC_Order {
        $order = new WC_Order();
        $order->items = $items;
        return $order;
    }

    /** A variation's own dimensions are what the line is measured by - that is where this shop keeps them. */
    public function test_a_variation_is_measured_by_its_own_dimensions(): void {
        $order = $this->order([new BGC_Item_Stub(new BGC_Product_Stub('variation', 9, 7, 1.5), 2)]);
        $units = BGCouriers_Settings::order_units($order, self::DEFAULT_BOX);
        $this->assertCount(1, $units);
        $this->assertSame(9.0, $units[0]['l']);
        $this->assertSame(2, $units[0]['qty']);
        $this->assertArrayNotHasKey('measured', $units[0]);
    }

    /** No dimensions anywhere: the line stands in as the configured parcel, and says it is not a measurement. */
    public function test_a_product_without_dimensions_stands_in_as_the_default_parcel(): void {
        $order = $this->order([new BGC_Item_Stub(new BGC_Product_Stub('simple'), 3)]);
        $units = BGCouriers_Settings::order_units($order, self::DEFAULT_BOX);
        $this->assertSame(10.0, $units[0]['l']);
        $this->assertSame(2.0, $units[0]['h']);
        $this->assertFalse($units[0]['measured']);
    }

    /**
     * The bundle line is a heading: order #6300 carries "Комплект: За орхидеи" and then the acid and the
     * paste it holds, each with its own size. Counting the heading too adds a whole default parcel of
     * nothing.
     */
    public function test_a_bundle_heading_is_not_a_parcel(): void {
        $order = $this->order([
            new BGC_Item_Stub(new BGC_Product_Stub('bundle'), 1),
            new BGC_Item_Stub(new BGC_Product_Stub('variation', 10, 8, 4), 1),
            new BGC_Item_Stub(new BGC_Product_Stub('simple', 10, 10, 0.2), 1),
        ]);
        $units = BGCouriers_Settings::order_units($order, self::DEFAULT_BOX);
        $this->assertCount(2, $units, 'the heading is dropped, its contents are kept');
        $this->assertSame(10.0, $units[0]['l']);
        $this->assertSame(0.2, $units[1]['h']);
    }

    /** Every bundle plugin this shop could use names its type differently; all of them are headings. */
    public function test_every_container_type_is_dropped(): void {
        $order = $this->order([
            new BGC_Item_Stub(new BGC_Product_Stub('grouped'), 1),
            new BGC_Item_Stub(new BGC_Product_Stub('woosb'), 1),
            new BGC_Item_Stub(new BGC_Product_Stub('yith_bundle'), 1),
            new BGC_Item_Stub(new BGC_Product_Stub('composite'), 1),
        ]);
        $this->assertSame([], BGCouriers_Settings::order_units($order, self::DEFAULT_BOX));
    }

    /** A bundle the merchant HAS measured is a real parcel - it ships as one box and is counted as one. */
    public function test_a_bundle_with_its_own_size_is_counted(): void {
        $order = $this->order([new BGC_Item_Stub(new BGC_Product_Stub('bundle', 20, 15, 8), 1)]);
        $units = BGCouriers_Settings::order_units($order, self::DEFAULT_BOX);
        $this->assertCount(1, $units);
        $this->assertSame(20.0, $units[0]['l']);
    }

    /** An order of nothing but bundle headings has nothing to measure, and must not pretend otherwise. */
    public function test_headings_alone_measure_nothing(): void {
        $order = $this->order([new BGC_Item_Stub(new BGC_Product_Stub('bundle'), 1)]);
        $units = BGCouriers_Settings::order_units($order, self::DEFAULT_BOX);
        $this->assertSame([], BGCouriers_Settings::box_of_units($units, 1.1));
    }
}
