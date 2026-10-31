<?php
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-econt.php';

/**
 * The office the shop hands parcels in at goes on the quote and the waybill as senderOfficeCode, and nowhere when none is chosen.
 *
 * @group econt
 */
final class EcontDropoffTest extends BGCouriers_Unit_TestCase {
    protected function setUp(): void {
        parent::setUp();
        Functions\when('wc_get_weight')->alias(static fn($v, $u) => $v);
    }

    private function sender(): array {
        return ['client' => ['name' => 'ПРИМЕР ООД', 'phones' => ['0888123456']],
                'address' => ['city' => ['id' => 41], 'street' => 'бул. Витоша', 'num' => '1']];
    }

    private function order(): WC_Order {
        $o = new WC_Order();
        $o->total          = 24.0;
        $o->payment_method = 'bacs';
        $o->items          = [new WC_Order_Item_Stub(1, 1, 24.00)];
        $o->meta           = ['_bgcouriers_method' => 'office'];
        return $o;
    }

    public function test_the_quote_starts_at_the_office_only_when_one_is_chosen(): void {
        $this->options([]);
        $s = ['method' => 'office', 'site_id' => 56784, 'office_code' => '4002', 'weight_kg' => 1.0];

        $none = BGCouriers_Econt::build_calculate_body($s, $this->sender())['label'];
        $this->assertArrayNotHasKey('senderOfficeCode', $none);

        $from = BGCouriers_Econt::build_calculate_body($s, $this->sender(), '1009')['label'];
        $this->assertSame('1009', $from['senderOfficeCode']);
        $this->assertSame('4002', $from['receiverOfficeCode'], 'the receiver end is untouched');
        $this->assertSame(41, $from['senderAddress']['city']['id'], 'the client address stays on it');
    }

    public function test_the_waybill_starts_at_the_office_only_when_one_is_chosen(): void {
        $this->options([]);
        $none = BGCouriers_Econt::build_label_body($this->order(), $this->sender(), '4002')['label'];
        $this->assertArrayNotHasKey('senderOfficeCode', $none);

        $from = BGCouriers_Econt::build_label_body($this->order(), $this->sender(), '4002', '1009')['label'];
        $this->assertSame('1009', $from['senderOfficeCode']);
        $this->assertSame('4002', $from['receiverOfficeCode']);
    }

    public function test_the_setting_reads_as_an_office_id(): void {
        $this->options([]);
        $this->assertSame(0, BGCouriers_Econt::dropoff_office());
        $this->options(['bgcouriers_econt_dropoff_office' => '163']);
        $this->assertSame(163, BGCouriers_Econt::dropoff_office());
    }
}
