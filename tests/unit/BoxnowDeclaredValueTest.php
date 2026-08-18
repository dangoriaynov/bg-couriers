<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/tests/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-phone.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';

/**
 * What we declare a prepaid parcel is worth is the merchant's money, not our assumption.
 *
 * BOX NOW's own plugin sends "0" for a prepaid shipment (box-now-delivery.php:687); we sent the order
 * total on every one. Nothing published says whether the field is priced or what it covers - their
 * /api/v1/openapi.json and /api/v1/docs answer 404 even with a valid token - so it follows the house
 * rule for anything a courier may charge for: off unless the merchant asks for it. A cash-on-delivery
 * parcel is not a choice: it carries the amount being collected.
 *
 * @group boxnow
 */
final class BoxnowDeclaredValueTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function body(string $payment_method, string $declare): array {
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('get_bloginfo')->justReturn('Shop');
        Functions\when('wc_get_price_decimals')->justReturn(2);
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($declare) {
            $map = [
                'bgcouriers_boxnow_declare_value' => $declare,
                'bgcouriers_boxnow_sender_phone'  => '+359888123456',
                'bgcouriers_boxnow_allow_returns' => 'no',
                'admin_email'                     => 'shop@example.com',
            ];
            return $map[$n] ?? $d;
        });
        $order = new WC_Order();
        $order->total          = 49.00;
        $order->payment_method = $payment_method;
        $order->phone          = '0888123456';
        return BGCouriers_Boxnow::build_delivery_request($order, 'WH1');
    }

    public function test_a_prepaid_parcel_declares_nothing_by_default(): void {
        $b = $this->body('bacs', 'no');
        $this->assertSame('0.00', $b['invoiceValue']);
        $this->assertSame('0.00', $b['amountToBeCollected']);
        $this->assertSame('prepaid', $b['paymentMode']);
    }

    public function test_the_merchant_can_ask_for_the_value_to_be_declared(): void {
        $b = $this->body('bacs', 'yes');
        $this->assertNotSame('0.00', $b['invoiceValue']);
    }

    /** Cash on delivery is never a choice - the parcel carries what is being collected. */
    public function test_a_cod_parcel_always_carries_its_amount(): void {
        foreach (['no', 'yes'] as $declare) {
            $b = $this->body('cod', $declare);
            $this->assertSame($b['amountToBeCollected'], $b['invoiceValue']);
            $this->assertSame('cod', $b['paymentMode']);
        }
    }
}
