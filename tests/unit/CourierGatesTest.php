<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-couriers.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-encryption.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';

/**
 * The gates between a courier and a customer, and which of them decides what.
 *
 * Switching a courier ON is a DECISION and nothing is allowed to refuse it: the credentials come from
 * the courier, and two of them (Express One's collection address, Европът's sender file) can only be
 * picked off a list its API returns - so a courier that could not be enabled before it was configured
 * could never be configured at all. What withholds it from the checkout is a separate question, asked
 * here rather than at the switch:
 *
 *   switched on -> credentials saved -> credentials validated -> at least one delivery option
 *
 * The zone is WooCommerce's own gate - it never calls a shipping method outside a matching zone - so
 * this plugin only DIAGNOSES that one.
 *
 * @group core
 */
final class CourierGatesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('get_transient')->alias(static function ($k) {
            return strpos((string) $k, 'bgcouriers_typecnt_') === 0
                ? ['office' => 0, 'automat' => 0, 'total' => 0] : false;
        });
        Functions\when('set_transient')->justReturn(true);
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });
    }
    protected function tearDown(): void { BGCouriers_Couriers::reset(); Monkey\tearDown(); parent::tearDown(); }

    private function opts(array $map): void {
        Functions\when('get_option')->alias(static function ($k, $d = false) use ($map) {
            return array_key_exists($k, $map) ? $map[$k] : $d;
        });
    }
    /** Enabled, credentials saved and validated. Delivery options default to on. */
    private function ready(array $extra = []): array {
        return array_merge([
            'bgcouriers_speedy_enabled'   => 'yes',
            'bgcouriers_speedy_username'  => 'u',
            'bgcouriers_speedy_password'  => 'p',
            'bgcouriers_speedy_validated' => 'yes',
        ], $extra);
    }

    // ── Switching one on is never refused ────────────────────────────────────

    public function test_a_courier_with_nothing_configured_still_says_what_is_missing_rather_than_refusing(): void {
        $this->opts(['bgcouriers_speedy_enabled' => 'yes']);
        $problems = (new BGCouriers_Speedy([]))->enable_problems();
        // A list, not a veto - and the credentials are on it, which is the whole point of showing it.
        $this->assertNotEmpty($problems);
        $this->assertSame('creds_missing', $problems[0]['code']);
        // The switch itself stays where the merchant put it.
        $this->assertSame('yes', get_option('bgcouriers_speedy_enabled', 'no'));
    }

    // ── ...but the checkout withholds it until it can actually be used ───────

    public function test_no_credentials_means_no_rate_however_enabled_it_is(): void {
        $this->opts(['bgcouriers_speedy_enabled' => 'yes']);
        $this->assertNull(BGCouriers_Settings::courier_config('speedy'));
        $this->assertFalse(BGCouriers_Settings::courier_offerable('speedy'));
    }

    /**
     * The ✕ beside a credential field marks it as needing re-validation and does NOT clear the value,
     * so creds_present() stays true. Before this gate the courier went on quoting with credentials the
     * shop had just called into question.
     */
    public function test_credentials_marked_invalid_take_the_courier_off_the_checkout(): void {
        $this->opts($this->ready(['bgcouriers_speedy_validated' => 'no']));
        $this->assertTrue(BGCouriers_Settings::creds_present('speedy'), 'the values are still there');
        $this->assertNull(BGCouriers_Settings::courier_config('speedy'), 'and they are not to be used');
    }

    /** Credentials saved before the flag existed count as valid - a legacy install must not go dark. */
    public function test_a_courier_configured_before_the_flag_existed_keeps_working(): void {
        $this->opts([
            'bgcouriers_speedy_enabled'  => 'yes',
            'bgcouriers_speedy_username' => 'u',
            'bgcouriers_speedy_password' => 'p',
        ]);
        $this->assertNotNull(BGCouriers_Settings::courier_config('speedy'));
    }

    public function test_every_delivery_option_off_means_the_courier_is_not_offered(): void {
        // selection_for() falls back to 'office' when nothing is enabled, so without this gate a courier
        // with all three switched off quoted an office delivery anyway. Measured on dev: Express One.
        $this->opts($this->ready([
            'bgcouriers_speedy_office_enabled'  => 'no',
            'bgcouriers_speedy_address_enabled' => 'no',
            'bgcouriers_speedy_automat_enabled' => 'no',
        ]));
        $this->assertSame([], BGCouriers_Settings::enabled_methods('speedy'));
        $this->assertNotNull(BGCouriers_Settings::courier_config('speedy'), 'it is configured...');
        $this->assertFalse(BGCouriers_Settings::courier_offerable('speedy'), '...and still not offerable');
        $codes = array_column((new BGCouriers_Speedy([]))->enable_problems(), 'code');
        $this->assertContains('no_methods', $codes, 'and the tab says so');
    }

    public function test_the_ready_state_is_offerable(): void {
        $this->opts($this->ready());
        $this->assertTrue(BGCouriers_Settings::courier_offerable('speedy'));
        $this->assertSame([], (new BGCouriers_Speedy([]))->enable_problems());
    }

    // ── The zone is WooCommerce's gate; this only reports on it ──────────────

    public function test_a_zone_that_cannot_be_read_is_never_reported_as_missing(): void {
        // WC_Shipping_Zones is absent here, as it is on any request that has not booted WooCommerce's
        // shipping. Guessing "not in a zone" would put a false problem on every tab.
        $this->assertTrue(BGCouriers_Settings::in_a_shipping_zone('speedy'));
        $this->opts($this->ready());
        $this->assertNotContains('no_zone', array_column((new BGCouriers_Speedy([]))->enable_problems(), 'code'));
    }
}
