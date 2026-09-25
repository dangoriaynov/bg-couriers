<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-icons.php';
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';

/**
 * The WooCommerce app shows an order's shipping method and its address and nothing else about the
 * delivery, so "Speedy" over "ВАРНА - ДРАГОМАН / гр. ВАРНА ул. ШЕЙНОВО No 24" gave the owner no way to
 * tell an office order from a locker or from a delivery to the customer's own door - the address of an
 * office order IS the office's, and reads exactly like a home address with a company line above it
 * (reported with a screenshot, 2026-09-24).
 *
 * The delivery type now rides on the shipping line's name, which is the field that question is asked of.
 *
 * @group core
 */
final class ShippingItemNameTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // The catalogue is not loaded here; the English source string is what the label falls back to.
        Functions\when('__')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_the_type_is_appended_to_the_rate_name(): void {
        $this->assertSame('Speedy: To office',  BGCouriers_Checkout::shipping_item_name('Speedy', 'office'));
        $this->assertSame('Speedy: To address', BGCouriers_Checkout::shipping_item_name('Speedy', 'address'));
        $this->assertSame('BOX NOW: To APS',    BGCouriers_Checkout::shipping_item_name('BOX NOW', 'automat'));
    }

    /**
     * Composed from the BASE name every time. An order re-saved in the admin editor runs this again, and
     * appending to the current name would read "Speedy: To office: To office", once per save.
     */
    public function test_naming_an_already_named_line_does_not_stack(): void {
        $once  = BGCouriers_Checkout::shipping_item_name('Speedy', 'office');
        $twice = BGCouriers_Checkout::shipping_item_name('Speedy', 'office');
        $this->assertSame($once, $twice);
        // And the same base renamed to another type is that other type, not both.
        $this->assertSame('Speedy: To address', BGCouriers_Checkout::shipping_item_name('Speedy', 'address'));
    }

    /**
     * A method this plugin does not know is not something to print at a customer: BGCouriers_Icons
     * hands back the id itself, and the name keeps what the shop configured instead of gaining an
     * English word out of a database column.
     */
    public function test_an_unknown_type_adds_nothing(): void {
        $this->assertSame('Speedy', BGCouriers_Checkout::shipping_item_name('Speedy', 'drone'));
        $this->assertSame('Speedy', BGCouriers_Checkout::shipping_item_name('Speedy', ''));
    }

    /** No rate name to build on: nothing invented, and no stray colon. */
    public function test_an_empty_rate_name_stays_empty(): void {
        $this->assertSame('', BGCouriers_Checkout::shipping_item_name('', 'office'));
        $this->assertSame('', BGCouriers_Checkout::shipping_item_name('   ', 'office'));
    }
}
