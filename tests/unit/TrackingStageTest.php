<?php
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';

/**
 * classify()/stage() decide when polling stops for good and when an order is completed, so reading a
 * status as terminal too early is the expensive mistake.
 *
 * @group core
 */
final class TrackingStageTest extends BGCouriers_Unit_TestCase {

    /** @return array<string,array{string,string}> courier wording => stage */
    public static function wordings(): array {
        $cases = [
            'transit' => [
                // "deliver" inside a phrase that means the parcel is still moving
                'Предадена за доставка', 'Опит за доставка', 'Готова за доставка', 'Неуспешен опит за доставка',
                'Out for delivery', 'Delivery attempt failed',
                // not even handed to the courier yet
                'Awaiting delivery to Econt', 'Очаква предаване към Еконт', 'Awaiting delivery', 'Ready for delivery',
                'Preparing for delivery', 'Delivery scheduled for tomorrow', 'Очаква се доставка',
                // Econt place names and prose
                'Travelling on line', 'Sofia - Sofia', 'Sofia Emil Markov Nov', 'Order № 260730044625 for: adding cash on delivery',
                // negations
                'Недоставена пратка', 'Not delivered', 'Undelivered - receiver absent', 'Unsuccessful delivery attempt',
                // a whole Speedy journey; declining to INSPECT is not a refusal of the parcel
                'Получена информация за пратка', 'Приемане от подател', 'Приемане от куриер/служител', 'Изпращане от офис',
                'Пристигане в офис', 'Уточняване на доставка', 'Отказ от преглед/тестване',
            ],
            'ready' => [
                'Подготовка за предаване на клиент в офис', 'Изпратено известие за пратка в офис/автомат',
                'Готова за получаване', 'Ready for pickup',
            ],
            'delivered' => [
                'Доставена пратка', 'Доставка на клиент', 'Взета от получателя', 'Delivered to office',
                'Доставена на получателя', 'delivered', 'AWB delivered', 'Доставена', 'Доставено', 'Delivered',
            ],
            'returning' => ['Връщане към подателя', 'Върната обратно', 'Return to sender'],
            'returned'  => ['Предаване обратно на подател', 'Върната пратка', 'Returned to sender'],
            'cancelled' => ['Анулиране', 'Отказана от получателя'],
        ];
        $out = [];
        foreach ($cases as $stage => $texts) {
            foreach ($texts as $text) { $out[$text] = [$text, $stage]; }
        }
        return $out;
    }

    /** @dataProvider wordings */
    public function test_classify_reads_the_courier_wording_as_its_stage(string $text, string $stage): void {
        $this->assertSame($stage, BGCouriers_Tracking::classify($text));
    }

    public function test_stage_without_a_phase_reads_the_text(): void {
        $t = new BGCouriers_Tracking('1', 'X', [['code' => 'X', 'name' => 'Доставена пратка', 'date' => '']]);
        $this->assertSame('delivered', $t->stage());
    }

    /** @return array<string,array{string,string}> */
    public static function phases(): array {
        return [
            'out for delivery'   => ['OUT_FOR_DELIVERY', 'transit'],
            'delivered'          => ['DELIVERED', 'delivered'],
            'return to sender'   => ['RETURN_TO_SENDER', 'returned'],
            'delivered back'     => ['DELIVERED_BACK_TO_SENDER', 'returned'],
            'in transit'         => ['IN_TRANSIT', 'transit'],
        ];
    }

    /** @dataProvider phases */
    public function test_a_courier_phase_overrides_the_text(string $phase, string $stage): void {
        $ev = [['code' => '1', 'name' => 'Предадена за доставка', 'date' => '']];
        $this->assertSame($stage, (new BGCouriers_Tracking('1', '1', $ev, $phase))->stage());
    }

    public function test_cancellation_text_survives_a_non_terminal_phase(): void {
        $ev = [['code' => '128', 'name' => 'Анулиране', 'date' => '']];
        $this->assertSame('cancelled', (new BGCouriers_Tracking('1', '128', $ev, 'IN_TRANSIT'))->stage());
    }

    public function test_an_operation_code_decides_the_stage_over_unknown_text(): void {
        $ev = [['code' => '1134', 'name' => 'Some wording we have never seen', 'date' => '']];
        $this->assertSame('ready', (new BGCouriers_Tracking('1', '1134', $ev))->stage());
        $done = [['code' => '-14', 'name' => 'Доставка на клиент', 'date' => '']];
        $this->assertSame('delivered', (new BGCouriers_Tracking('1', '-14', $done))->stage());
    }

    public function test_handover_is_unknown_unless_the_courier_states_it(): void {
        $ev = [['code' => '148', 'name' => 'Получена информация за пратка', 'date' => '']];
        $this->assertNull((new BGCouriers_Tracking('1', 'X', $ev))->handover);
        $this->assertFalse((new BGCouriers_Tracking('1', 'X', [], '', false))->handover);
        $this->assertTrue((new BGCouriers_Tracking('1', 'X', [], '', true))->handover);
    }

    public function test_every_stage_has_a_label(): void {
        foreach (['transit', 'ready', 'delivered', 'returned', 'cancelled'] as $stage) {
            $this->assertNotSame('', BGCouriers_Tracking::stage_label($stage), $stage);
        }
    }

    /** @return array<string,array{string,string,string,string}> */
    public static function status_notes(): array {
        return [
            'adds something'     => ['Speedy', 'registered', 'Получена информация за пратка', 'Speedy - Label created: "Получена информация за пратка"'],
            'same as the label'  => ['Sameday', 'registered', 'Label created', 'Sameday - Label created'],
            'case and padding'   => ['Sameday', 'registered', '  label CREATED ', 'Sameday - Label created'],
            'delivered'          => ['Econt', 'delivered', 'Доставена', 'Econt - Delivered: "Доставена"'],
        ];
    }

    /** @dataProvider status_notes */
    public function test_the_status_note_quotes_the_courier_only_when_it_adds_something(string $courier, string $stage, string $text, string $want): void {
        $this->assertSame($want, BGCouriers_Tracking::status_note($courier, $stage, $text));
    }

    /** @return array<string,array{string,string,array{string,string}}> */
    public static function headlines(): array {
        return [
            'identical'               => ['registered', 'Label created', ['Label created', '']],
            'identical, loosely'      => ['registered', ' label CREATED. ', ['Label created', '']],
            'courier text contains ours' => ['unclaimed', 'Not collected since 21 Aug', ['Not collected since 21 Aug', '']],
            'ours contains courier text' => ['returned', 'Back with', ['Back with you', '']],
            'different sentence'      => ['transit', 'Приемане от куриер', ['On its way', 'Приемане от куриер']],
            'parser fallback word'    => ['transit', 'UNKNOWN', ['On its way', '']],
            'nothing heard yet'       => ['ready', '', ['Ready for collection', '']],
        ];
    }

    /** @dataProvider headlines */
    public function test_the_headline_never_says_one_thing_twice(string $stage, string $text, array $want): void {
        $this->assertSame($want, BGCouriers_Tracking::stage_headline($stage, $text));
    }

    public function test_only_delivered_cancelled_and_returned_are_final(): void {
        foreach (['delivered', 'cancelled', 'returned'] as $st) {
            $this->assertTrue(BGCouriers_Tracking::is_final($st), $st);
        }
        foreach (['', 'registered', 'transit', 'ready', 'unclaimed', 'returning', 'nonsense'] as $st) {
            $this->assertFalse(BGCouriers_Tracking::is_final($st), "'$st'");
        }
    }
}
