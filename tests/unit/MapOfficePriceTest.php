<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';
require_once dirname(__DIR__) . '/stubs/wc-tax.php';

/**
 * The per-office figure the address map advertises MUST be the one the shipping row will charge - the
 * map is fed from this class for exactly that reason. map_office_price() answers it for a LIVE quote:
 * in the order total -> the shop-window price, at the door -> the courier's cash, null when there is
 * nothing to show. A positive number is the ONLY paid result, which is what lets the map caller treat a
 * dropped option and a free one apart (free is decided from the cart by the caller, not here). And a
 * quote handed here carries its own tax: a bare reference number must never be, or door_price() would
 * add the shop's shipping tax to a figure that is not owed one.
 *
 * @group core
 */
final class MapOfficePriceTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** The shop displays prices WITHOUT tax - the default, and what the live shop is set to. */
    private function shopShowsNetPrices(): void {
        Functions\when('wc_tax_enabled')->justReturn(true);
        Functions\when('get_option')->alias(fn($k, $d = false) => $k === 'woocommerce_tax_display_cart' ? 'excl' : $d);
    }

    /** No quote (an unreachable courier) shows nothing - it must not empty the whole map. */
    public function test_no_quote_shows_nothing(): void {
        $this->assertNull(BGCouriers_Pricing::map_office_price(null, true));
        $this->assertNull(BGCouriers_Pricing::map_office_price(null, false));
    }

    /** In the order total: the shop-window price (display_price of the net rate cost). */
    public function test_in_the_order_total_shows_the_display_price(): void {
        $this->shopShowsNetPrices();
        $q = new BGCouriers_Quote(2.29, 0.46, 'EUR', 'live');
        $expected = BGCouriers_Pricing::display_price(BGCouriers_Pricing::rate_cost($q));
        $this->assertSame($expected, BGCouriers_Pricing::map_office_price($q, true));
        $this->assertSame(2.29, $expected, 'excl display, WC adds the tax on the row itself');
    }

    /** At the door: what the courier collects in cash, tax and all. */
    public function test_at_the_door_shows_the_door_price(): void {
        $this->shopShowsNetPrices();
        $q = new BGCouriers_Quote(2.29, 0.46, 'EUR', 'live');
        $this->assertSame(BGCouriers_Pricing::door_price($q), BGCouriers_Pricing::map_office_price($q, false));
        $this->assertSame(2.75, BGCouriers_Pricing::map_office_price($q, false));
    }

    /**
     * A quote that prices out at or below zero is DROPPED (null), never returned as 0.0 - so the map
     * caller, which treats free separately, can never mistake an unpriced courier for a free delivery.
     */
    public function test_a_zero_price_is_dropped_not_returned_as_zero(): void {
        $this->assertNull(BGCouriers_Pricing::map_office_price(new BGCouriers_Quote(0.0, 0.0, 'EUR', 'live'), true));
        $this->assertNull(BGCouriers_Pricing::map_office_price(new BGCouriers_Quote(0.0, 0.0, 'EUR', 'live'), false));
    }
}
