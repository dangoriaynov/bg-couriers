<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * Saved credentials are saved whether or not the courier is switched on.
 *
 * creds_present() used to require the enable toggle as well, which the settings screen reads to decide
 * whether to LOCK the username and password behind the ✕. A courier that was configured but disabled
 * therefore rendered both fields blank and editable - and a browser fills an empty field called
 * "Client ID" with the merchant's own e-mail, which the next Save would write over the real credential.
 *
 * @group core
 */
final class CredsPresentTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $opts */
    private function options(array $opts): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($opts) {
            return array_key_exists($n, $opts) ? $opts[$n] : $d;
        });
    }

    /** The case that broke BOX NOW on the live shop: credentials stored, courier turned off. */
    public function test_disabled_courier_still_has_its_credentials(): void {
        $this->options([
            'bgcouriers_boxnow_enabled'  => 'no',
            'bgcouriers_boxnow_username' => 'a3f1c8e2-0000-4d5b-9e77-1b2c3d4e5f60',
            'bgcouriers_boxnow_password' => 'secret',
        ]);
        $this->assertTrue(BGCouriers_Settings::creds_present('boxnow'),
            'disabling a courier must not make the settings screen treat its credentials as absent');
    }

    public function test_enabled_courier_with_credentials(): void {
        $this->options([
            'bgcouriers_speedy_enabled'  => 'yes',
            'bgcouriers_speedy_username' => '1234567',
            'bgcouriers_speedy_password' => 'secret',
        ]);
        $this->assertTrue(BGCouriers_Settings::creds_present('speedy'));
    }

    /** Half-entered credentials are not credentials: nothing to lock, and Validate would fail anyway. */
    public function test_password_without_username_is_not_present(): void {
        $this->options([
            'bgcouriers_econt_enabled'  => 'yes',
            'bgcouriers_econt_username' => '',
            'bgcouriers_econt_password' => 'secret',
        ]);
        $this->assertFalse(BGCouriers_Settings::creds_present('econt'));
    }

    public function test_nothing_saved_is_not_present(): void {
        $this->options(['bgcouriers_pigeon_enabled' => 'yes']);
        $this->assertFalse(BGCouriers_Settings::creds_present('pigeon'));
    }
}
