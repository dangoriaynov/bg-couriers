<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-packer.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';
require_once dirname(__DIR__) . '/stubs/wc-tax.php';

/**
 * The money the customer hands the COURIER, which is not the same question as how the shop displays a price.
 *
 * There are two numbers in a rate row and they had been conflated for a long time.
 *
 * A **charged** delivery is part of the order: WooCommerce is given a net cost, adds the shipping tax,
 * and renders it according to `woocommerce_tax_display_cart`. That is the shop's own arithmetic and it
 * is right as it stands.
 *
 * A **door** delivery is not in the order at all. The rate costs 0 and the row shows what the courier
 * will collect in cash on the doorstep - the tooltip beside it says "Paid to the courier on delivery."
 * The courier charges VAT on that whether or not the shop has chosen to show ITS prices with tax, so a
 * setting about the shop's shop-window cannot be what decides it.
 *
 * It was decided by exactly that, through display_price(), which is gated on the display setting. On
 * the default ('excl') the customer was told 2,20 and handed the courier 2,64. Measured on the live dev
 * shop 2026-08-31, one 1 kg parcel: Speedy short by 0.44, Express One by 0.68, Европът by 0.46 - each
 * exactly its own VAT.
 *
 * And a second layer underneath, which is why reading the courier's own VAT was not enough: the quote
 * CACHE stored only the price and threw the tax away, so `$quote->tax` was 0 for three hours at a time
 * even for the couriers whose API reports it.
 *
 * @group core
 */
