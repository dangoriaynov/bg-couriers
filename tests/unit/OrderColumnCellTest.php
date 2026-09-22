<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-labels.php';
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
    /**
     * Once the courier holds the parcel the delivery details cannot be changed, so the pencil goes dim
     * and loses its href - leading it to an editor that will refuse to save is worse than not offering
     * it. The padlock that used to carry this message is gone with it: a disabled control says the same
     * thing in the place the merchant is already looking, and this column has no room to spare.
     */
    public function test_a_collected_shipment_dims_the_pencil_and_drops_the_padlock(): void {
        foreach (['esc_html', 'esc_html__', 'esc_attr', 'esc_attr__', 'esc_url', 'sanitize_html_class'] as $f) {
            Functions\when($f)->alias('trim');
        }
        Functions\when('human_time_diff')->justReturn('5 minutes');
        $stub = new class { public function get_edit_order_url(): string { return 'http://edit'; } };
        Functions\when('wc_get_order')->justReturn($stub);

        $order = new WC_Order();
        $order->meta = ['_bgcouriers_handover' => 'yes', '_bgcouriers_track_stage' => 'transit'];
        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 7, '', '', '', '', 'http://re', $order);

        $this->assertStringContainsString('bgc-edit-lnk bgc-off', $h, 'the pencil must be dimmed');
        $this->assertStringNotContainsString('http://edit', $h, 'and must not lead anywhere');
        $this->assertStringContainsString('aria-disabled="true"', $h);
        $this->assertStringNotContainsString('bgc-lock', $h, 'the padlock is gone');
        $this->assertStringNotContainsString('bgc-regen', $h, 're-issue is still withheld while locked');

        $open = new WC_Order();
        $open->meta = ['_bgcouriers_track_stage' => 'registered'];
        $g = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 7, '', '', '', '', 'http://re', $open);
        $this->assertStringContainsString('http://edit', $g, 'an uncollected parcel keeps its pencil');
        $this->assertStringNotContainsString('bgc-off', $g);
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

    /**
     * "Not printed yet" is the PRINT TILE turning green, not a second printer icon beside the buttons.
     * The owner's reading of the list (2026-09-22): the extra badge made the row look like it had one
     * more action than it did, and it outlived the print because the PDF opens in a new tab and the list
     * never reloads. One tile, one colour, and the click itself turns it back (bgc-orders-list.js).
     */
    public function test_an_unprinted_waybill_turns_the_print_tile_green_instead_of_adding_a_badge(): void {
        foreach (['esc_html', 'esc_html__', 'esc_attr', 'esc_attr__', 'esc_url', 'sanitize_html_class'] as $f) {
            Functions\when($f)->alias('trim');
        }
        Functions\when('wc_get_order')->justReturn(new class { public function get_edit_order_url(): string { return 'http://edit'; } });
        $order = new WC_Order();
        $order->meta = ['_bgcouriers_waybill' => 'W123', '_bgcouriers_label_needs_print' => '1'];
        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 7, '', '', '', '', 'http://re', $order);
        $this->assertMatchesRegularExpression('~<a class="bgc-ico bgc-primary bgc-unprinted"[^>]*href="http://p"~', $h, 'the print tile carries the state');
        $this->assertStringNotContainsString('bgc-wb-flag', $h, 'no separate badge');
        $this->assertStringContainsString('Print label (not printed yet)', $h, 'the tile says so on hover');

        $printed = new WC_Order();
        $printed->meta = ['_bgcouriers_waybill' => 'W123', '_bgcouriers_label_needs_print' => ''];
        $g = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 7, '', '', '', '', 'http://re', $printed);
        $this->assertStringNotContainsString('bgc-unprinted', $g);
        $this->assertStringNotContainsString('not printed yet', $g);
    }

    /** The red "order changed" warning is a different signal and keeps its own badge. */
    public function test_a_stale_waybill_keeps_the_red_badge_and_a_plain_print_tile(): void {
        foreach (['esc_html', 'esc_html__', 'esc_attr', 'esc_attr__', 'esc_url', 'sanitize_html_class'] as $f) {
            Functions\when($f)->alias('trim');
        }
        // The fingerprint compares the order as it stands, which reads WooCommerce's number helpers.
        Functions\when('wc_format_decimal')->alias(static fn($v, $d = 2) => number_format((float) $v, (int) $d, '.', ''));
        Functions\when('wc_get_weight')->alias(static fn($w, $to, $from = null) => (float) $w / 1000);
        Functions\when('get_option')->justReturn('1');
        Functions\when('wc_get_order')->justReturn(new class { public function get_edit_order_url(): string { return 'http://edit'; } });
        $order = new WC_Order();
        $order->meta = ['_bgcouriers_waybill' => 'W123', '_bgcouriers_label_fp' => 'old', '_bgcouriers_label_needs_print' => '1'];
        $h = BGCouriers_Order_Columns::cell_html('W123', 'http://p', 'http://t', 'http://g', 7, '', '', '', '', 'http://re', $order);
        $this->assertStringContainsString('bgc-wb-flag bgc-wb-stale', $h);
        $this->assertStringContainsString('dashicons-warning', $h);
        $this->assertStringNotContainsString('bgc-unprinted', $h, 'stale outranks unprinted, as label_state() says');
    }
}
