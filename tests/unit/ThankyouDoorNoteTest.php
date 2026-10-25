<?php
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-thankyou.php';

/**
 * "Paid to the courier" appears once in the thank-you summary: under the shipping row when it carries the price,
 * as a separate note only when it does not.
 *
 * @group core
 */
final class ThankyouDoorNoteTest extends BGCouriers_Unit_TestCase {

    private const NOTE = 'The delivery fee is paid to the courier on delivery.';

    protected function setUp(): void {
        parent::setUp();
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('wp_strip_all_tags')->alias('strip_tags');
        Functions\when('wp_kses_post')->returnArg(1);
        $this->options(['bgcouriers_speedy_ship_in_total' => 'no']);
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function shippingRows(): array {
        return [
            'row carries the door price' => ['Speedy: До офис<br><small class="bgc-door-price">~3,00 € paid to the courier on delivery</small>', 0],
            'row has no price'           => ['Speedy: До офис', 1],
        ];
    }

    /** @dataProvider shippingRows */
    public function test_the_courier_note_is_shown_only_when_the_shipping_row_lacks_it(string $shipping, int $notes): void {
        $order = new class extends WC_Order {
            public array $totals = [];
            public function get_order_item_totals() { return $this->totals; }
        };
        $order->meta['_bgcouriers_courier'] = 'speedy';
        $order->totals = [
            'shipping'    => ['label' => 'Доставка:', 'value' => $shipping],
            'order_total' => ['label' => 'Общо:', 'value' => '7,00 €'],
        ];
        $m = new ReflectionMethod(BGCouriers_Thankyou::class, 'items_block');
        $m->setAccessible(true);
        $html = $m->invoke(null, $order);
        $this->assertSame($notes, substr_count($html, self::NOTE));
    }
}
