<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';

/**
 * WooCommerce 10.x calls the heading above the shipping methods "Shipment"; bg_BG has no translation for
 * it, so a Bulgarian checkout showed one English word above our courier picker. The heading is dropped -
 * the picker underneath already says what the block is.
 *
 * Which heading is WooCommerce's own is decided by watching, not by translating: the plugin sees the
 * name at priority 1 and decides at 999. That is what makes it work in any locale, and it is also what
 * WordPress.org requires - translating a neighbour's strings, or hiding their msgids in variables to
 * keep them out of our catalogue, fails the scan.
 *
 * @group core
 */
final class PackageNameTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** A checkout whose cart produced $count shipping packages. */
    private function cartWith(int $count): BGCouriers_Checkout {
        Functions\when('WC')->justReturn(new BGCouriers_WC_Shipping_Stub($count));
        return new BGCouriers_Checkout();
    }

    /** The name WooCommerce built goes in one end; whatever survives comes out of the other. */
    private function heading(BGCouriers_Checkout $c, string $wc_name, int $index = 0, ?string $renamed = null): string {
        $c->capture_package_name($wc_name, $index, []);
        return $c->package_name($renamed ?? $wc_name, $index, []);
    }

    /** The default heading is dropped, in English or translated - it is WooCommerce's, and it repeats us. */
    public function test_the_default_heading_is_removed_whatever_the_locale_calls_it(): void {
        $c = $this->cartWith(1);
        $this->assertSame('', $this->heading($c, 'Shipment'));
        $this->assertSame('', $this->heading($c, 'Пратка'));
        $this->assertSame('', $this->heading($c, 'Доставка'));
    }

    /** Numbered names for multi-package carts are never rewritten - they are what tells them apart. */
    public function test_multi_package_names_are_untouched(): void {
        $c = $this->cartWith(2);
        $this->assertSame('Shipment 1', $this->heading($c, 'Shipment 1', 0));
        $this->assertSame('Пратка 2', $this->heading($c, 'Пратка 2', 1));
    }

    /** Another plugin that renamed it keeps its value - we only ever remove WooCommerce's own. */
    public function test_a_name_set_by_someone_else_is_kept(): void {
        $c = $this->cartWith(1);
        $this->assertSame('Моята доставка', $this->heading($c, 'Shipment', 0, 'Моята доставка'));
    }

    /** Before WooCommerce is up there is nothing to count; one package is the safe reading. */
    public function test_without_woocommerce_the_single_package_reading_holds(): void {
        Functions\when('WC')->justReturn(null);
        $c = new BGCouriers_Checkout();
        $this->assertSame('', $this->heading($c, 'Shipment'));
    }

    /** Nothing in this file may translate a string that belongs to WooCommerce. */
    public function test_the_plugin_does_not_translate_woocommerces_strings(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php');
        $this->assertSame(0, preg_match_all("/(?<![\\w\\*])(?:__|_e|_x|_n|esc_html__|esc_attr__)\\s*\\([^)]*'woocommerce'/", $source));
    }
}

/** Stands in for WC(): only shipping()->get_packages() is ever asked for. */
final class BGCouriers_WC_Shipping_Stub {
    private int $count;
    public function __construct(int $count) { $this->count = $count; }
    public function shipping(): self { return $this; }
    public function get_packages(): array { return array_fill(0, $this->count, []); }
}
