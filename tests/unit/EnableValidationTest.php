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
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function opts(array $map): void {
        Functions\when('get_option')->alias(function ($k, $d = false) use ($map) {
            return array_key_exists($k, $map) ? $map[$k] : $d;
        });
    }
    /** enabled + credentials saved + validated - the base "ready" state. */
    private function ok(string $c): array {
        return ["bgcouriers_{$c}_enabled" => 'yes', "bgcouriers_{$c}_username" => 'u', "bgcouriers_{$c}_password" => 'p', "bgcouriers_{$c}_validated" => 'yes'];
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
        $this->opts($this->ok('boxnow') + ['bgcouriers_boxnow_partner_id' => '11', 'bgcouriers_boxnow_warehouse_id' => '2', 'bgcouriers_boxnow_flat_price' => '3.99']);
        $this->assertEmpty((new BGCouriers_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
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
