<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * Free delivery and "who pays the courier" are the same decision seen from two sides, and they were
 * decided in two places that never met: an order over the free-shipping threshold was charged nothing at
 * checkout (correct) and then handed to the courier as recipient-paid (wrong) - so the customer was
 * promised free delivery and asked to pay for it at the door. Order 11204: goods 54.00, threshold 40.
 *
 * Every courier, every delivery option, both sides of the threshold, both payer settings.
 *
 * @group core
 */
final class FreeShippingPayerMatrixTest extends TestCase {
    private const COURIERS = ['speedy', 'econt', 'pigeon', 'sameday', 'boxnow'];
    private const METHODS  = ['office', 'address', 'automat'];

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,mixed> $opts */
    private function options(array $opts): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($opts) {
            return array_key_exists($n, $opts) ? $opts[$n] : $d;
        });
    }

    private function order(string $method, float $goods): WC_Order {
        $o = new WC_Order();
        $o->meta     = ['_bgcouriers_method' => $method];
        $o->subtotal = $goods;
        $o->total    = $goods;
        return $o;
    }

    /**
     * The whole matrix. A qualifying order is the SHOP's cost whatever the courier is set to; a
     * non-qualifying one follows the setting.
     */
    public function test_free_shipping_beats_recipient_pays_for_every_courier_and_option(): void {
        foreach (self::COURIERS as $courier) {
            foreach (self::METHODS as $method) {
                foreach (['yes' => 'sender', 'no' => 'recipient'] as $in_total => $payer_when_charged) {
                    $opts = [
                        'bgcouriers_' . $courier . '_ship_in_total'                  => $in_total,
                        'bgcouriers_' . $courier . '_' . $method . '_free_threshold' => '40',
                    ];
                    $this->options($opts);

                    // BOX NOW cannot bill the recipient at all - its API has no field for it.
                    $expected_charged = $courier === 'boxnow' ? 'sender' : $payer_when_charged;
                    $where = "{$courier}/{$method}/ship_in_total={$in_total}";

                    $under = $this->order($method, 39.99);
                    $this->assertFalse(BGCouriers_Settings::free_for_order($under, $courier), "under: {$where}");
                    $this->assertSame($expected_charged, BGCouriers_Payer_Probe2::payer_of($courier, $under), "under: {$where}");

                    foreach ([40.00, 54.00] as $goods) {
                        $over = $this->order($method, $goods);
                        $this->assertTrue(BGCouriers_Settings::free_for_order($over, $courier), "over {$goods}: {$where}");
                        $this->assertSame('sender', BGCouriers_Payer_Probe2::payer_of($courier, $over),
                            "a free delivery is the shop's own cost: {$where}");
                    }
                }
            }
        }
    }

    /** A courier-level threshold covers every delivery option, whatever the per-option fields say. */
    public function test_a_courier_level_threshold_applies_to_every_option(): void {
        foreach (self::COURIERS as $courier) {
            foreach (self::METHODS as $method) {
                $this->options([
                    'bgcouriers_' . $courier . '_free_threshold'    => '25',
                    'bgcouriers_' . $courier . '_ship_in_total'     => 'no',
                ]);
                $this->assertTrue(BGCouriers_Settings::free_for_order($this->order($method, 30.0), $courier), "{$courier}/{$method}");
                $this->assertSame('sender', BGCouriers_Payer_Probe2::payer_of($courier, $this->order($method, 30.0)));
            }
        }
    }

    /** No threshold set anywhere: nothing is free, and the payer setting decides alone. */
    public function test_without_a_threshold_nothing_is_free(): void {
        foreach (self::COURIERS as $courier) {
            $this->options(['bgcouriers_' . $courier . '_ship_in_total' => 'no']);
            $o = $this->order('office', 10000.0);
            $this->assertFalse(BGCouriers_Settings::free_for_order($o, $courier), $courier);
            $this->assertSame($courier === 'boxnow' ? 'sender' : 'recipient', BGCouriers_Payer_Probe2::payer_of($courier, $o), $courier);
        }
    }

    /** The delivery charge itself must never push an order over the threshold. */
    public function test_only_the_goods_count_towards_the_threshold(): void {
        $this->options(['bgcouriers_speedy_office_free_threshold' => '40', 'bgcouriers_speedy_ship_in_total' => 'yes']);
        $o = $this->order('office', 36.0);
        $o->total = 42.0; // 36 goods + 6 delivery
        $this->assertFalse(BGCouriers_Settings::free_for_order($o, 'speedy'));
    }
}

/** Exposes the protected payer rule, which is what the matrix is really about. */
final class BGCouriers_Payer_Probe2 extends BGCouriers_Abstract_Courier {
    public static function payer_of(string $courier, ?WC_Order $order = null): string { return self::service_payer($courier, $order); }
    public function id(): string { return 'probe2'; }
    public function label(): string { return 'Probe2'; }
    public function capabilities(): array { return []; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $city_id): array { return []; }
    public function quote(array $shipment): BGCouriers_Quote { throw new \RuntimeException('n/a'); }
    public function create_label(\WC_Order $order): BGCouriers_Label { throw new \RuntimeException('n/a'); }
    public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
    public function track(string $waybill): BGCouriers_Tracking { throw new \RuntimeException('n/a'); }
    public function tracking_url(string $waybill): string { return ''; }
    public function cancel_label(string $waybill): bool { return false; }
    public function check_credentials(): bool { return true; }
}
