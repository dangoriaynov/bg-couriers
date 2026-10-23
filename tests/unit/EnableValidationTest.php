<?php
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
 * Per-courier "can this courier be enabled" rules: credentials present and validated, plus each courier's own fields.
 *
 * @group core
 */
final class EnableValidationTest extends BGCouriers_Unit_TestCase {
    protected function setUp(): void {
        parent::setUp();
        // No database: "nothing synced" makes available_methods() fall back to declared capabilities.
        Functions\when('get_transient')->alias(static function ($k) {
            return strpos((string) $k, 'bgcouriers_typecnt_') === 0
                ? ['office' => 0, 'automat' => 0, 'total' => 0] : false;
        });
        Functions\when('set_transient')->justReturn(true);
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });
    }
    protected function tearDown(): void {
        // The registry is static: a registration that outlives its test poisons the next file.
        BGCouriers_Couriers::reset();
        parent::tearDown();
    }

    /** enabled + credentials saved + validated - the base "ready" state. */
    private function ok(string $c): array {
        return ["bgcouriers_{$c}_enabled" => 'yes', "bgcouriers_{$c}_username" => 'u', "bgcouriers_{$c}_password" => 'p', "bgcouriers_{$c}_validated" => 'yes'];
    }

    private function speedy_problem_codes(): array {
        return array_column((new BGCouriers_Speedy([]))->enable_problems(), 'code');
    }

    /** The merchant's path from an empty install; validating must work while the courier is still off. */
    public function test_the_setup_sequence_from_an_empty_install(): void {
        $this->options([]);
        $this->assertSame(['creds_missing'], $this->speedy_problem_codes());

        $saved = [
            'bgcouriers_speedy_enabled'   => 'no',
            'bgcouriers_speedy_username'  => 'u',
            'bgcouriers_speedy_password'  => 'p',
            'bgcouriers_speedy_validated' => 'no',
        ];
        $this->options($saved);
        $this->assertSame('u', BGCouriers_Settings::courier_credentials('speedy')['username']);
        $this->assertTrue(BGCouriers_Settings::creds_present('speedy'));
        $this->assertNull(BGCouriers_Settings::courier_config('speedy'), 'it is still switched off');
        $this->assertSame(['creds_unvalidated'], $this->speedy_problem_codes());

        $this->options(array_merge($saved, ['bgcouriers_speedy_validated' => 'yes']));
        $this->assertSame([], $this->speedy_problem_codes(), 'switched off is not a credentials problem');

        $this->options($this->ok('speedy'));
        $this->assertSame([], $this->speedy_problem_codes());
        $this->assertNotNull(BGCouriers_Settings::courier_config('speedy'));
    }

    /** @return array<string,array{array,mixed,bool}> extra options, cached Econt agreements, blocks? */
    public static function econt_cod(): array {
        return [
            'cod without agreement'     => [['bgcouriers_econt_cd_num' => ''], false, true],
            'cod with agreement, profile unreachable' => [['bgcouriers_econt_cd_num' => 'CD139925'], false, false],
            // moneyTransfer does not map onto the merchant's choice; only existence is checked
            'agreement whatever its moneyTransfer' => [['bgcouriers_econt_cd_num' => 'CD139925', 'bgcouriers_econt_ppp_payout' => 'yes'], [
                ['num' => 'CD139879', 'moneyTransfer' => true,  'method' => 'office', 'officeCode' => '1170'],
                ['num' => 'CD139925', 'moneyTransfer' => false, 'method' => 'bank',   'IBAN' => 'BG68...'],
            ], false],
            'agreement no longer in the profile' => [['bgcouriers_econt_cd_num' => 'CD000000'], [
                ['num' => 'CD139925', 'moneyTransfer' => false, 'method' => 'bank'],
            ], true],
        ];
    }

    /** @dataProvider econt_cod */
    public function test_econt_cash_on_delivery_needs_an_existing_agreement(array $extra, $agreements, bool $blocks): void {
        $this->options($this->ok('econt') + ['bgcouriers_econt_cod_enabled' => 'yes'] + $extra);
        Functions\when('get_transient')->justReturn($agreements);
        Functions\when('wp_remote_post')->alias(static function () { throw new \RuntimeException('no network in a unit test'); });
        $this->assertSame($blocks, (new BGCouriers_Econt([]))->enable_problems() !== []);
    }

    /** @return array<string,array{array,bool}> */
    public static function boxnow(): array {
        $full = ['bgcouriers_boxnow_partner_id' => '11', 'bgcouriers_boxnow_warehouse_id' => '2',
                 'bgcouriers_boxnow_flat_price' => '3.99', 'bgcouriers_boxnow_sender_phone' => '0888123456'];
        return [
            'complete'                => [$full, false],
            'no warehouse'            => [['bgcouriers_boxnow_warehouse_id' => ''] + $full, true],
            'no flat price'           => [['bgcouriers_boxnow_flat_price' => '0'] + $full, true],
            'no sender phone'         => [['bgcouriers_boxnow_sender_phone' => ''] + $full, true],
            'sender phone not E.164'  => [['bgcouriers_boxnow_sender_phone' => '123'] + $full, true],
        ];
    }

    /** @dataProvider boxnow */
    public function test_boxnow_needs_warehouse_price_and_a_usable_sender_phone(array $opts, bool $blocks): void {
        $this->options($this->ok('boxnow') + $opts);
        $c = new BGCouriers_Boxnow(['api_url' => '', 'partner_id' => '', 'warehouse_id' => '']);
        $this->assertSame($blocks, $c->enable_problems() !== []);
    }

    public function test_sameday_account_without_pickup_or_services_blocks(): void {
        $this->options($this->ok('sameday') + ['bgcouriers_sameday_pickup_point' => '']);
        $c = new class([]) extends BGCouriers_Sameday {
            public function check_credentials(): bool { return true; }
            public function pickup_point_id(): int { return 0; }
            public function service_id(string $type): int { return 0; }
        };
        $this->assertNotEmpty($c->enable_problems());
    }
}
