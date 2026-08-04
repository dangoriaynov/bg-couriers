<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/** A courier that simply reports whatever problems the test wants it to. */
if (!class_exists('BGCouriers_Problem_Courier')) {
    class BGCouriers_Problem_Courier implements BGCouriers_Courier_Interface {
        public $problems = [];
        public function __construct(array $p = []) { $this->problems = $p; }
        public function enable_problems(): array { return $this->problems; }
        public function id(): string { return 'boxnow'; }
        public function label(): string { return 'BOX NOW'; }
        public function capabilities(): array { return ['automat']; }
        public function fetch_cities(): array { return []; }
        public function fetch_offices(int $city_id = 0): array { return []; }
        public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('n/a'); }
        public function create_label(\WC_Order $o): BGCouriers_Label { throw new BGCouriers_Api_Exception('n/a'); }
        public function cancel_label(string $w): bool { return false; }
        public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, ''); }
        public function check_credentials(): bool { return true; }
        public function label_formats(): array { return []; }
        public function get_label_pdf(string $w, string $f = ''): string { return ''; }
        public function tracking_url(string $w): string { return ''; }
    }
}

if (!class_exists('BGCouriers_Fake_Gateway')) {
    class BGCouriers_Fake_Gateway { public $enabled; public function __construct($e){ $this->enabled=$e; } }
    class BGCouriers_Fake_Gateways {
        private $g; public function __construct(array $g){ $this->g=$g; }
        public function payment_gateways(){ return $this->g; }
    }
    class BGCouriers_Fake_WC {
        private $g; public function __construct(array $g){ $this->g=new BGCouriers_Fake_Gateways($g); }
        public function payment_gateways(){ return $this->g; }
    }
}

/**
 * Which of two red banners a courier tab shows.
 *
 * "No customer can pick this courier at all" outranks "this courier is missing a setting": the first
 * says nothing will ever reach it, the second is a gap to fill in on a courier that would otherwise
 * work. Reported the other way round, prod showed "No sender phone is set" on a BOX NOW that could not
 * appear at checkout in the first place - true, but not the thing to act on.
 *
 * @group core
 */
final class BlockerPriorityTest extends TestCase {

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $opts */
    private function options(array $opts): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($opts) {
            return array_key_exists($n, $opts) ? $opts[$n] : $d;
        });
    }

    /**
     * ППП mode, the courier does not do ППП, and the shop has no prepaid gateway: it will not appear at
     * checkout. That is what the merchant needs told, even though it is ALSO missing a sender phone.
     */
    public function test_cannot_appear_at_checkout_wins_over_a_missing_setting(): void {
        $this->options([
            'bgcouriers_cod_fiscalization'   => 'ppp',
            'bgcouriers_boxnow_ppp_payout'   => 'no',
            'bgcouriers_boxnow_sender_phone' => '',      // also missing - deliberately
        ]);
        Functions\when('WC')->justReturn(new BGCouriers_Fake_WC([
            'cod' => new BGCouriers_Fake_Gateway('yes'),   // cash on delivery, and nothing else
        ]));

        $n = BGCouriers_Settings::courier_blocker('boxnow');

        $this->assertNotNull($n);
        $this->assertSame('error', $n['level']);
        $this->assertStringNotContainsString('sender phone', $n['msg'],
            'the missing setting is real but it is not what stops this courier working');
    }

    /**
     * With a prepaid gateway present the ППП problem is only a warning - the courier still appears, for
     * prepaid orders. Now the missing setting IS the thing that would break those orders, so it wins.
     */
    public function test_a_missing_setting_wins_when_the_courier_can_still_be_used(): void {
        $this->options([
            'bgcouriers_cod_fiscalization'   => 'ppp',
            'bgcouriers_boxnow_ppp_payout'   => 'no',
            'bgcouriers_boxnow_partner_id'   => '16549',
            'bgcouriers_boxnow_warehouse_id' => '2',
            'bgcouriers_boxnow_flat_price'   => '1.20',
            'bgcouriers_boxnow_username'     => 'id',
            'bgcouriers_boxnow_password'     => 'secret',
            'bgcouriers_boxnow_sender_phone' => '',
        ]);
        Functions\when('WC')->justReturn(new BGCouriers_Fake_WC([
            'cod'  => new BGCouriers_Fake_Gateway('yes'),
            'bacs' => new BGCouriers_Fake_Gateway('yes'),   // bank transfer = prepaid
        ]));
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('boxnow', 'BOX NOW', static function () {
            return new BGCouriers_Problem_Courier([[
                'msg' => 'No sender phone is set.',
                'fix' => 'Enter the merchant contact phone below.',
            ]]);
        });

        $n = BGCouriers_Settings::courier_blocker('boxnow');

        $this->assertNotNull($n);
        $this->assertSame('error', $n['level']);
        $this->assertStringContainsString('sender phone', $n['msg']);
    }
}
