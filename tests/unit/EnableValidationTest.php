<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgc-settings.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-speedy.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-econt.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-boxnow.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-sameday.php';

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
        return ["bgc_{$c}_enabled" => 'yes', "bgc_{$c}_username" => 'u', "bgc_{$c}_password" => 'p', "bgc_{$c}_validated" => 'yes'];
    }

    public function test_missing_credentials_blocks(): void {
        $this->opts([]);
        $this->assertNotEmpty((new BGC_Speedy([]))->enable_problems());
    }

    public function test_unvalidated_credentials_block(): void {
        $this->opts(['bgc_speedy_enabled' => 'yes', 'bgc_speedy_username' => 'u', 'bgc_speedy_password' => 'p', 'bgc_speedy_validated' => 'no']);
        $this->assertNotEmpty((new BGC_Speedy([]))->enable_problems());
    }

    public function test_valid_speedy_passes(): void {
        $this->opts($this->ok('speedy'));
        $this->assertEmpty((new BGC_Speedy([]))->enable_problems());
    }

    public function test_econt_cod_without_agreement_blocks(): void {
        $this->opts($this->ok('econt') + ['bgc_econt_cod_enabled' => 'yes', 'bgc_econt_cd_num' => '']);
        $this->assertNotEmpty((new BGC_Econt([]))->enable_problems());
    }

    public function test_econt_cod_with_agreement_passes(): void {
        $this->opts($this->ok('econt') + ['bgc_econt_cod_enabled' => 'yes', 'bgc_econt_cd_num' => 'CD139925']);
        $this->assertEmpty((new BGC_Econt([]))->enable_problems());
    }

    public function test_boxnow_missing_warehouse_blocks(): void {
        $this->opts($this->ok('boxnow') + ['bgc_boxnow_partner_id' => '11', 'bgc_boxnow_warehouse_id' => '', 'bgc_boxnow_flat_price' => '3.99']);
        $this->assertNotEmpty((new BGC_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    public function test_boxnow_missing_flat_price_blocks(): void {
        $this->opts($this->ok('boxnow') + ['bgc_boxnow_partner_id' => '11', 'bgc_boxnow_warehouse_id' => '2', 'bgc_boxnow_flat_price' => '0']);
        $this->assertNotEmpty((new BGC_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    public function test_boxnow_complete_passes(): void {
        $this->opts($this->ok('boxnow') + ['bgc_boxnow_partner_id' => '11', 'bgc_boxnow_warehouse_id' => '2', 'bgc_boxnow_flat_price' => '3.99']);
        $this->assertEmpty((new BGC_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']))->enable_problems());
    }

    public function test_sameday_account_without_pickup_or_services_blocks(): void {
        // Services + pickup point are auto-discovered from the ACCOUNT now (no typed-in ids) - an
        // account that carries neither must still block enabling.
        $this->opts($this->ok('sameday') + ['bgc_sameday_pickup_point' => '']);
        $c = new class([]) extends BGC_Sameday {
            public function check_credentials(): bool { return true; }
            public function pickup_point_id(): int { return 0; }
            public function service_id(string $type): int { return 0; }
        };
        $this->assertNotEmpty($c->enable_problems());
    }
}
