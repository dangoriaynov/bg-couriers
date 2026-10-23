<?php

require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-settings.php';

/**
 * creds_present() ignores the enable toggle: the settings screen locks saved credentials by it, and an
 * unlocked blank field invites the browser to autofill over the real credential.
 *
 * @group core
 */
final class CredsPresentTest extends BGCouriers_Unit_TestCase {

    /** @return array<string,array{string,array,bool}> */
    public static function cases(): array {
        return [
            'disabled with credentials' => ['boxnow', ['enabled' => 'no', 'username' => 'a3f1c8e2-0000-4d5b-9e77-1b2c3d4e5f60', 'password' => 'secret'], true],
            'enabled with credentials'  => ['speedy', ['enabled' => 'yes', 'username' => '1234567', 'password' => 'secret'], true],
            'password without username' => ['econt', ['enabled' => 'yes', 'username' => '', 'password' => 'secret'], false],
            'nothing saved'             => ['pigeon', ['enabled' => 'yes'], false],
        ];
    }

    /** @dataProvider cases */
    public function test_credentials_are_present_only_when_both_are_saved(string $courier, array $saved, bool $present): void {
        $opts = [];
        foreach ($saved as $k => $v) { $opts["bgcouriers_{$courier}_{$k}"] = $v; }
        $this->options($opts);
        $this->assertSame($present, BGCouriers_Settings::creds_present($courier));
    }
}
