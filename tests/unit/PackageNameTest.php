<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';

/**
 * WooCommerce 10.x calls the heading above the shipping methods "Shipment"; bg_BG has no translation for
 * it, so a Bulgarian checkout showed one English word above our courier picker. We substitute
 * WooCommerce's own translated "Shipping" - but only while theirs is genuinely missing.
 *
 * @group core
 */
final class PackageNameTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $dict what the WooCommerce catalogue returns */
    private function withCatalogue(array $dict): BGCouriers_Checkout {
        Functions\when('_x')->alias(static fn($text, $ctx, $dom = '') => $dict[$text] ?? $text);
        Functions\when('__')->alias(static fn($text, $dom = '') => $dict[$text] ?? $text);
        return new BGCouriers_Checkout();
    }

    /** The default heading is dropped - our picker underneath already says what it is. */
    public function test_the_default_heading_is_removed(): void {
        $this->assertSame('', $this->withCatalogue(['Shipping' => 'Доставка'])->package_name('Shipment', 0, []));
        $this->assertSame('', $this->withCatalogue([])->package_name('Shipment', 0, []));
    }

    /** Also when the locale HAS translated it - it is still the default heading. */
    public function test_a_translated_default_is_removed_too(): void {
        $c = $this->withCatalogue(['Shipment' => 'Пратка', 'Shipping' => 'Доставка']);
        $this->assertSame('', $c->package_name('Пратка', 0, []));
        $this->assertSame('', $c->package_name('Доставка', 0, []));
    }

    /** Numbered names for multi-package carts are never rewritten. */
    public function test_multi_package_names_are_untouched(): void {
        $c = $this->withCatalogue(['Shipping' => 'Доставка']);
        $this->assertSame('Shipment 2', $c->package_name('Shipment 2', 1, []));
    }

    /** Another plugin that already renamed it keeps its value. */
    public function test_a_name_set_by_someone_else_is_kept(): void {
        $c = $this->withCatalogue(['Shipping' => 'Доставка']);
        $this->assertSame('Моята доставка', $c->package_name('Моята доставка', 0, []));
    }
}
