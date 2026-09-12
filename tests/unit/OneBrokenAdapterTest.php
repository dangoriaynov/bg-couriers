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
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-tracking-poller.php';
require_once dirname(__DIR__, 2) . '/includes/Shipping/class-bgcouriers-pricing.php';

/**
 * A courier whose adapter is BROKEN - not one whose API is down. The difference is the class of what it
 * throws: an API that refuses throws an Exception, which everything here catches; an adapter that hits
 * a TypeError on an answer it did not expect throws an Error, which most of it did not.
 */
if (!class_exists('BrokenAdapter')) {
    final class BrokenAdapter implements BGCouriers_Courier_Interface {
        public int $asked = 0;
        public function __construct(private string $cid, private bool $broken) {}
        public function track(string $w): BGCouriers_Tracking {
            $this->asked++;
            if ($this->broken) { throw new \TypeError('count(): Argument #1 must be of type Countable|array, null given'); }
            return new BGCouriers_Tracking($w, 'Приета', [['code' => '1', 'name' => 'a', 'date' => '']], '', null, true);
        }
        public function quote(array $s): BGCouriers_Quote {
            $this->asked++;
            if ($this->broken) { throw new \TypeError('strlen(): Argument #1 must be of type string, array given'); }
            return new BGCouriers_Quote(4.44, 0.0, 'EUR', 'live');
        }
        public function id(): string { return $this->cid; }
        public function label(): string { return $this->cid; }
        public function capabilities(): array { return ['office', 'live_quote']; }
        public function check_credentials(): bool { return true; }
        public function fetch_cities(): array { return []; }
        public function fetch_offices(int $c): array { return []; }
        public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
        public function label_formats(): array { return []; }
        public function get_label_pdf(string $w, string $f = ''): string { return ''; }
        public function cancel_label(string $w): bool { return true; }
        public function tracking_url(string $w): string { return ''; }
    }
}

/**
 * One broken courier adapter must not take the others down with it.
 *
 * Seven adapters parse seven APIs' JSON, and an answer shaped differently from what an adapter expects
 * is a TypeError, not an Exception. The plugin already learnt this once: the office lookup and the
 * settings screen catch \Throwable, each with a note about the 500 that taught them to. The places
 * that had not learnt it were the ones nobody is watching:
 *
 *  - the tracking cron polls forty orders, oldest first. An Error from the first order's adapter
 *    escaped the loop, and every order after it went unpolled - every courier's, not just the broken
 *    one's. Since that order never gets marked finished it sits at the front of the batch on every
 *    run, so tracking is dead for the whole shop until somebody notices it has gone quiet.
 *  - the checkout quote: an Error from an adapter's quote() walked out of the pricing layer, past the
 *    fallback price that sits right below the live call, and fatalled the checkout page.
 *
 * @group core
 */
final class OneBrokenAdapterTest extends TestCase {
    /** @var array<string,mixed> */
    private array $store = [];

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }   // run()'s age bound
        $this->store = [];
        $store = &$this->store;
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        Functions\when('get_transient')->alias(static function ($k) use (&$store) { return $store[$k] ?? false; });
        Functions\when('set_transient')->alias(static function ($k, $v, $ttl = 0) use (&$store) { $store[$k] = $v; return true; });
        Functions\when('delete_transient')->alias(static function ($k) use (&$store) { unset($store[$k]); return true; });
        Functions\when('apply_filters')->returnArg(2);
        BGCouriers_Couriers::reset();
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    /** An order with a waybill, as the poller finds one. */
    private function order(string $courier): WC_Order {
        $o = new WC_Order();
        $o->meta = ['_bgcouriers_courier' => $courier, '_bgcouriers_waybill' => 'W-' . $courier];
        return $o;
    }

    /** The batch: a broken courier's order first, a healthy courier's order behind it. */
    public function test_the_order_behind_a_broken_adapter_is_still_polled(): void {
        Functions\when('get_option')->alias(static function ($n, $d = '') { return $n === 'bgcouriers_tracking_poll' ? 'hourly' : $d; });
        $broken  = new BrokenAdapter('broken', true);
        $healthy = new BrokenAdapter('healthy', false);
        BGCouriers_Couriers::register('broken', 'Broken', static function () use ($broken) { return $broken; });
        BGCouriers_Couriers::register('healthy', 'Healthy', static function () use ($healthy) { return $healthy; });
        $first = $this->order('broken'); $second = $this->order('healthy');
        Functions\when('wc_get_orders')->justReturn([$first, $second]);

        BGCouriers_Tracking_Poller::run();

        $this->assertSame(1, $broken->asked, 'the broken one was asked, and threw');
        $this->assertSame(1, $healthy->asked, 'the healthy one behind it was still asked');
        $this->assertSame('Приета', $second->meta['_bgcouriers_track_text'] ?? '', 'and its order was updated');
        $this->assertArrayNotHasKey('_bgcouriers_track_text', $first->meta, 'nothing was written for the one that threw');
    }

    /** The checkout: a broken adapter is a fallback price, not a fatal. */
    public function test_a_broken_quote_falls_back_instead_of_fatalling_the_checkout(): void {
        Functions\when('get_option')->alias(static function ($n, $d = '') {
            if (strpos((string) $n, '_price_mode') !== false) { return 'fallback'; }
            if (strpos((string) $n, '_price') !== false) { return '6.50'; }
            return $d;
        });
        $q = BGCouriers_Pricing::quote(new BrokenAdapter('broken', true),
            ['method' => 'office', 'country' => 'BG', 'weight_kg' => 1.0, 'currency' => 'EUR']);

        $this->assertEqualsWithDelta(6.50, $q->price, 0.001, 'the configured fallback, exactly as for an API that is down');
        $this->assertSame('fixed', $q->source);
    }

    /**
     * And it is treated as what it is - an instant failure, not a slow one. A TypeError comes back in
     * microseconds; it must not put the courier to rest the way a hanging API does, because the next
     * customer's basket may be one the adapter parses fine.
     */
    public function test_a_broken_adapter_is_not_mistaken_for_a_slow_one(): void {
        Functions\when('get_option')->alias(static function ($n, $d = '') {
            if (strpos((string) $n, '_price_mode') !== false) { return 'fallback'; }
            if (strpos((string) $n, '_price') !== false) { return '6.50'; }
            return $d;
        });
        $c = new BrokenAdapter('broken', true);
        BGCouriers_Pricing::quote($c, ['method' => 'office', 'country' => 'BG', 'weight_kg' => 1.0, 'currency' => 'EUR']);
        BGCouriers_Pricing::quote($c, ['method' => 'office', 'country' => 'BG', 'weight_kg' => 1.0, 'currency' => 'EUR']);

        $this->assertSame(2, $c->asked, 'asked again next time, like any instant refusal');
        $this->assertArrayNotHasKey('bgcouriers_slow_broken', $this->store);
    }
}
