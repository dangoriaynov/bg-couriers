<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-order-columns.php';

/**
 * @group speedy
 */
final class OrderColumnCellTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_waybill_shows_print_and_track(): void {
        Functions\when('esc_html')->alias('trim');
        Functions\when('esc_html__')->alias('trim');
        Functions\when('esc_attr')->alias('trim');
        Functions\when('esc_attr__')->alias('trim');
        Functions\when('esc_url')->alias('trim');
        $h = BGC_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g');
        $this->assertStringContainsString('W123', $h);
        $this->assertStringContainsString('http://p', $h);
        $this->assertStringContainsString('http://t', $h);
        $this->assertStringNotContainsString('http://g', $h); // no Generate when already labelled
    }
    public function test_courier_logo_tile_precedes_actions_with_hint(): void {
        Functions\when('esc_html')->alias('trim');
        Functions\when('esc_html__')->alias('trim');
        Functions\when('esc_attr')->alias('trim');
        Functions\when('esc_attr__')->alias('trim');
        Functions\when('esc_url')->alias('trim');
        $h = BGC_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 0, '', '', 'Speedy', 'http://logo.png');
        $this->assertStringContainsString('bgc-ltile', $h);
        $this->assertStringContainsString('data-tip="Speedy"', $h);
        $this->assertStringContainsString('http://logo.png', $h);
        $this->assertLessThan(strpos($h, 'bgc-copy'), strpos($h, 'bgc-ltile')); // logo tile comes first
        // The empty-waybill cell keeps the logo tile too (JS swaps back to Generate preserving it).
        $g = BGC_Order_Columns::cell_html('', 'http://p', 'http://t', 'http://g', 0, '', '', 'Speedy', 'http://logo.png');
        $this->assertStringContainsString('bgc-ltile', $g);
        $this->assertStringContainsString('bgc-gen', $g);
    }
    public function test_no_waybill_shows_generate(): void {
        Functions\when('esc_html')->alias('trim');
        Functions\when('esc_html__')->alias('trim');
        Functions\when('esc_attr')->alias('trim');
        Functions\when('esc_attr__')->alias('trim');
        Functions\when('esc_url')->alias('trim');
        $h = BGC_Order_Columns::cell_html('', 'http://p', 'http://t', 'http://g');
        $this->assertStringContainsString('http://g', $h);
        $this->assertStringNotContainsString('http://p', $h);
    }
}
