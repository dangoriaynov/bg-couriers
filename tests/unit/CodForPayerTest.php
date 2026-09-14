<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * service_payer() and cod_for_payer() are protected static on the abstract courier; an abstract subclass
 * can call them through a public wrapper without implementing the whole courier interface, and keeps the
 * test honest that they are protected (reflection would pass even if the signature moved).
 */
abstract class BGC_CodForPayer_Exposer extends BGCouriers_Abstract_Courier {
    public static function payer(string $courier, ?\WC_Order $order): string { return self::service_payer($courier, $order); }
    public static function cod(\WC_Order $order, string $payer): float { return self::cod_for_payer($order, $payer); }
}

/**
 * How much cash the courier collects at the door. This is the money the whole delivery exists to move,
 * and it is decided in two steps every courier shares: service_payer() picks WHO is paying for the
 * delivery, and cod_for_payer() turns that into an amount. Both were untested - the only cash-amount
 * test pinned a dead Speedy::cod_amount() that duplicated the goods-only branch alone.
 *
 * The rule: when the SENDER pays the delivery (the merchant charged shipping at checkout, or is
 * absorbing it as free shipping) the courier collects the full order total; when the RECIPIENT pays it
 * at the door the courier collects the goods only, and the delivery is the courier's own cash on top.
 *
 * @group core
 */
final class CodForPayerTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function order(float $total, float $shipping, float $ship_tax, float $subtotal, string $method = 'office'): WC_Order {
        $o = new WC_Order();
        $o->total = $total; $o->shipping_total = $shipping; $o->shipping_tax = $ship_tax; $o->subtotal = $subtotal;
        $o->meta['_bgcouriers_method'] = $method;
        return $o;
    }

    // ── cod_for_payer(): the amount, given who pays ──────────────────────────

    public function test_the_sender_paying_collects_the_whole_order_total(): void {
        // Delivery was charged with the order, so the total already includes it and the courier collects it all.
        $this->assertSame(25.20, BGC_CodForPayer_Exposer::cod($this->order(25.20, 6.00, 0.20, 19.00), 'sender'));
    }

    public function test_the_recipient_paying_collects_the_goods_only(): void {
        // The recipient hands the courier the delivery in cash on top, so the COD is goods only.
        $this->assertSame(19.00, BGC_CodForPayer_Exposer::cod($this->order(25.20, 6.00, 0.20, 19.00), 'recipient'));
    }

    public function test_the_collected_amount_never_goes_below_zero(): void {
        // A degenerate order where the shipping line exceeds the total must collect 0, not a negative COD.
        $this->assertSame(0.0, BGC_CodForPayer_Exposer::cod($this->order(5.00, 6.00, 0.00, 5.00), 'recipient'));
    }

    // ── service_payer(): who pays, and the amount that follows ───────────────

    public function test_delivery_in_the_order_total_makes_the_sender_pay_the_whole_total(): void {
        Functions\when('get_option')->alias(static fn($n, $d = false) => $n === 'bgcouriers_econt_ship_in_total' ? 'yes' : $d);
        $o = $this->order(25.20, 6.00, 0.20, 19.00);
        $this->assertSame('sender', BGC_CodForPayer_Exposer::payer('econt', $o));
        $this->assertSame(25.20, BGC_CodForPayer_Exposer::cod($o, BGC_CodForPayer_Exposer::payer('econt', $o)));
    }

    public function test_delivery_paid_at_the_door_makes_the_recipient_pay_and_collects_goods_only(): void {
        Functions\when('get_option')->alias(static fn($n, $d = false) => $n === 'bgcouriers_econt_ship_in_total' ? 'no' : $d);
        $o = $this->order(25.20, 6.00, 0.20, 19.00);
        $this->assertSame('recipient', BGC_CodForPayer_Exposer::payer('econt', $o));
        $this->assertSame(19.00, BGC_CodForPayer_Exposer::cod($o, BGC_CodForPayer_Exposer::payer('econt', $o)));
    }

    /**
     * The case that cost real money: free shipping is the MERCHANT absorbing the delivery, so even a
     * courier set to bill the recipient must collect the full total - or the customer is told "free"
     * at checkout and then asked to pay the courier at the door, and the shop absorbs nothing.
     */
    public function test_free_shipping_makes_the_sender_pay_even_when_the_recipient_normally_would(): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) {
            if ($n === 'bgcouriers_econt_free_threshold') { return 40; } // free over 40
            if ($n === 'bgcouriers_econt_ship_in_total')   { return 'no'; } // recipient pays, normally
            return $d;
        });
        // A 50-goods order over the threshold: shipping is free (0), so the total is the goods.
        $o = $this->order(50.00, 0.00, 0.00, 50.00);
        $this->assertSame('sender', BGC_CodForPayer_Exposer::payer('econt', $o), 'free shipping means the merchant absorbs it');
        $this->assertSame(50.00, BGC_CodForPayer_Exposer::cod($o, BGC_CodForPayer_Exposer::payer('econt', $o)));
    }
}
