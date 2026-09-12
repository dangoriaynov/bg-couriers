<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';

/** A courier whose quote() can be made to answer, to refuse at once, or to refuse slowly. */
if (!class_exists('SlowQuoteCourier')) {
    final class SlowQuoteCourier implements BGCouriers_Courier_Interface {
        public int $asked = 0;
        /** @param float $delay seconds to burn before refusing; 0 with $fail=false means answer normally. */
        public function __construct(public bool $fail = true, public float $delay = 0.0) {}
        public function quote(array $s): BGCouriers_Quote {
            $this->asked++;
            if ($this->delay > 0) { usleep((int) ($this->delay * 1000000)); }
            if ($this->fail) { throw new BGCouriers_Api_Exception('the courier did not answer'); }
            return new BGCouriers_Quote(4.44, 0.0, 'EUR', 'live');
        }
        public function id(): string { return 'slowfake'; }
        public function label(): string { return 'Slow Fake'; }
        public function capabilities(): array { return ['office', 'live_quote']; }
        public function check_credentials(): bool { return true; }
        public function fetch_cities(): array { return []; }
        public function fetch_offices(int $c): array { return []; }
        public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
        public function label_formats(): array { return []; }
        public function get_label_pdf(string $w, string $f = ''): string { return ''; }
        public function cancel_label(string $w): bool { return true; }
        public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('', '', []); }
        public function tracking_url(string $w): string { return ''; }
    }
}

/**
 * A courier whose API is hanging must not hang the shop.
 *
 * Rates are calculated one after another inside the request that renders the checkout, and a quote POST
 * waits 20 seconds and then retries - so one sick courier costs every customer forty seconds, every
 * time, until somebody notices. There is a configured fallback price sitting right below the live call;
 * the only thing standing between the customer and it is the wait.
 *
 * So a failure that TOOK ITS TIME puts that courier to rest for a few minutes: the next customer is
 * served the fallback straight away. A failure that came back instantly does not - a courier that
 * refuses this destination in a hundred milliseconds has cost nobody anything, and the next basket may
 * get a different answer.
 *
 * @group core
 */
final class SlowCourierRestsTest extends TestCase {
    /** @var array<string,mixed> the transient store this test runs against */
    private array $store = [];

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        $this->store = [];
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        $store = &$this->store;
        Functions\when('get_transient')->alias(static function ($k) use (&$store) { return $store[$k] ?? false; });
        Functions\when('set_transient')->alias(static function ($k, $v, $ttl = 0) use (&$store) { $store[$k] = $v; return true; });
        Functions\when('delete_transient')->alias(static function ($k) use (&$store) { unset($store[$k]); return true; });
        // 'fallback' mode: ask the API first, and when it will not answer use the merchant's own price.
        // That is the number the customer gets instead of a wait, and it is what makes resting safe.
        Functions\when('get_option')->alias(static function ($n, $d = '') {
            if (strpos((string) $n, '_price_mode') !== false) { return 'fallback'; }
            if (strpos((string) $n, '_price') !== false) { return '7.50'; }
            return $d;
        });
        // The real threshold is five seconds; sleeping that four times over would make the suite that
        // gates every release twenty seconds slower for nothing. The filter is the same one a shop on a
        // slow line to a courier would use.
        Functions\when('apply_filters')->alias(static function ($hook, $value = null) {
            return $hook === 'bgcouriers_slow_quote_seconds' ? self::SLOW : $value;
        });
    }
    /** What counts as slow, for this test. */
    private const SLOW = 0.05;
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function shipment(): array {
        return ['method' => 'office', 'country' => 'BG', 'weight_kg' => 1.0, 'currency' => 'EUR'];
    }

    /** The harm this is about: a slow failure, and then the next customer is not made to wait too. */
    public function test_a_slow_failure_puts_the_courier_to_rest(): void {
        $c = new SlowQuoteCourier(true, self::SLOW + 0.02);

        $first = BGCouriers_Pricing::quote($c, $this->shipment());
        $this->assertSame(1, $c->asked, 'the first customer did ask');
        $this->assertGreaterThan(0.0, $first->price, 'and was still given a price');

        $second = BGCouriers_Pricing::quote($c, $this->shipment());
        $this->assertSame(1, $c->asked, 'the second customer was not made to wait for the same silence');
        $this->assertSame($first->price, $second->price, 'and got the same fallback price');
    }

    /**
     * A refusal that comes back at once is not a sick API - it is an answer. Asking again is free, and
     * the next basket, town or weight may well be one this courier does take.
     */
    public function test_an_instant_refusal_does_not_stop_the_next_customer_asking(): void {
        $c = new SlowQuoteCourier(true, 0.0);

        BGCouriers_Pricing::quote($c, $this->shipment());
        BGCouriers_Pricing::quote($c, $this->shipment());
        BGCouriers_Pricing::quote($c, $this->shipment());

        $this->assertSame(3, $c->asked, 'a courier that refuses instantly keeps being asked');
    }

    /** And a courier that answers is never put to rest, however long it took to answer. */
    public function test_a_slow_but_successful_quote_is_not_a_reason_to_stop_asking(): void {
        $c = new SlowQuoteCourier(false, self::SLOW + 0.02);

        $q = BGCouriers_Pricing::quote($c, $this->shipment());
        $this->assertSame('live', $q->source);
        BGCouriers_Pricing::quote($c, $this->shipment());
        $this->assertSame(2, $c->asked, 'it gave a real price, so it is still the best source of one');
    }

    /** A courier that comes back is asked again straight away, not after the rest has run out. */
    public function test_a_courier_that_recovers_is_woken_by_its_own_answer(): void {
        $slow = new SlowQuoteCourier(true, self::SLOW + 0.02);
        BGCouriers_Pricing::quote($slow, $this->shipment());
        $this->assertArrayHasKey('bgcouriers_slow_slowfake', $this->store, 'it is resting');

        // The merchant fixes whatever it was; something else asks for a quote and gets one.
        unset($this->store['bgcouriers_slow_slowfake']);
        $well = new SlowQuoteCourier(false, 0.0);
        BGCouriers_Pricing::quote($well, $this->shipment());
        $this->assertArrayNotHasKey('bgcouriers_slow_slowfake', $this->store,
            'an answer clears the rest, so nothing is skipped for the remaining minutes');
    }

    /**
     * Abroad there is no fallback to serve - every price below the live call was set or measured for a
     * domestic parcel - so a foreign quote always asks, and the failure is passed on rather than
     * answered with a domestic number wearing a foreign label.
     */
    public function test_a_foreign_quote_always_asks_even_while_the_courier_rests(): void {
        Functions\when('apply_filters')->alias(static function ($hook, $value = null) {
            if ($hook === 'bgcouriers_intl_enabled') { return true; }
            return $hook === 'bgcouriers_slow_quote_seconds' ? self::SLOW : $value;
        });
        $c = new SlowQuoteCourier(true, self::SLOW + 0.02);
        $this->store['bgcouriers_slow_slowfake'] = 1;   // already resting

        $threw = false;
        try { BGCouriers_Pricing::quote($c, ['method' => 'office', 'country' => 'RO', 'weight_kg' => 1.0, 'currency' => 'EUR']); }
        catch (\Exception $e) { $threw = true; }

        $this->assertSame(1, $c->asked, 'a foreign destination is asked about however the courier has been behaving');
        $this->assertTrue($threw, 'and a foreign failure is still passed on, never answered with a domestic price');
    }
}
