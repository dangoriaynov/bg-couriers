<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-packer.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';

if (!class_exists('WC_Tax')) {
    /** Just enough WC_Tax for the display rule: a flat 20% shipping tax, which is the Bulgarian rate. */
    class WC_Tax {
        public static function get_shipping_tax_rates() { return ['x' => ['rate' => 20.0]]; }
        public static function calc_shipping_tax($price, $rates) { return ['x' => round($price * 0.2, 2)]; }
    }
}

/**
 * The map and the checkout must answer with the same price for the same parcel. They cannot do that
 * while they disagree about what the parcel IS: the shipping methods are handed the package WooCommerce
 * built, and the map's price endpoint used to ask the cart for its own total instead. Those differ by
 * exactly the items that are not shipped - a virtual bundle container, a downloadable - so the map could
 * advertise a price the checkout would never charge.
 *
 * @group core
 */
final class OnePriceForOneParcelTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** A gram-priced shop, and a cart whose shipping packages hold $weights (in grams). */
    private function shop(array $package_weights, string $tax_display = 'excl'): void {
        Functions\when('wc_get_weight')->alias(static function ($w, $to, $from = '') { return ((float) $w) / 1000; });
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($tax_display) {
            return $n === 'woocommerce_tax_display_cart' ? $tax_display : $d;
        });
        $packages = array_map(static function ($w) { return ['contents_weight' => $w]; }, $package_weights);
        $cart = new class($packages) {
            private array $p;
            public function __construct(array $p) { $this->p = $p; }
            public function get_shipping_packages() { return $this->p; }
        };
        $wc = new class($cart) {
            public $cart;
            public function __construct($c) { $this->cart = $c; }
        };
        Functions\when('WC')->justReturn($wc);
    }

    /** The invariant: whatever the shipping method is priced for, the map is priced for the same thing. */
    public function test_the_map_and_the_checkout_price_the_same_parcel(): void {
        $this->shop([400.0]);
        $package = ['contents_weight' => 400.0];
        $this->assertSame(
            BGCouriers_Pricing::package_parcel($package),
            BGCouriers_Pricing::cart_parcel(),
            'the map endpoint and the shipping method must start from the same parcel'
        );
        $this->assertSame(0.4, BGCouriers_Pricing::cart_parcel()['weight_kg']);
    }

    /** More than one package: the map prices the whole consignment, not one leg of it. */
    public function test_several_packages_are_added_up(): void {
        $this->shop([400.0, 600.0]);
        $this->assertSame(1.0, BGCouriers_Pricing::cart_parcel()['weight_kg']);
    }

    /**
     * What is not shipped is not weighed. The cart's own total would have counted a virtual item; the
     * shipping packages do not carry one, so nothing here can.
     */
    public function test_a_cart_with_nothing_to_ship_falls_to_the_floor(): void {
        $this->shop([]);
        $this->assertSame(0.1, BGCouriers_Pricing::cart_parcel()['weight_kg']);
    }

    /**
     * A basket that HAS something in it and still weighs nothing is not a 0.1 kg parcel - it is a parcel
     * nobody has weighed, because the products carry no weight.
     *
     * The label has always read it that way: with nothing to add up it uses the shop's default weight.
     * The checkout fell to the floor instead, so the customer was quoted for a tenth of a kilogram and
     * the courier was handed the shop's default. Found on dev driving Express One through a real
     * checkout: quoted 2.70 against a waybill declaring 1 kg, which costs 3.38.
     */
    public function test_a_parcel_nobody_weighed_is_priced_as_the_shop_s_default(): void {
        require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';
        Functions\when('wc_get_weight')->alias(static function ($w, $to, $from = '') { return ((float) $w) / 1000; });
        Functions\when('get_option')->alias(static fn($n, $d = false) => $n === 'bgcouriers_default_weight_kg' ? 1.0 : $d);

        $this->assertSame(1.0, BGCouriers_Pricing::package_parcel(
            ['contents_weight' => 0, 'contents' => [['x' => 1]]])['weight_kg'],
            'the same parcel the label will declare');
        $this->assertSame(0.1, BGCouriers_Pricing::package_parcel(
            ['contents_weight' => 0, 'contents' => []])['weight_kg'],
            'nothing to ship is not a parcel to price');
        $this->assertSame(0.1, BGCouriers_Pricing::package_parcel(
            ['contents_weight' => 20, 'contents' => [['x' => 1]]])['weight_kg'],
            'two 10 g items HAVE been weighed - the floor is the honest answer there');
    }

    public function test_a_price_printed_beside_a_tax_inclusive_row_carries_the_tax(): void {
        $this->shop([400.0], 'incl');
        $this->assertSame(6.07, BGCouriers_Pricing::display_price(5.06));
    }

    public function test_a_shop_showing_prices_without_tax_prints_the_net_figure(): void {
        $this->shop([400.0], 'excl');
        $this->assertSame(5.06, BGCouriers_Pricing::display_price(5.06));
    }

    public function test_a_free_delivery_stays_free_either_way(): void {
        $this->shop([400.0], 'incl');
        $this->assertSame(0.0, BGCouriers_Pricing::display_price(0.0));
    }

    /**
     * The tests above hold the shared helpers. This one holds the CALL SITES, which is where the two
     * paths drifted apart in the first place: each had written out its own way of getting at the cart's
     * weight. Only BGCouriers_Pricing may read a weight off a package or a cart - everything else asks
     * it. Same for the tax-on-display sum, which four shipping methods each kept their own copy of.
     */
    public function test_only_the_pricing_class_decides_what_a_parcel_weighs(): void {
        $root  = dirname(__DIR__, 2) . '/includes';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $bad   = [];
        foreach ($files as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') { continue; }
            $path = $f->getPathname();
            if (strpos($path, '/lib/') !== false || basename($path) === 'class-bgcouriers-pricing.php') { continue; }
            $src = (string) file_get_contents($path);
            foreach (['contents_weight', 'get_cart_contents_weight', 'calc_shipping_tax'] as $needle) {
                if (strpos($src, $needle) !== false) { $bad[] = basename($path) . ' uses ' . $needle; }
            }
        }
        $this->assertSame([], $bad, 'ask BGCouriers_Pricing instead - one parcel, one price, one place');
    }
}
