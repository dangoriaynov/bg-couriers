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

    public function test_abroad_it_names_the_country_and_offers_the_way_back(): void {
        update_option('bgcouriers_cod_fiscalization', 'ppp'); // no prepaid gateway is enabled in a test shop
        $this->deliver_to('RO');
        $out = $this->checkout()->no_shipping_reason('the original');
        $this->assertStringNotContainsString('the original', $out);
        $this->assertStringContainsString('Romania', $out, 'the message must name where the parcel was going');
        $this->assertStringContainsString('bgcouriers_home', $out, 'and carry the link back out of it');
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
