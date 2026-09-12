<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-ajax.php';

/** Stands in for the die() at the end of every wp_send_json_*, so the handler stops where it really would. */
final class BusySent extends \RuntimeException {
    public function __construct(public string $fn, public $body, public int $status) { parent::__construct($fn); }
}

/**
 * A refused request must not look like an answer.
 *
 * The five rate-limited endpoints do not share a shape: offices and streets answer with a LIST, while
 * city_avail, the map lookup and the geocoder answer with an OBJECT. So there is no single body that can
 * be handed to every caller safely, and the first attempt at this - HTTP 200 carrying a bgc_busy flag in
 * an object - was worse than the empty answer it replaced. The office dropdown caches what it is handed
 * and then calls .filter on it; the street dropdown calls .map. An object where a list belongs does not
 * leave those fields empty, it takes them out of the page with a TypeError, and in the order editor that
 * is the merchant's screen rather than a customer's.
 *
 * The status code is what every caller here actually shares: jQuery routes a non-2xx away from the
 * success handler, so $.get(..., fn), .done(fn) and select2's transport all simply do not run. This
 * watches that - a refusal carries a 4xx and never a 200 - because the 200 is the whole defect.
 *
 * @group core
 */
final class BusyAnswerTest extends TestCase {
    /** @var array<string,mixed> */
    private array $store = [];

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        $this->store = [];
        $store = &$this->store;
        Functions\when('get_transient')->alias(static function ($k) use (&$store) { return $store[$k] ?? false; });
        Functions\when('set_transient')->alias(static function ($k, $v, $t = 0) use (&$store) { $store[$k] = $v; return true; });
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('sanitize_key')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('add_action')->justReturn(true);
        // Both of them, so the test can see WHICH one answered and with what.
        Functions\when('wp_send_json')->alias(static function ($b, $s = 200) { throw new BusySent('wp_send_json', $b, (int) $s); });
        Functions\when('wp_send_json_error')->alias(static function ($b, $s = 200) { throw new BusySent('wp_send_json_error', $b, (int) $s); });
        Functions\when('wp_send_json_success')->alias(static function ($b, $s = 200) { throw new BusySent('wp_send_json_success', $b, (int) $s); });
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_GET = [];
    }
    protected function tearDown(): void { $_GET = []; unset($_SERVER['REMOTE_ADDR']); Monkey\tearDown(); parent::tearDown(); }

    /** Spend the whole per-IP budget, so the next request through any handler is refused. */
    private function spend_the_budget(): void {
        $key = 'bgcouriers_rl_' . md5('203.0.113.9');
        $this->store[$key] = ['n' => 999999, 'until' => time() + 60];
    }

    /** Run one handler and report how it answered. */
    private function answer(string $handler): BusySent {
        $ajax = new BGCouriers_Ajax();
        try { $ajax->$handler(); }
        catch (BusySent $e) { return $e; }
        $this->fail("{$handler} answered nothing at all");
    }

    /**
     * @dataProvider rate_limited_handlers
     * The one thing every caller shares: jQuery runs the success handler only for a 2xx. A refusal that
     * comes back 200 is a refusal the browser believes and caches.
     */
    public function test_a_refusal_is_not_a_successful_response(string $handler): void {
        $this->spend_the_budget();
        $sent = $this->answer($handler);

        $this->assertGreaterThanOrEqual(400, $sent->status,
            "{$handler} refused with HTTP {$sent->status}, which jQuery hands to the success handler");
        $this->assertSame(429, $sent->status, 'too many requests is what happened, so say so');
        $this->assertNotSame('wp_send_json', $sent->fn,
            "{$handler} sent a plain answer; a refusal has to be an error, whatever shape the endpoint normally has");
    }

    /** @dataProvider rate_limited_handlers */
    public function test_a_refusal_says_why_for_anyone_reading_the_network_tab(string $handler): void {
        $this->spend_the_budget();
        $this->assertSame(['bgc_busy' => true], $this->answer($handler)->body);
    }

    /** Every endpoint the limiter guards. Miss one and it answers 200 with the wrong shape again. */
    public static function rate_limited_handlers(): array {
        return [
            'offices'        => ['offices'],
            'streets'        => ['streets'],
            'city_avail'     => ['city_avail'],
            'geocode'        => ['geocode'],
            'allmap_offices' => ['allmap_offices'],
            'allmap_prices'  => ['allmap_prices'],
        ];
    }

    /**
     * The negative control. With budget left, none of this happens: the handler gets on with its work
     * and answers whatever it normally answers - so the test above is watching the refusal and not
     * simply the fact that these handlers exit early for some other reason.
     */
    public function test_a_request_inside_the_budget_is_not_refused(): void {
        $_GET = ['courier' => 'speedy', 'city_id' => '0'];   // city 0: city_avail's own early exit
        $sent = $this->answer('city_avail');

        $this->assertSame(200, $sent->status, 'an ordinary request is answered, not refused');
        $this->assertSame('wp_send_json', $sent->fn);
        $this->assertSame(['office' => false, 'automat' => false], $sent->body,
            'and it is the endpoint own shape, which is exactly what a refusal must NOT borrow');
    }
}
