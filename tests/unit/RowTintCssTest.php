<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-columns.php';

/**
 * The orders-list row tint is inline CSS built from per-courier options, so hex_to_rgb() is the escaping
 * gate: nothing but three integers may reach the stylesheet, and an unusable value must produce no rule
 * at all rather than a guessed (black) colour.
 *
 * @group core
 */
final class RowTintCssTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('sanitize_html_class')->alias(static function ($c) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $c); });
        // Two real registry entries are enough; the factories are never called (all() only reads labels).
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return null; });
        BGCouriers_Couriers::register('econt', 'Econt', static function () { return null; });
    }
    /** Leave the registry empty rather than half-populated - CouriersRegistryTest resets it itself. */
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $opts */
    private function css(array $opts, string $enabled = 'yes'): string {
        Functions\when('get_option')->alias(static function ($name, $default = false) use ($opts, $enabled) {
            if ($name === 'bgcouriers_row_tint') { return $enabled; }
            return array_key_exists($name, $opts) ? $opts[$name] : $default;
        });
        return BGCouriers_Order_Columns::row_tint_css();
    }

    public function test_six_digit_hex_becomes_a_pale_rgba_rule_on_the_cells(): void {
        $css = $this->css(['bgcouriers_speedy_row_color' => '#d63638', 'bgcouriers_econt_row_color' => '']);
        $this->assertStringContainsString('.wp-list-table tr.bgc-courier-speedy > td', $css);
        $this->assertStringContainsString('.wp-list-table tr.bgc-courier-speedy > th', $css);
        $this->assertStringContainsString('rgba(214,54,56,0.13)', $css);
        $this->assertStringContainsString('rgba(214,54,56,0.2)', $css); // hover
        $this->assertStringContainsString(':hover > td', $css);
    }

    public function test_three_digit_shorthand_is_expanded(): void {
        $css = $this->css(['bgcouriers_speedy_row_color' => '#abc', 'bgcouriers_econt_row_color' => '']);
        $this->assertStringContainsString('rgba(170,187,204,0.13)', $css);
    }

    /** A junk value must not silently tint every such row black. */
    public function test_unusable_values_produce_no_rule(): void {
        foreach (['', 'red', 'rgb(1,2,3)', '#12', '#1234567', 'd63638', '#ggg'] as $bad) {
            $css = $this->css(['bgcouriers_speedy_row_color' => $bad, 'bgcouriers_econt_row_color' => '']);
            $this->assertStringNotContainsString('bgc-courier-speedy', $css, "value: [$bad]");
            $this->assertStringNotContainsString('rgba(0,0,0', $css, "value: [$bad]");
        }
    }

    /** Nothing but digits and commas from the option can reach the CSS. */
    public function test_no_option_text_leaks_into_the_stylesheet(): void {
        $css = $this->css(['bgcouriers_speedy_row_color' => '#fff;}body{display:none', 'bgcouriers_econt_row_color' => '#000']);
        $this->assertStringNotContainsString('display:none', $css);
        $this->assertStringNotContainsString('bgc-courier-speedy', $css);
        $this->assertStringContainsString('rgba(0,0,0,0.13)', $css); // econt's real black still renders
    }

    public function test_master_switch_off_disables_everything(): void {
        $this->assertSame('', $this->css(['bgcouriers_speedy_row_color' => '#d63638'], 'no'));
    }

    /** Falls back to the shipped default when the option was never saved. */
    public function test_default_palette_applies_out_of_the_box(): void {
        $css = $this->css([]); // no options stored at all
        $this->assertStringContainsString('bgc-courier-speedy', $css);
        $this->assertStringContainsString('bgc-courier-econt', $css);
    }

    public function test_defaults_are_distinct_and_valid_hex(): void {
        $c = BGCouriers_Order_Columns::ROW_COLORS;
        $this->assertSame(array_values($c), array_unique(array_values($c)), 'every courier a different colour');
        foreach ($c as $cid => $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $hex, $cid);
        }
    }
}
