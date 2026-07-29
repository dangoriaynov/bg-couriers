<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-checkout.php';

if (!class_exists('BGCouriers_Fake_Rate')) {
    /** Just the bit of WC_Shipping_Rate these filters touch. */
    class BGCouriers_Fake_Rate {
        private string $mid;
        public function __construct(string $mid) { $this->mid = $mid; }
        public function get_method_id(): string { return $this->mid; }
    }
}

/**
 * Rate ids look like "bgcouriers_speedy:12" and the courier id is what follows the prefix.
 *
 * This exists because the offset was once hardcoded as substr($id, 4) - the length of the OLD "bgc_"
 * prefix. Renaming the plugin prefix left the number behind, so every rate resolved to a courier that
 * does not exist, ppp_filter_rates deleted all of them, and the live checkout reported "no shipping
 * methods available". Nothing else caught it: the methods themselves still produced rates.
 *
 * @group core
 */
final class RatePrefixTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** The whole bug in one assertion: a real courier rate must survive the ППП filter. */
    public function test_ppp_filter_keeps_rates_whose_courier_pays_out(): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) {
            if ($n === 'bgcouriers_cod_fiscalization') { return 'ppp'; }
            // Every courier pays out via ППП - so nothing may be removed.
            if (substr($n, -11) === '_ppp_payout') { return 'yes'; }
            return $d;
        });
        // has_prepaid_gateway() walks WC()->payment_gateways()->payment_gateways(); with none enabled it
        // returns false, which is exactly the state that makes ppp_filter_rates do its filtering.
        $gateways = new class { public function payment_gateways(): array { return []; } };
        Functions\when('WC')->justReturn(new class($gateways) {
            private $g;
            public function __construct($g) { $this->g = $g; }
            public function payment_gateways() { return $this->g; }
        });
        $rates = [];
        foreach (['speedy' => 9, 'econt' => 10, 'pigeon' => 11, 'sameday' => 12] as $cid => $inst) {
            $id = 'bgcouriers_' . $cid . ':' . $inst;
            $rates[$id] = new BGCouriers_Fake_Rate('bgcouriers_' . $cid);
        }
        $out = (new BGCouriers_Checkout())->ppp_filter_rates($rates, []);
        $this->assertCount(4, $out, 'the ППП filter must not drop couriers that do pay out');
        $this->assertSame(array_keys($rates), array_keys($out));
    }

    /** Sorting must not lose rates either, whatever the configured order. */
    public function test_sort_rates_keeps_every_rate(): void {
        Functions\when('get_option')->justReturn('');
        $rates = [
            'bgcouriers_sameday:12' => new BGCouriers_Fake_Rate('bgcouriers_sameday'),
            'bgcouriers_speedy:9'   => new BGCouriers_Fake_Rate('bgcouriers_speedy'),
            'other_method:3'        => new BGCouriers_Fake_Rate('other_method'),
        ];
        $out = (new BGCouriers_Checkout())->sort_rates($rates);
        $this->assertCount(3, $out);
        $this->assertArrayHasKey('other_method:3', $out, "another plugin's rate is never dropped");
        $this->assertSame('other_method:3', array_key_last($out), 'foreign rates sort to the end');
    }

    /** The offset must be derived from the prefix, not a number that can go stale again. */
    public function test_prefix_constant_matches_its_declared_length(): void {
        $r = new ReflectionClass('BGCouriers_Checkout');
        $prefix = $r->getConstant('METHOD_PREFIX');
        $len    = $r->getConstant('PREFIX_LEN');
        $this->assertSame('bgcouriers_', $prefix);
        $this->assertSame(strlen($prefix), $len, 'PREFIX_LEN must equal strlen(METHOD_PREFIX)');
    }
}
