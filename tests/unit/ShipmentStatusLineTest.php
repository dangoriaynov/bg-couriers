<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-icons.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-columns.php';

/**
 * The shipment state in the orders list. It used to be a two-line block of the courier's own wording,
 * which is what made every row tall: "Изпратено известие за пратка в офис/автомат" in a narrow column
 * wraps, and a list is meant to be scanned, not read. It is now a single icon in the button row, with
 * the whole sentence one hover away - the same trade the courier logo already makes.
 *
 * @group core
 */
final class ShipmentStatusLineTest extends TestCase {
    private const STAGES = ['registered', 'transit', 'ready', 'delivered', 'returning', 'returned', 'cancelled'];

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        foreach (['esc_html', 'esc_attr', 'esc_html__', 'esc_attr__', 'esc_url'] as $f) {
            Functions\when($f)->alias(static fn($v) => (string) $v);
        }
        Functions\when('sanitize_html_class')->alias(static fn($v) => (string) $v);
        Functions\when('human_time_diff')->justReturn('5 minutes');
        Functions\when('get_option')->justReturn('');
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,mixed> $meta */
    private function order(array $meta): WC_Order {
        $o = new WC_Order();
        $o->meta = $meta;
        return $o;
    }

    /** A label created a minute ago: a stage, no courier wording yet - and it must still show. */
    public function test_a_brand_new_waybill_shows_its_stage(): void {
        $h = BGCouriers_Order_Columns::status_html($this->order([
            '_bgcouriers_track_stage'   => 'registered',
            '_bgcouriers_track_updated' => 1785400000,
        ]));
        $this->assertNotSame('', $h, 'a fresh waybill must not render an empty cell');
        $this->assertStringContainsString('Label created', $h);
    }

    /** Once the courier speaks, its own wording joins the stage - in the hover hint, not in the row. */
    public function test_the_couriers_wording_joins_the_stage(): void {
        $h = BGCouriers_Order_Columns::status_html($this->order([
            '_bgcouriers_track_stage' => 'transit',
            '_bgcouriers_track_text'  => 'Приемане от куриер/служител',
        ]));
        $this->assertStringContainsString('On its way', $h);
        $this->assertStringContainsString('Приемане от куриер', $h);
    }

    /**
     * The wording belongs in the hint ONLY. Left in the row it wrapped to two lines and made every row
     * in the list taller than the buttons beside it - which is the whole reason this became an icon.
     */
    public function test_the_wording_is_in_the_hint_and_not_in_the_row_text(): void {
        $h = BGCouriers_Order_Columns::status_html($this->order([
            '_bgcouriers_track_stage' => 'transit',
            '_bgcouriers_track_text'  => 'Изпратено известие за пратка в офис/автомат',
        ]));
        $tip = [];
        preg_match('/data-tip="([^"]*)"/', $h, $tip);
        $this->assertStringContainsString('Изпратено известие', $tip[1] ?? '', 'the sentence must be in the hint');
        // Nothing outside the tags: the icon carries no text node of its own.
        $this->assertSame('', trim(wp_strip_all_tags_stub($h)), 'the row itself must render no text');
    }

    /** No waybill, nothing heard: genuinely nothing to say. */
    public function test_an_order_with_no_shipment_shows_nothing(): void {
        $this->assertSame('', BGCouriers_Order_Columns::status_html($this->order([])));
    }

    /**
     * Colour by CLASS, never by an inline style. This column is printed through
     * BGCouriers_Kses::admin_actions(), whose <span> takes no style attribute - so the old
     * `style="background:#..."` dot was silently stripped at output and the list had no colour in it at
     * all, while the unit test (which reads the HTML before kses) said it did.
     */
    public function test_the_stage_colour_survives_kses_because_it_is_a_class(): void {
        foreach (self::STAGES as $stage) {
            $h = BGCouriers_Order_Columns::status_html($this->order(['_bgcouriers_track_stage' => $stage]));
            $this->assertStringContainsString('bgc-stage-' . $stage, $h, $stage);
            $this->assertStringNotContainsString('style=', $h, "{$stage}: an inline style would be stripped");
        }
    }

    /** Every stage still HAS a colour, and the stylesheet is generated from those. */
    public function test_every_stage_has_its_own_colour_in_the_generated_css(): void {
        $css = BGCouriers_Order_Columns::stage_color_css();
        foreach (self::STAGES as $stage) {
            $this->assertStringContainsString('.bgc-stage-' . $stage, $css, $stage);
        }
        $this->assertStringContainsString(BGCouriers_Order_Columns::STAGE_COLORS['delivered'], $css);
        $this->assertNotSame(BGCouriers_Order_Columns::STAGE_COLORS['delivered'],
            BGCouriers_Order_Columns::STAGE_COLORS['returning'], 'delivered and coming back must not look alike');
    }

    /** An icon per stage, and a DIFFERENT one each time - one shared glyph would say nothing. */
    public function test_each_stage_draws_its_own_icon(): void {
        $seen = [];
        foreach (self::STAGES as $stage) {
            $svg = BGCouriers_Icons::stage($stage);
            $this->assertStringContainsString('<svg', $svg, $stage);
            $this->assertNotContains($svg, $seen, "{$stage} reuses another stage's glyph");
            $seen[] = $svg;
        }
        $this->assertSame('', BGCouriers_Icons::stage('nonsense'), 'an unknown stage draws nothing');
    }
}

/** Minimal stand-in: the WP function is not loaded in unit tests. */
function wp_strip_all_tags_stub(string $h): string {
    return preg_replace('/<[^>]*>/', '', $h) ?? '';
}
