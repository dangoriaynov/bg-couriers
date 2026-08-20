<?php
/**
 * A checkout with nothing to choose from has to say why - and, abroad, offer the way back.
 *
 * The state under test is a real one and not a rare one: a shop whose cash on delivery is legal only
 * through the courier's ППП cannot be paid for a parcel that leaves the country, so every rate for a
 * foreign address is refused on purpose. What the customer then saw was WooCommerce's "please ensure
 * that your address has been entered correctly", about an address with nothing wrong with it, on a page
 * whose country picker is drawn against a shipping rate and therefore was not there either.
 *
 * @group core
 */
final class NoShippingAbroadTest extends WP_UnitTestCase {

    /** The filters are hooked in the constructor; the test wants the method, not another set of hooks. */
    private function checkout(): BGCouriers_Checkout {
        return (new ReflectionClass('BGCouriers_Checkout'))->newInstanceWithoutConstructor();
    }

    private function deliver_to(string $country): void {
        if (!function_exists('WC') || !WC()) { $this->markTestSkipped('WC not loaded'); }
        if (!WC()->session && method_exists(WC(), 'initialize_session')) { WC()->initialize_session(); }
        if (!WC()->session) { $this->markTestSkipped('no WooCommerce session in this environment'); }
        WC()->session->set('bgcouriers_country', $country);
    }

    public function tear_down() {
        delete_option('bgcouriers_cod_fiscalization');
        if (function_exists('WC') && WC() && WC()->session) { WC()->session->set('bgcouriers_country', ''); }
        unset($_GET['bgcouriers_home']);
        parent::tear_down();
    }

    public function test_a_domestic_checkout_keeps_woocommerces_own_message(): void {
        $this->deliver_to('BG');
        $this->assertSame(
            'the original',
            $this->checkout()->no_shipping_reason('the original'),
            'at home the address really can be wrong, and WooCommerce says so better than we would'
        );
    }

    /** The href out of the message, or '' if there is none. */
    private function way_back(string $html): string {
        return preg_match('/href="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
    }

    public function test_abroad_it_names_the_country_and_offers_the_way_back(): void {
        update_option('bgcouriers_cod_fiscalization', 'ppp'); // no prepaid gateway is enabled in a test shop
        $this->deliver_to('RO');
        $out = $this->checkout()->no_shipping_reason('the original');
        $this->assertStringNotContainsString('the original', $out);
        $this->assertStringContainsString('Romania', $out, 'the message must name where the parcel was going');
        $this->assertStringContainsString('bgcouriers_home', $out, 'and carry the link back out of it');
    }

    /**
     * The link has to name the page it goes to.
     *
     * Both totals blocks are re-rendered inside WooCommerce's own AJAX refresh, so the current URL at the
     * moment this message is built is /?wc-ajax=update_order_review. A link built off it - which is what
     * add_query_arg() does when given no base - sends the customer to a page whose entire body is "-1".
     * That shipped once and was caught in a browser, not here; these two assertions are what would have
     * caught it here.
     */
    public function test_the_way_back_points_at_the_checkout_page_and_not_at_the_ajax_url(): void {
        update_option('bgcouriers_cod_fiscalization', 'ppp');
        $this->deliver_to('RO');
        $href = $this->way_back($this->checkout()->no_shipping_reason('the original'));
        $this->assertStringStartsWith(wc_get_checkout_url(), $href, 'the way back must be the checkout page');
        $this->assertStringNotContainsString('wc-ajax', $href, 'never the AJAX endpoint the message was drawn in');
    }

    public function test_on_the_cart_the_way_back_is_the_cart(): void {
        update_option('bgcouriers_cod_fiscalization', 'ppp');
        $this->deliver_to('RO');
        $href = $this->way_back($this->checkout()->no_shipping_reason_cart('the original'));
        $this->assertStringStartsWith(wc_get_cart_url(), $href, 'a customer on the cart stays on the cart');
        $this->assertStringNotContainsString('wc-ajax', $href);
    }

    /**
     * The way back changes the session, so it is a request that has to prove it was meant. Without this
     * any page could carry a link that quietly moved a customer's delivery country.
     */
    public function test_the_way_back_refuses_a_request_that_carries_no_valid_nonce(): void {
        $this->deliver_to('RO');
        $_GET['bgcouriers_home'] = 'not-a-nonce';
        $this->checkout()->reset_country();
        $this->assertSame('RO', WC()->session->get('bgcouriers_country'));
    }
}
