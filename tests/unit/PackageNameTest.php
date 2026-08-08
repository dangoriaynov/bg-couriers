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
 * name before every other listener and decides after all of them. That is what makes it work in any locale, and it is also what
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

    /**
     * Nothing that ships may translate anybody else's string, in any file.
     *
     * Both halves of this failed a real WordPress.org upload: a text domain that is not ours
     * (WordPress.WP.I18n.TextDomainMismatch) and a variable where a literal belongs
     * (WordPress.WP.I18n.NonSingularStringLiteralText). Both are ERRORs, and both are rejections.
     */
    public function test_no_shipped_file_translates_a_string_that_is_not_ours(): void {
        $calls   = '(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x|translate)';
        $foreign = [];
        $literal = [];
        foreach ($this->shipped_php_files() as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all("/(?<![\\w\\$])$calls\\s*\\([^)]*'(?:woocommerce|default|wordpress)'/", $source)) {
                $foreign[] = basename($file);
            }
            if (preg_match_all("/(?<![\\w\\$])$calls\\s*\\(\\s*\\\$/", $source)) {
                $literal[] = basename($file);
            }
        }
        $this->assertSame([], $foreign, 'a plugin translates its own strings, never a neighbour\'s');
        $this->assertSame([], $literal, 'a translation call takes a literal, never a variable');
    }

    /** @return string[] every PHP file in the package except the bundled PDF library */
    private function shipped_php_files(): array {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/includes'));
        $out   = [];
        foreach ($files as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && strpos($f->getPathname(), '/lib/') === false) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }
}

/** Stands in for WC(): only shipping()->get_packages() is ever asked for. */
final class BGCouriers_WC_Shipping_Stub {
    private int $count;
    public function __construct(int $count) { $this->count = $count; }
    public function shipping(): self { return $this; }
    public function get_packages(): array { return array_fill(0, $this->count, []); }
}
