<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-packer.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';
require_once dirname(__DIR__) . '/stubs/wc-tax.php';

/**
 * What the shop charges for a delivery has to be what the courier charges the shop for it.
 *
 * Every rate this plugin registers is added with `'taxes' => ''`, which asks WooCommerce to work the
 * shipping tax out and put it on top of a NET cost. That is a sound arrangement right up until the shop
 * calculates no tax - and then WooCommerce adds nothing, while the courier goes on invoicing its VAT.
 * The customer was charged the net price, the shop was billed the gross one, and the difference came
 * out of the shop on every order whose delivery is charged in the order total. Nothing anywhere in the
 * checkout was ever going to cover it.
 *
 * Measured, not reasoned: order 11260 on the live shop was quoted **1.37** for a Sameday easyBox, and
 * Sameday invoiced **1.66** for waybill 1CJALN20743532 - the same figure plus 20%.
 *
 * The rule these tests hold: the rate cost is the NET price where WooCommerce will add the tax, and
 * the price the courier will actually charge where it will not. A quote that carries no tax of its own
 * is handed over untouched either way, because both kinds that do are already right - a figure that
 * includes VAT (Pigeon, Европът, Econt where there are no rates to split it with) is what the courier
 * charges, and a flat price a merchant typed into the settings is a decision, not a courier's quote.
 *
 * @group core
 */
final class RateCostTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void {
        unset($GLOBALS['bgcouriers_test_shop_has_no_tax_rates']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** A shop with tax calculation on and a shipping rate to calculate with. */
    private function shopCalculatesTax(): void {
        Functions\when('wc_tax_enabled')->justReturn(true);
    }

    /** dobavki.club: `woocommerce_calc_taxes` off AND an empty rate table. */
    private function shopCalculatesNoTax(): void {
        Functions\when('wc_tax_enabled')->justReturn(false);
        $GLOBALS['bgcouriers_test_shop_has_no_tax_rates'] = true;
    }

    // ── The rate cost ────────────────────────────────────────────────────────

    public function test_a_shop_that_calculates_tax_is_handed_the_net_cost_as_it_always_was(): void {
        $this->shopCalculatesTax();
        $q = new BGCouriers_Quote(1.37, 0.27, 'EUR', 'live');
        // WooCommerce adds the 0.27 itself. Handing it the gross would charge the tax twice - the 0.3.5
        // fault, and the reason this is a branch rather than a single sum.
        $this->assertSame(1.37, BGCouriers_Pricing::rate_cost($q));
    }

    public function test_a_shop_that_calculates_none_is_charged_what_the_courier_charges(): void {
        $this->shopCalculatesNoTax();
        $q = new BGCouriers_Quote(1.37, 0.27, 'EUR', 'live');
        $this->assertSame(1.64, BGCouriers_Pricing::rate_cost($q),
            'order 11260 was charged 1.37 and Sameday invoiced 1.66');
    }

    public function test_a_shop_with_tax_switched_on_but_no_rate_for_the_address_still_charges_the_courier_s_price(): void {
        // Tax calculation is on, but there is no shipping rate to add: WooCommerce adds nothing here
        // either, so the cost has to carry the courier's own tax.
        Functions\when('wc_tax_enabled')->justReturn(true);
        $GLOBALS['bgcouriers_test_shop_has_no_tax_rates'] = true;
        $this->assertSame(1.64, BGCouriers_Pricing::rate_cost(new BGCouriers_Quote(1.37, 0.27, 'EUR', 'live')));
    }

    public function test_a_price_that_carries_no_tax_of_its_own_is_handed_over_unchanged(): void {
        $this->shopCalculatesNoTax();
        // A merchant's typed flat price, and a courier whose figure already includes its VAT with no
        // rates to split it out with. Neither is a net price waiting for tax, and grossing either up
        // would overcharge the customer by twenty percent.
        $this->assertSame(4.00, BGCouriers_Pricing::rate_cost(new BGCouriers_Quote(4.00, 0.0, 'EUR', 'fixed')));
        $this->assertSame(4.59, BGCouriers_Pricing::rate_cost(new BGCouriers_Quote(4.59, 0.0, 'EUR', 'live')));
    }

    public function test_free_delivery_stays_free(): void {
        $this->shopCalculatesNoTax();
        $this->assertSame(0.0, BGCouriers_Pricing::rate_cost(new BGCouriers_Quote(0.0, 0.0, 'EUR', 'live')));
    }

    // ── The tax itself, where the courier does not report one ────────────────

    public function test_the_shops_own_shipping_rate_is_used_when_it_has_one(): void {
        $this->shopCalculatesTax();
        $this->assertSame(0.90, BGCouriers_Pricing::courier_tax(4.50));
    }

    public function test_without_a_rate_the_standing_bulgarian_20_percent_stands_in(): void {
        $this->shopCalculatesNoTax();
        $this->assertSame(0.27, BGCouriers_Pricing::courier_tax(1.37));
    }

    public function test_the_standing_rate_can_be_filtered_for_a_shop_that_is_not_on_20(): void {
        $this->shopCalculatesNoTax();
        Functions\when('apply_filters')->alias(static function ($hook, $value) {
            return $hook === 'bgcouriers_courier_vat_rate' ? 9.0 : $value;
        });
        $this->assertSame(0.12, BGCouriers_Pricing::courier_tax(1.37));
    }

    public function test_nothing_is_taxed_onto_nothing(): void {
        $this->shopCalculatesNoTax();
        $this->assertSame(0.0, BGCouriers_Pricing::courier_tax(0.0));
    }

    // ── The number the map prints beside it ──────────────────────────────────

    public function test_the_map_prints_the_figure_the_row_will_charge(): void {
        // The map calls display_price(rate_cost($q)). On a shop that adds no tax the rate cost is
        // already everything the customer pays, and display_price must not put twenty percent on it a
        // second time however `woocommerce_tax_display_cart` was left set.
        $this->shopCalculatesNoTax();
        Functions\when('get_option')->alias(static fn($n, $d = false) => $n === 'woocommerce_tax_display_cart' ? 'incl' : $d);
        $charged = BGCouriers_Pricing::rate_cost(new BGCouriers_Quote(1.37, 0.27, 'EUR', 'live'));
        $this->assertSame(1.64, $charged);
        $this->assertSame(1.64, BGCouriers_Pricing::display_price($charged));
    }
}
