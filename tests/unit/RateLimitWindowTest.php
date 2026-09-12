<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Checkout/class-bgcouriers-ajax.php';

/**
 * The per-IP budget is a window of TIME, not a window of silence.
 *
 * It used to write the counter back with a fresh sixty-second expiry on every single call, so the
 * budget only ever reset after a whole minute with nothing arriving. A shared address with steady
 * traffic - an office, or a mobile carrier behind CGNAT, which is most phone traffic here - never
 * reaches a quiet minute, so its allowance was never given back until it had been refused long enough
 * to stop asking. Two customers at one office were enough for the second to queue behind the first.
 *
 * The tell is the expiry the counter is stored with: inside one window it must COUNT DOWN. A constant
 * sixty is the bug, and it is the thing this watches.
 *
 * @group core
 */
final class RateLimitWindowTest extends TestCase {
    /** @var array<string,mixed> */
    private array $store = [];
    /** @var int[] every TTL the limiter has asked for, in order */
    private array $ttls = [];

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        $this->store = [];
        $this->ttls  = [];
        $store = &$this->store;
        $ttls  = &$this->ttls;
        Functions\when('get_transient')->alias(static function ($k) use (&$store) { return $store[$k] ?? false; });
        Functions\when('set_transient')->alias(static function ($k, $v, $ttl = 0) use (&$store, &$ttls) {
            $store[$k] = $v; $ttls[] = (int) $ttl; return true;
        });
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_unslash')->returnArg(1);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }
    protected function tearDown(): void { unset($_SERVER['REMOTE_ADDR']); Monkey\tearDown(); parent::tearDown(); }

    /** The limiter is private, and private is right - it is nobody else's business but this class's. */
    private function ask(int $max = 5, int $window = 60): bool {
        $call = \Closure::bind(
            static function (int $max, int $window) { return BGCouriers_Ajax::rate_ok($max, $window); },
            null,
            BGCouriers_Ajax::class
        );
        return $call($max, $window);
    }

    /** The budget itself: spend it, and the next caller is refused. */
    public function test_the_budget_runs_out_and_then_refuses(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->ask(5, 60), "request {$i} is inside the budget");
        }
        $this->assertFalse($this->ask(5, 60), 'the sixth is over it');
        $this->assertFalse($this->ask(5, 60), 'and stays over it');
    }

    /**
     * The window counts down. Every stored expiry must be shorter than the one before it, because the
     * window ends at a fixed moment however many requests arrive inside it. Writing 60 back each time is
     * what made a busy address unable to get its allowance back.
     */
    public function test_the_window_counts_down_instead_of_starting_again(): void {
        for ($i = 0; $i < 5; $i++) { $this->ask(5, 60); }

        $this->assertCount(5, $this->ttls, 'each accepted request stored the counter');
        $this->assertSame(60, $this->ttls[0], 'the first request opens a full window');
        foreach ($this->ttls as $ttl) {
            $this->assertLessThanOrEqual(60, $ttl, 'no request may extend the window past its end');
        }
        // Never longer than the one before it. The old code wrote a full 60 every time, which is the
        // only way this sequence can climb.
        for ($i = 1; $i < count($this->ttls); $i++) {
            $this->assertLessThanOrEqual($this->ttls[$i - 1], $this->ttls[$i],
                'a later request inside the window pushed its end further out');
        }
        // The sharp assertion: the window END is one moment, so every write agrees about when it is.
        $slot = $this->store['bgcouriers_rl_' . md5('203.0.113.7')];
        $this->assertIsArray($slot);
        $this->assertSame(5, $slot['n'], 'five requests counted');
        $this->assertSame(time() + 60, $slot['until'], 'and they all share one end');
    }

    /** A window that has run out is a new window, with the whole budget back. */
    public function test_a_window_that_has_expired_gives_the_budget_back(): void {
        $key = 'bgcouriers_rl_' . md5('203.0.113.7');
        $this->store[$key] = ['n' => 99, 'until' => time() - 1];   // spent, and over

        $this->assertTrue($this->ask(5, 60), 'the new window starts clean');
        $this->assertSame(1, $this->store[$key]['n']);
    }

    /**
     * An upgrade finds whatever the old code left behind - a bare integer. Reinterpreting that as a
     * spent budget would refuse a customer for a minute on the day the plugin updates.
     */
    public function test_the_counter_the_old_code_stored_does_not_lock_anyone_out(): void {
        $key = 'bgcouriers_rl_' . md5('203.0.113.7');
        $this->store[$key] = 9999;   // what the previous version wrote

        $this->assertTrue($this->ask(5, 60), 'an unrecognised value starts a fresh window');
        $this->assertIsArray($this->store[$key]);
        $this->assertSame(1, $this->store[$key]['n']);
    }

    /** Two addresses have two budgets - one busy office must not refuse the rest of the internet. */
    public function test_each_address_has_its_own_budget(): void {
        for ($i = 0; $i < 5; $i++) { $this->ask(5, 60); }
        $this->assertFalse($this->ask(5, 60), 'this address is spent');

        $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
        $this->assertTrue($this->ask(5, 60), 'and the next one is not');
    }
}
