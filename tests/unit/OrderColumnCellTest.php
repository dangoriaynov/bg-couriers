<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-columns.php';

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
        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g');
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
        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 0, '', '', 'Speedy', 'http://logo.png');
        $this->assertStringContainsString('bgc-ltile', $h);
        $this->assertStringContainsString('data-tip="Speedy"', $h);
        $this->assertStringContainsString('http://logo.png', $h);
        $this->assertLessThan(strpos($h, 'bgc-copy'), strpos($h, 'bgc-ltile')); // logo tile comes first
        // The empty-waybill cell keeps the logo tile too (JS swaps back to Generate preserving it).
        $g = BGCouriers_Order_Columns::cell_html('', 'http://p', 'http://t', 'http://g', 0, '', '', 'Speedy', 'http://logo.png');
        $this->assertStringContainsString('bgc-ltile', $g);
        $this->assertStringContainsString('bgc-gen', $g);
    }
    public function test_edit_pencil_shows_in_both_states_with_autoopen_marker(): void {
        Functions\when('esc_html')->alias('trim');
        Functions\when('esc_html__')->alias('trim');
        Functions\when('esc_attr')->alias('trim');
        Functions\when('esc_attr__')->alias('trim');
        Functions\when('esc_url')->alias('trim');
        $stub = new class { public function get_edit_order_url(): string { return 'http://edit'; } };
        Functions\when('wc_get_order')->justReturn($stub);
        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 7);
        $this->assertStringContainsString('http://edit#bgc-edit', $h);
        $this->assertSame(2, substr_count($h, 'class="bgc-row"')); // row 1 logo+pencil, row 2 actions
        $this->assertLessThan(strpos($h, 'bgc-copy'), strpos($h, 'bgc-edit-lnk')); // pencil in row 1
        $g = BGCouriers_Order_Columns::cell_html('', 'http://p', 'http://t', 'http://g', 7);
        $this->assertStringContainsString('http://edit#bgc-edit', $g); // pencil also without a waybill
        $this->assertSame(2, substr_count($g, 'class="bgc-row"'));
        $this->assertLessThan(strpos($g, 'bgc-gen'), strpos($g, 'bgc-edit-lnk')); // pencil above Generate
        $this->assertStringContainsString('bgc-gen', $g);
    }
    /**
     * The re-issue icon sits right of the pencil in row 1, and ONLY when a waybill exists - without one
     * there is nothing to void, and the cell already offers Generate.
     */
    public function test_reissue_icon_only_with_a_waybill_and_right_of_the_pencil(): void {
        Functions\when('esc_html')->alias('trim');
        Functions\when('esc_html__')->alias('trim');
        Functions\when('esc_attr')->alias('trim');
        Functions\when('esc_attr__')->alias('trim');
        Functions\when('esc_url')->alias('trim');
        $stub = new class { public function get_edit_order_url(): string { return 'http://edit'; } };
        Functions\when('wc_get_order')->justReturn($stub);

        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 7, '', '', '', '', 'http://re');
        $this->assertStringContainsString('bgc-regen', $h);
        $this->assertStringContainsString('http://re', $h);
        $this->assertStringContainsString('dashicons-update', $h);
        $this->assertLessThan(strpos($h, 'bgc-regen'), strpos($h, 'bgc-edit-lnk')); // pencil, then re-issue
        $this->assertLessThan(strpos($h, 'bgc-copy'), strpos($h, 'bgc-regen'));     // both still in row 1
        $this->assertSame(2, substr_count($h, 'class="bgc-row"'));

        $g = BGCouriers_Order_Columns::cell_html('', 'http://p', 'http://t', 'http://g', 7, '', '', '', '', 'http://re');
        $this->assertStringNotContainsString('bgc-regen', $g);
        $this->assertStringNotContainsString('http://re', $g);
    }
    /** Callers that pass no re-issue URL (or none at all) must not render a dead button. */
    public function test_reissue_icon_absent_without_a_url(): void {
        Functions\when('esc_html')->alias('trim');
        Functions\when('esc_html__')->alias('trim');
        Functions\when('esc_attr')->alias('trim');
        Functions\when('esc_attr__')->alias('trim');
        Functions\when('esc_url')->alias('trim');
        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g');
        $this->assertStringNotContainsString('bgc-regen', $h);
    }
    public function test_no_waybill_shows_generate(): void {
        Functions\when('esc_html')->alias('trim');
        Functions\when('esc_html__')->alias('trim');
        Functions\when('esc_attr')->alias('trim');
        Functions\when('esc_attr__')->alias('trim');
        Functions\when('esc_url')->alias('trim');
        $h = BGCouriers_Order_Columns::cell_html('', 'http://p', 'http://t', 'http://g');
        $this->assertStringContainsString('http://g', $h);
        $this->assertStringNotContainsString('http://p', $h);
    }
}
