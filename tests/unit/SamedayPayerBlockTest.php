<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Sameday refuses recipient-paid delivery unless the contract covers it, and when it refuses NO waybill
 * is produced - every order with that courier fails. There is no way to ask the API up front, so the
 * refusal itself is remembered and turned into the same red-tab blocker the ППП rules already use.
 *
 * @group core
 */
final class SamedayPayerBlockTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $opts */
    private function opts(array $opts): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($opts) {
            return array_key_exists($n, $opts) ? $opts[$n] : $d;
        });
    }

    /** Refused by Sameday + still configured as recipient-pays = the courier cannot ship at all. */
    public function test_blocks_when_sameday_refused_and_the_setting_still_says_recipient(): void {
        $this->opts([
            BGCouriers_Sameday::NO_RECIPIENT_PAY   => 'yes',
            'bgcouriers_sameday_ship_in_total'     => 'no',
        ]);
        $n = BGCouriers_Settings::courier_blocker('sameday');
        $this->assertIsArray($n);
        $this->assertSame('error', $n['level'], 'error is what paints the tab red');
        $this->assertStringContainsString('Sameday', $n['msg']);
    }

    /** Once the merchant switches to "delivery in the order total", the courier works - no blocker. */
    public function test_no_block_once_the_merchant_charges_the_delivery(): void {
        $this->opts([
            BGCouriers_Sameday::NO_RECIPIENT_PAY => 'yes',
            'bgcouriers_sameday_ship_in_total'   => 'yes',
        ]);
        $this->assertNull(BGCouriers_Settings::courier_blocker('sameday'));
    }

    /** Never warn about a refusal that has not happened - most accounts are fine. */
    public function test_no_block_before_sameday_has_ever_refused(): void {
        $this->opts(['bgcouriers_sameday_ship_in_total' => 'no']);
        $this->assertNull(BGCouriers_Settings::courier_blocker('sameday'));
    }

    /** The flag is Sameday's alone; it must not bleed onto another courier. */
    public function test_other_couriers_are_unaffected(): void {
        $this->opts([
            BGCouriers_Sameday::NO_RECIPIENT_PAY => 'yes',
            'bgcouriers_speedy_ship_in_total'    => 'no',
        ]);
        $this->assertNull(BGCouriers_Settings::courier_blocker('speedy'));
    }

    /** The ППП blockers still come through the same channel - this replaced their call sites. */
    public function test_ppp_blocker_still_reported(): void {
        $this->opts([
            'bgcouriers_cod_fiscalization'    => 'ppp',
            'bgcouriers_boxnow_ppp_payout'    => 'no',
        ]);
        Functions\when('wc_get_payment_gateway_by_order')->justReturn(null);
        $n = BGCouriers_Settings::courier_blocker('boxnow');
        $this->assertIsArray($n);
        $this->assertContains($n['level'], ['error', 'warning']);
    }
}
