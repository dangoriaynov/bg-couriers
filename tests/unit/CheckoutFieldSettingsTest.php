<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * Two things the plugin does to a page it does not own - dropping WooCommerce's address fields at
 * checkout and hiding its shipping calculator on the cart - are now decisions the store makes.
 *
 * They stay ON out of the box: the courier's city/office/automat IS the delivery address, and a rate
 * priced to a post code is not a rate anyone here will pay, so a store that installs this and changes
 * nothing gets a checkout that agrees with itself. A store that ships some other way as well unticks
 * them and WooCommerce's own fields come straight back.
 */
final class CheckoutFieldSettingsTest extends TestCase {

    protected function setUp(): void { Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); }

    /** @param array<string,string> $stored options as the database has them */
    private function options(array $stored): void {
        Functions\when('get_option')->alias(static fn($key, $default = false) => $stored[$key] ?? $default);
    }

    public function test_both_are_on_for_a_store_that_has_never_opened_the_settings(): void {
        $this->options([]);
        $this->assertTrue(BGCouriers_Settings::own_address_fields());
        $this->assertTrue(BGCouriers_Settings::hide_shipping_calculator());
    }

    public function test_unticking_either_one_gives_woocommerce_its_page_back(): void {
        $this->options(['bgcouriers_own_address_fields' => 'no', 'bgcouriers_hide_shipping_calc' => 'no']);
        $this->assertFalse(BGCouriers_Settings::own_address_fields());
        $this->assertFalse(BGCouriers_Settings::hide_shipping_calculator());
    }

    /** They are independent - a store may keep its address fields and still hide the calculator. */
    public function test_the_two_settings_do_not_read_each_other(): void {
        $this->options(['bgcouriers_own_address_fields' => 'no']);
        $this->assertFalse(BGCouriers_Settings::own_address_fields());
        $this->assertTrue(BGCouriers_Settings::hide_shipping_calculator());
    }

    /** The checkout fields themselves: stripped while the setting is on, handed back untouched when off. */
    public function test_the_checkout_fields_follow_the_setting(): void {
        require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';
        $checkout = (new ReflectionClass('BGCouriers_Checkout'))->newInstanceWithoutConstructor();
        $fields   = ['billing' => [
            'billing_address_1' => [], 'billing_city' => [], 'billing_postcode' => [], 'billing_state' => [],
            'billing_phone' => ['required' => false], 'billing_email' => ['required' => true],
        ]];

        $this->options(['bgcouriers_own_address_fields' => 'no']);
        $this->assertSame($fields, $checkout->simplify_fields($fields), 'nothing of WooCommerce may be touched while off');

        $this->options([]);                                   // default: on
        $stripped = $checkout->simplify_fields($fields);
        foreach (['billing_address_1', 'billing_city', 'billing_postcode', 'billing_state'] as $gone) {
            $this->assertArrayNotHasKey($gone, $stripped['billing']);
        }
        // A courier label needs a phone; the e-mail is optional and only sent if the merchant opts in.
        $this->assertTrue($stripped['billing']['billing_phone']['required']);
        $this->assertFalse($stripped['billing']['billing_email']['required']);
    }

    /**
     * The e-mail is the one field this plugin makes OPTIONAL that WooCommerce ships as required, so it
     * is the one a store may want back. Off by default - every shop that already runs this keeps the
     * checkout it has - and the phone is not part of the choice: every courier's label is built with it.
     */
    public function test_the_email_is_optional_until_the_store_asks_for_it(): void {
        require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';
        $checkout = (new ReflectionClass('BGCouriers_Checkout'))->newInstanceWithoutConstructor();
        $fields   = ['billing' => [
            'billing_phone' => ['required' => false], 'billing_email' => ['required' => true],
        ]];

        $this->options([]);
        $this->assertFalse(BGCouriers_Settings::require_email());
        $this->assertFalse($checkout->simplify_fields($fields)['billing']['billing_email']['required']);

        $this->options(['bgcouriers_require_email' => 'yes']);
        $this->assertTrue(BGCouriers_Settings::require_email());
        $asked = $checkout->simplify_fields($fields);
        $this->assertTrue($asked['billing']['billing_email']['required']);
        $this->assertTrue($asked['billing']['billing_phone']['required'], 'the phone is never part of the choice');
    }

    /**
     * The calculator is gated by a WooCommerce OPTION, so the plugin short-circuits it. Off, the
     * short-circuit must pass the incoming value straight through - that is what tells WooCommerce to
     * read its own option and show the calculator.
     */
    public function test_the_cart_calculator_short_circuit_follows_the_setting(): void {
        require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';
        $checkout = (new ReflectionClass('BGCouriers_Checkout'))->newInstanceWithoutConstructor();

        $this->options([]);
        $this->assertSame('no', $checkout->hide_calculator_option(false));

        $this->options(['bgcouriers_hide_shipping_calc' => 'no']);
        $this->assertFalse($checkout->hide_calculator_option(false));
        $this->assertSame('yes', $checkout->hide_calculator_option('yes'), 'another plugin’s answer must survive');
    }

    /** Both are offered in the admin, and both default to yes there as well as in code. */
    public function test_the_admin_offers_both_and_defaults_them_on(): void {
        $settings = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-wc-settings.php');
        foreach (['bgcouriers_own_address_fields', 'bgcouriers_hide_shipping_calc'] as $id) {
            $this->assertMatchesRegularExpression("/'id' => '{$id}'.*?'default' => 'yes'/s", $settings,
                "$id must be offered in the settings page and default to yes");
        }
        $this->assertMatchesRegularExpression("/'id' => 'bgcouriers_require_email'.*?'default' => 'no'/s", $settings,
            'the e-mail requirement must be offered in the settings page, and off out of the box');
        $this->assertStringNotContainsString(
            '.woocommerce-shipping-calculator',
            (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/bgc-cart.css'),
            'hiding the calculator from a stylesheet that always loads would ignore the setting'
        );
    }
}
