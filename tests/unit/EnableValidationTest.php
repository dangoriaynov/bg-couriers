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
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-econt.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-boxnow.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-sameday.php';

/**
 * Per-courier "can this courier be enabled" rules - crucial settings must be present + credentials
 * validated, and each courier adds its own required fields.
 *
 * @group core
 */
final class EnableValidationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        // The readiness list now asks whether any delivery option is left on, which reaches the synced
        // office counts behind a transient. Nothing here has a database; "not cached" is the answer that
        // makes available_methods() fall back to the courier's declared capabilities.
        Functions\when('get_transient')->alias(static function ($k) {
            // "Nothing synced", answered from the cache so nothing reaches for a database that is not
            // there. total = 0 is what makes available_methods() fall back to declared capabilities.
            return strpos((string) $k, 'bgcouriers_typecnt_') === 0
                ? ['office' => 0, 'automat' => 0, 'total' => 0] : false;
        });
        Functions\when('set_transient')->justReturn(true);
    }
    protected function tearDown(): void {
        // A registration that outlives its test poisons the next FILE, not just the next test: the
        // registry is static, and enabled_methods() only prunes when it finds a courier in it.
        BGCouriers_Couriers::reset();
        Monkey\tearDown(); parent::tearDown();
    }

    private function opts(array $map): void {
        Functions\when('get_option')->alias(function ($k, $d = false) use ($map) {
            return array_key_exists($k, $map) ? $map[$k] : $d;
        });
    }
    /** enabled + credentials saved + validated - the base "ready" state. */
    private function ok(string $c): array {
        return ["bgcouriers_{$c}_enabled" => 'yes', "bgcouriers_{$c}_username" => 'u', "bgcouriers_{$c}_password" => 'p', "bgcouriers_{$c}_validated" => 'yes'];
    }

    /**
     * The first-install deadlock, and the one bug in this file that a real merchant hit rather than a
     * test: saving a username sets _validated = no, a courier may not be ENABLED until that is yes, and
     * validating it used to require the courier to be enabled - because one function answered both
     * "what are its credentials" and "is the shop offering it". The answer to the second must not
     * withhold the first.
     */
    public function test_credentials_are_readable_while_the_courier_is_switched_off(): void {
        $this->opts([
            'bgcouriers_speedy_enabled'  => 'no',
            'bgcouriers_speedy_username' => 'u',
            'bgcouriers_speedy_password' => 'p',
        ]);
        // courier_credentials() answers only for a REGISTERED courier, so register one here.
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });

        // "Is the shop offering this courier?" - no, and that stays true.
        $this->assertNull(BGCouriers_Settings::courier_config('speedy'));
        // "What does it need to reach its API?" - a different question, with an answer.
        $creds = BGCouriers_Settings::courier_credentials('speedy');
        $this->assertIsArray($creds);
        $this->assertSame('u', $creds['username']);
        // ...and the credentials themselves are not in doubt, so nothing may claim they are missing.
        $this->assertTrue(BGCouriers_Settings::creds_present('speedy'));
        BGCouriers_Couriers::reset();
    }

    /** Being switched off is not a credentials problem, and must never be reported as one. */
    public function test_switched_off_courier_reports_no_credentials_problem(): void {
        $this->opts([
            'bgcouriers_speedy_enabled'   => 'no',
            'bgcouriers_speedy_username'  => 'u',
            'bgcouriers_speedy_password'  => 'p',
            'bgcouriers_speedy_validated' => 'yes',
        ]);
        $this->assertSame([], (new BGCouriers_Speedy([]))->enable_problems());
    }

    /** The unvalidated problem is tagged, so the enable check can replace it with what actually happened. */
    public function test_unvalidated_problem_carries_its_code(): void {
        $this->opts([
            'bgcouriers_speedy_enabled'   => 'yes',
            'bgcouriers_speedy_username'  => 'u',
            'bgcouriers_speedy_password'  => 'p',
            'bgcouriers_speedy_validated' => 'no',
        ]);
        $problems = (new BGCouriers_Speedy([]))->enable_problems();
        $this->assertCount(1, $problems);
        $this->assertSame('creds_unvalidated', $problems[0]['code']);
    }

    /**
     * The whole setup sequence, walked from an install where NOTHING is configured.
     *
     * This is the test that was missing, and its absence is why a deadlock reached the directory: every
     * check ever run on this plugin - unit, e2e, by hand - started from a fully configured site, where
     * `_validated` defaults to yes and the trap cannot spring. A merchant starts from nothing, and each
     * step below is a state he really passes through.
     */
    public function test_the_setup_sequence_from_an_empty_install(): void {
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });

        // 1. Fresh install: nothing saved. The only complaint may be that there are no credentials.
        $this->opts([]);
        $problems = (new BGCouriers_Speedy([]))->enable_problems();
        $this->assertCount(1, $problems);
        $this->assertSame('creds_missing', $problems[0]['code']);

        // 2. Credentials typed and saved. Saving a new username sets _validated = no - and THIS is the
        //    state the merchant is in when he reaches for the check button.
        $saved = [
            'bgcouriers_speedy_enabled'   => 'no',
            'bgcouriers_speedy_username'  => 'u',
            'bgcouriers_speedy_password'  => 'p',
            'bgcouriers_speedy_validated' => 'no',
        ];
        $this->opts($saved);
        // The credentials must be reachable HERE, with the courier still off. Everything else follows
        // from this one property: the check runs, so the flag can become yes, so it can be enabled.
        $creds = BGCouriers_Settings::courier_credentials('speedy');
        $this->assertSame('u', $creds['username']);
        $this->assertTrue(BGCouriers_Settings::creds_present('speedy'));
        $this->assertNull(BGCouriers_Settings::courier_config('speedy'), 'it is still switched off');
        $problems = (new BGCouriers_Speedy([]))->enable_problems();
        $this->assertSame('creds_unvalidated', $problems[0]['code']);

        // 3. The check has run and passed - which is what enabling now does for the merchant.
        $this->opts(array_merge($saved, ['bgcouriers_speedy_validated' => 'yes']));
        $this->assertSame([], (new BGCouriers_Speedy([]))->enable_problems(), 'nothing left blocking');

        // 4. Switched on: now, and only now, does it count as a courier the shop offers.
        $this->opts(array_merge($saved, ['bgcouriers_speedy_validated' => 'yes', 'bgcouriers_speedy_enabled' => 'yes']));
        $this->assertNotNull(BGCouriers_Settings::courier_config('speedy'));
        BGCouriers_Couriers::reset();
    }

    public function test_missing_credentials_blocks(): void {
        $this->opts([]);
        $this->assertNotEmpty((new BGCouriers_Speedy([]))->enable_problems());
    }

    public function test_unvalidated_credentials_block(): void {
        $this->opts(['bgcouriers_speedy_enabled' => 'yes', 'bgcouriers_speedy_username' => 'u', 'bgcouriers_speedy_password' => 'p', 'bgcouriers_speedy_validated' => 'no']);
        $this->assertNotEmpty((new BGCouriers_Speedy([]))->enable_problems());
    }

    public function test_valid_speedy_passes(): void {
        $this->opts($this->ok('speedy'));
        $this->assertEmpty((new BGCouriers_Speedy([]))->enable_problems());
    }

    public function test_econt_cod_without_agreement_blocks(): void {
        $this->opts($this->ok('econt') + ['bgcouriers_econt_cod_enabled' => 'yes', 'bgcouriers_econt_cd_num' => '']);
        $this->assertNotEmpty((new BGCouriers_Econt([]))->enable_problems());
    }

    public function test_econt_cod_with_agreement_passes(): void {
        $this->opts($this->ok('econt') + ['bgcouriers_econt_cod_enabled' => 'yes', 'bgcouriers_econt_cd_num' => 'CD139925']);
        // The chosen agreement is also checked against how the shop says it is paid out, which reads the
        // Econt profile through a transient. Nothing cached and no API here: the check bows out quietly.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        $this->assertEmpty((new BGCouriers_Econt([]))->enable_problems());
    }

    /**
     * Which agreement is the right one is the MERCHANT's arrangement with Econt, not ours to judge.
     * Econt's moneyTransfer flag does not map onto it the way it reads: CD139925 is marked
     * moneyTransfer=false and is exactly what this shop selects in Econt's own UI. Reading that as
     * "not a ППП" put a red error on a correctly configured shop, so only existence is checked.
     */
    public function test_econt_does_not_second_guess_which_agreement_is_right(): void {
        $this->opts($this->ok('econt') + [
            'bgcouriers_econt_cod_enabled' => 'yes',
            'bgcouriers_econt_cd_num'      => 'CD139925',
            'bgcouriers_econt_ppp_payout'  => 'yes',
        ]);
        Functions\when('get_transient')->justReturn([
            ['num' => 'CD139879', 'moneyTransfer' => true,  'method' => 'office', 'officeCode' => '1170'],
            ['num' => 'CD139925', 'moneyTransfer' => false, 'method' => 'bank',   'IBAN' => 'BG68...'],
        ]);
        Functions\when('set_transient')->justReturn(true);
        $this->assertEmpty((new BGCouriers_Econt([]))->enable_problems());
    }

    /** An agreement that has since been removed from the profile must be reported, not used silently. */
    public function test_econt_flags_an_agreement_that_no_longer_exists(): void {
        $this->opts($this->ok('econt') + [
            'bgcouriers_econt_cod_enabled' => 'yes',
            'bgcouriers_econt_cd_num'      => 'CD000000',
        ]);
        Functions\when('get_transient')->justReturn([['num' => 'CD139925', 'moneyTransfer' => false, 'method' => 'bank']]);
        Functions\when('set_transient')->justReturn(true);
        $this->assertNotEmpty((new BGCouriers_Econt([]))->enable_problems());
    }

    public function test_boxnow_missing_warehouse_blocks(): void {
        $this->opts($this->ok('boxnow') + ['bgcouriers_boxnow_partner_id' => '11', 'bgcouriers_boxnow_warehouse_id' => '', 'bgcouriers_boxnow_flat_price' => '3.99']);
        $this->assertNotEmpty((new BGCouriers_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    public function test_boxnow_missing_flat_price_blocks(): void {
        $this->opts($this->ok('boxnow') + ['bgcouriers_boxnow_partner_id' => '11', 'bgcouriers_boxnow_warehouse_id' => '2', 'bgcouriers_boxnow_flat_price' => '0']);
        $this->assertNotEmpty((new BGCouriers_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    public function test_boxnow_complete_passes(): void {
        $this->opts($this->ok('boxnow') + ['bgcouriers_boxnow_partner_id' => '11', 'bgcouriers_boxnow_warehouse_id' => '2',
            'bgcouriers_boxnow_flat_price' => '3.99', 'bgcouriers_boxnow_sender_phone' => '0888123456']);
        $this->assertEmpty((new BGCouriers_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    /**
     * BOX NOW rejects every shipment that carries no sender phone, and says so only as {"code":"P405"}
     * at label time - one order at a time, with nothing naming the field. It has to be caught here.
     */
    public function test_boxnow_missing_sender_phone_blocks(): void {
        $this->opts($this->ok('boxnow') + ['bgcouriers_boxnow_partner_id' => '11', 'bgcouriers_boxnow_warehouse_id' => '2',
            'bgcouriers_boxnow_flat_price' => '3.99', 'bgcouriers_boxnow_sender_phone' => '']);
        $this->assertNotEmpty((new BGCouriers_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    /** A number that cannot be made into E.164 is no better than none - BOX NOW refuses it just the same. */
    public function test_boxnow_unusable_sender_phone_blocks(): void {
        $this->opts($this->ok('boxnow') + ['bgcouriers_boxnow_partner_id' => '11', 'bgcouriers_boxnow_warehouse_id' => '2',
            'bgcouriers_boxnow_flat_price' => '3.99', 'bgcouriers_boxnow_sender_phone' => '123']);
        $this->assertNotEmpty((new BGCouriers_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    public function test_sameday_account_without_pickup_or_services_blocks(): void {
        // Services + pickup point are auto-discovered from the ACCOUNT now (no typed-in ids) - an
        // account that carries neither must still block enabling.
        $this->opts($this->ok('sameday') + ['bgcouriers_sameday_pickup_point' => '']);
        $c = new class([]) extends BGCouriers_Sameday {
            public function check_credentials(): bool { return true; }
            public function pickup_point_id(): int { return 0; }
            public function service_id(string $type): int { return 0; }
        };
        $this->assertNotEmpty($c->enable_problems());
    }
}
