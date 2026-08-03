<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * Whether the recipient may open - and possibly test - the parcel before paying is a promise made at
 * checkout, so it is one setting for the shop. It used to be two: a three-way select on Speedy and a
 * checkbox on Econt, which could disagree, and did nothing at all for anyone reading the Econt page
 * expecting to find "test".
 *
 * @group core
 */
final class OpenBeforePayTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,mixed> $opts */
    private function options(array $opts): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($opts) {
            return array_key_exists($n, $opts) ? $opts[$n] : $d;
        });
    }

    public function test_the_three_settings(): void {
        foreach (['no', 'open', 'test'] as $v) {
            $this->options(['bgcouriers_open_before_pay' => $v]);
            $this->assertSame($v, BGCouriers_Settings::open_before_pay());
        }
    }

    /** Anything unrecognised means "not allowed" - never a promise made by accident. */
    public function test_a_junk_value_allows_nothing(): void {
        $this->options(['bgcouriers_open_before_pay' => 'yes please']);
        $this->assertSame('no', BGCouriers_Settings::open_before_pay());
    }

    /** A shop configured before this was one setting keeps doing exactly what it did. */
    public function test_it_inherits_the_old_speedy_choice(): void {
        $this->options(['bgcouriers_speedy_open_before_pay' => 'test']);
        $this->assertSame('test', BGCouriers_Settings::open_before_pay());
    }

    /** ...and the old Econt checkbox, which only ever meant "may look". */
    public function test_it_inherits_the_old_econt_checkbox(): void {
        $this->options(['bgcouriers_econt_pay_after_accept' => 'yes']);
        $this->assertSame('open', BGCouriers_Settings::open_before_pay());

        $this->options(['bgcouriers_econt_pay_after_accept' => 'no']);
        $this->assertSame('no', BGCouriers_Settings::open_before_pay());
    }

    /**
     * Econt splits looking from trying out. "Test" is the stronger promise and includes looking, so both
     * flags travel together - sending payAfterTest alone would let someone test a parcel they may not open.
     */
    public function test_econt_sends_both_flags_for_test(): void {
        $sender = ['client' => ['name' => 'X', 'phones' => ['0888']],
                   'address' => ['city' => ['id' => 41], 'street' => '', 'num' => '1', 'quarter' => 'q', 'other' => '']];
        $order = new WC_Order();
        $order->items = [new WC_Order_Item_Stub(1, 1, 10.0)];
        $order->meta  = ['_bgcouriers_method' => 'office'];

        $this->options(['bgcouriers_open_before_pay' => 'open']);
        $l = BGCouriers_Econt::build_label_body($order, $sender, '1097')['label'];
        $this->assertTrue($l['services']['payAfterAccept']);
        $this->assertArrayNotHasKey('payAfterTest', $l['services']);

        $this->options(['bgcouriers_open_before_pay' => 'test']);
        $l = BGCouriers_Econt::build_label_body($order, $sender, '1097')['label'];
        $this->assertTrue($l['services']['payAfterAccept']);
        $this->assertTrue($l['services']['payAfterTest']);
    }

    /** A locker has nobody standing at it to supervise an inspection - both couriers ignore it there. */
    public function test_nothing_is_promised_for_a_locker(): void {
        $this->options(['bgcouriers_open_before_pay' => 'test']);
        $sender = ['client' => ['name' => 'X', 'phones' => ['0888']],
                   'address' => ['city' => ['id' => 41], 'street' => '', 'num' => '1', 'quarter' => 'q', 'other' => '']];
        $order = new WC_Order();
        $order->items = [new WC_Order_Item_Stub(1, 1, 10.0)];
        $order->meta  = ['_bgcouriers_method' => 'automat'];
        $l = BGCouriers_Econt::build_label_body($order, $sender, '1097')['label'];
        $this->assertArrayNotHasKey('payAfterAccept', $l['services'] ?? []);
        $this->assertArrayNotHasKey('payAfterTest', $l['services'] ?? []);
    }
}
