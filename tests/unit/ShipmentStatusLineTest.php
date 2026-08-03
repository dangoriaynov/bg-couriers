<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-columns.php';

/**
 * The shipment line in the orders list. It used to appear only once the tracking poll had heard
 * something from the courier - and that poll runs twice a day, so the newest orders (the ones actually
 * being looked at) showed a blank space under their buttons for hours while every older row had a state.
 * A waybill knows its own stage the moment it exists.
 *
 * @group core
 */
final class ShipmentStatusLineTest extends TestCase {
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

    /** Once the courier speaks, its own wording joins the stage. */
    public function test_the_couriers_wording_joins_the_stage(): void {
        $h = BGCouriers_Order_Columns::status_html($this->order([
            '_bgcouriers_track_stage' => 'transit',
            '_bgcouriers_track_text'  => 'Приемане от куриер/служител',
        ]));
        $this->assertStringContainsString('On its way', $h);
        $this->assertStringContainsString('Приемане от куриер', $h);
    }

    /** No waybill, nothing heard: genuinely nothing to say. */
    public function test_an_order_with_no_shipment_shows_nothing(): void {
        $this->assertSame('', BGCouriers_Order_Columns::status_html($this->order([])));
    }

    /** Every stage has a colour of its own - the dot is what makes the list scannable. */
    public function test_each_stage_gets_its_own_colour(): void {
        $seen = [];
        foreach (['registered', 'transit', 'ready', 'delivered', 'returning', 'returned', 'cancelled'] as $stage) {
            $h = BGCouriers_Order_Columns::status_html($this->order(['_bgcouriers_track_stage' => $stage]));
            $this->assertNotSame('', $h, $stage);
            preg_match('/background:(#[0-9a-f]{6})/', $h, $m);
            $this->assertNotEmpty($m, "no colour for {$stage}");
            $seen[$stage] = $m[1];
        }
        $this->assertSame($seen['delivered'], BGCouriers_Order_Columns::STAGE_COLORS['delivered']);
        $this->assertNotSame($seen['delivered'], $seen['returning'], 'delivered and coming back must not look alike');
    }
}