final class DoorPriceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** The shop displays prices WITHOUT tax - the default, and what the live shop is set to. */
    private function shopShowsNetPrices(): void {
        Functions\when('get_option')->alias(function ($k, $d = false) {
            return $k === 'woocommerce_tax_display_cart' ? 'excl' : $d;
        });
    }

    private function shopShowsGrossPrices(): void {
        Functions\when('get_option')->alias(function ($k, $d = false) {
            return $k === 'woocommerce_tax_display_cart' ? 'incl' : $d;
        });
    }

    // ── The courier's own VAT, where the courier reports one ─────────────────

    public function test_the_door_price_is_what_the_courier_collects_not_what_the_shop_displays(): void {
        // Speedy's API hands over the base and the VAT separately: 2.20 + 0.44. The customer pays 2.64.
        $q = new BGCouriers_Quote(2.20, 0.44, 'EUR', 'live');
        $this->shopShowsNetPrices();
        $this->assertSame(2.64, BGCouriers_Pricing::door_price($q));
    }

    public function test_the_shops_display_setting_does_not_change_what_the_courier_charges(): void {
        // The regression guard. This is the whole bug in one assertion: the number handed over on a
        // doorstep cannot depend on a setting about the shop's own shop-window.
        $q = new BGCouriers_Quote(2.20, 0.44, 'EUR', 'live');
        $this->shopShowsNetPrices();
        $net_shop = BGCouriers_Pricing::door_price($q);
        $this->shopShowsGrossPrices();
        $gross_shop = BGCouriers_Pricing::door_price($q);
        $this->assertSame($net_shop, $gross_shop);
        $this->assertSame(2.64, $net_shop);
    }

    // ── And where it does not ────────────────────────────────────────────────

    public function test_a_courier_that_reports_no_tax_has_the_shops_shipping_rate_applied(): void {
        // Pigeon and Sameday do not break their price down, and the plugin's standing rule is that a
        // quote is net. So the door price is that net plus the shipping tax - the same 20% the couriers
        // that DO report it charge (measured: 2.20/0.44, 3.38/0.68, 2.29/0.46 are all exactly 20%).
        $q = new BGCouriers_Quote(2.59, 0.0, 'EUR', 'live');
        $this->shopShowsNetPrices();
        $this->assertSame(3.11, BGCouriers_Pricing::door_price($q));
    }

    public function test_that_fallback_also_ignores_the_display_setting(): void {
        $q = new BGCouriers_Quote(2.59, 0.0, 'EUR', 'live');
        $this->shopShowsNetPrices();
        $a = BGCouriers_Pricing::door_price($q);
        $this->shopShowsGrossPrices();
        $this->assertSame($a, BGCouriers_Pricing::door_price($q));
    }

    public function test_nothing_to_pay_stays_nothing(): void {
        // Free delivery: there is no door price, and 0 must not acquire tax.
        $this->shopShowsNetPrices();
        $this->assertSame(0.0, BGCouriers_Pricing::door_price(new BGCouriers_Quote(0.0, 0.0, 'EUR', 'live')));
    }

    // ── display_price() keeps doing its own, different job ───────────────────

    public function test_display_price_still_answers_the_question_it_was_written_for(): void {
        // It exists so a price printed OUTSIDE a rate row matches the rate row beside it. That is a
        // question about presentation, and it rightly follows the display setting. The two must not be
        // collapsed into one helper again - that is how they were conflated in the first place.
        $this->shopShowsNetPrices();
        $this->assertSame(10.0, BGCouriers_Pricing::display_price(10.0));
        $this->shopShowsGrossPrices();
        $this->assertSame(12.0, BGCouriers_Pricing::display_price(10.0));
    }

    // ── The cache must not eat half the quote ────────────────────────────────

    public function test_a_cached_quote_still_knows_what_the_courier_charges(): void {
        // The second layer of the bug. Quotes are cached for three hours and the cache kept only the
        // price, so `tax` came back 0 and the door price silently fell to the fallback - which meant the
        // number could differ between a cold checkout and a warm one for the same parcel.
        $live   = new BGCouriers_Quote(2.20, 0.44, 'EUR', 'live');
        $cached = BGCouriers_Pricing::quote_from_cache(BGCouriers_Pricing::quote_to_cache($live), 'EUR');
        $this->assertSame($live->price, $cached->price);
        $this->assertSame($live->tax, $cached->tax);
        $this->assertSame('cached', $cached->source);
        $this->shopShowsNetPrices();
        $this->assertSame(BGCouriers_Pricing::door_price($live), BGCouriers_Pricing::door_price($cached));
    }

    public function test_a_cache_entry_written_before_this_fix_is_still_readable(): void {
        // Three hours of entries in the wild have no tax in them. They must read as "the courier did not
        // say", not blow up - the fallback then gives the same 20% those couriers actually charge.
        $old = BGCouriers_Pricing::quote_from_cache(['p' => 2.20, 'c' => 'EUR'], 'EUR');
        $this->assertSame(2.20, $old->price);
        $this->assertSame(0.0, $old->tax);
        $this->shopShowsNetPrices();
        $this->assertSame(2.64, BGCouriers_Pricing::door_price($old));
    }

    // ── The invariant that actually protects the customer ────────────────────

    public function test_the_same_delivery_costs_the_same_whichever_route_it_takes(): void {
        // The check that would have caught this bug on day one, and the one worth keeping: a delivery
        // paid at the door and the same delivery charged with the order must cost the customer the same
        // money. Verified end to end on the live shop 2026-08-31 - at the door the row showed 2.75 and
        // the customer handed the courier 2.75; charged, the rate cost 2.29 net and WooCommerce added
        // 0.46, so the shop charged 2.75. Before the fix the door route said 2.29.
        //
        // The two agree while the shop's shipping tax rate is the rate the courier charges - 20% here,
        // and the same 20% the couriers that report their own VAT return. A shop that configures some
        // other shipping rate makes its OWN charged price differ from the courier's; the door figure
        // stays right either way, because it is the courier's own total and not our arithmetic.
        $this->shopShowsNetPrices();
        foreach ([[2.29, 0.46], [3.38, 0.68], [2.20, 0.44], [2.59, 0.0], [5.06, 0.0]] as [$net, $tax]) {
            $q = new BGCouriers_Quote($net, $tax, 'EUR', 'live');
            // what the shop would charge for the same delivery: the net rate, plus the tax WooCommerce
            // adds to it - which is exactly what `taxes => ''` on the rate means.
            $charged = round($net + array_sum(WC_Tax::calc_shipping_tax($net, WC_Tax::get_shipping_tax_rates())), 2);
            $this->assertEqualsWithDelta($charged, BGCouriers_Pricing::door_price($q), 0.01,
                "a delivery of $net costs the customer a different amount depending on who collects it");
        }
    }

    public function test_the_door_price_is_never_the_bare_net_figure(): void {
        // The shape of the regression, stated directly: whatever else changes, the number on the row
        // must not be the untaxed one while a tax exists. That is what the customer was being shown.
        $this->shopShowsNetPrices();
        foreach ([[2.29, 0.46], [2.59, 0.0]] as [$net, $tax]) {
            $q = new BGCouriers_Quote($net, $tax, 'EUR', 'live');
            $this->assertGreaterThan($net, BGCouriers_Pricing::door_price($q));
        }
    }
}
