<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';

/**
 * BGCouriers_Tracking::classify()/stage() decide when the poller stops polling a waybill for good
 * (_bgcouriers_track_done is never cleared) and, with auto-status on, when an order is completed - so a
 * status read as terminal too early is expensive.
 *
 * @group core
 */
final class TrackingStageTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Real Speedy operation descriptions observed live on the account. */
    public function test_observed_speedy_operations(): void {
        $this->assertSame('transit', BGCouriers_Tracking::classify('Получена информация за пратка'));
        $this->assertSame('cancelled', BGCouriers_Tracking::classify('Анулиране'));
    }

    /**
     * Regression: 'достав'/'deliver' are substrings of phrasings that mean the parcel is still moving.
     * Reading those as delivered would end tracking on a mere delivery attempt.
     */
    public function test_in_flight_delivery_phrasings_are_not_delivered(): void {
        foreach (['Предадена за доставка', 'Опит за доставка', 'Готова за доставка',
                  'Неуспешен опит за доставка', 'Out for delivery', 'Delivery attempt failed'] as $s) {
            $this->assertSame('transit', BGCouriers_Tracking::classify($s), $s);
        }
    }

    /**
     * Regression, live incident: Econt's FIRST tracking event on order 11178 was "Awaiting delivery to
     * Econt" (event code 'prepared') - the parcel had not even been handed to the courier. 'deliver'
     * matched inside 'delivery', so the order was completed the day after it was placed and marked
     * terminal, which also stopped it ever being polled again. Both languages, both real strings.
     */
    public function test_awaiting_handover_is_not_delivered(): void {
        foreach (['Awaiting delivery to Econt', 'Очаква предаване към Еконт',
                  'Awaiting delivery', 'Ready for delivery', 'Preparing for delivery',
                  'Delivery scheduled for tomorrow', 'Очаква се доставка'] as $s) {
            $this->assertSame('transit', BGCouriers_Tracking::classify($s), $s);
        }
    }

    /** Every courier goes through classify(), so each one's real delivered wording has to pass. */
    public function test_delivered_wording_of_each_courier(): void {
        foreach (['Доставена пратка',            // Speedy
                  'Доставка на клиент',           // Speedy, operation -14 - the real final event
                  'Взета от получателя',          // Pigeon - its API sends no events, only this text
                  'Delivered to office',          // Econt (English feed)
                  'Доставена на получателя',      // Econt (Bulgarian feed)
                  'delivered',                    // Pigeon / Sameday lowercase feeds
                  'AWB delivered'] as $s) {
            $this->assertSame('delivered', BGCouriers_Tracking::classify($s), $s);
        }
    }

    /**
     * The full operation list of two real Speedy parcels, in order. Only the last one is terminal - and
     * "Отказ от преглед/тестване" (declining to INSPECT the parcel) fires one second before delivery, so
     * reading its "отказ" as a cancellation would end tracking right at the finish line.
     */
    public function test_a_whole_speedy_journey_reads_correctly(): void {
        $journey = [
            'Получена информация за пратка'                => 'transit',
            'Приемане от подател'                          => 'transit',
            'Приемане от куриер/служител'                  => 'transit',
            'Изпращане от офис'                            => 'transit',
            'Пристигане в офис'                            => 'transit',
            // The parcel has arrived and the customer has been told to come for it - its own stage,
            // between "on its way" and "handed over".
            'Подготовка за предаване на клиент в офис'     => 'ready',
            'Изпратено известие за пратка в офис/автомат'  => 'ready',
            'Уточняване на доставка'                       => 'transit',
            'Отказ от преглед/тестване'                    => 'transit',
            'Доставка на клиент'                           => 'delivered',
        ];
        foreach ($journey as $text => $want) {
            $this->assertSame($want, BGCouriers_Tracking::classify($text), $text);
        }
    }

    /** Econt's in-flight events are place names and prose; none of them may read as terminal. */
    public function test_econt_in_flight_events_are_transit(): void {
        foreach (['Awaiting delivery to Econt', 'Travelling on line', 'Sofia - Sofia',
                  'Sofia Emil Markov Nov', 'Order № 260730044625 for: adding cash on delivery'] as $s) {
            $this->assertSame('transit', BGCouriers_Tracking::classify($s), $s);
        }
    }

    /** An explicit negation must never read as the positive it contains. */
    public function test_negated_delivery_is_not_delivered(): void {
        foreach (['Недоставена пратка', 'Not delivered', 'Undelivered - receiver absent',
                  'Unsuccessful delivery attempt'] as $s) {
            $this->assertSame('transit', BGCouriers_Tracking::classify($s), $s);
        }
    }

    public function test_real_delivery_still_classifies_as_delivered(): void {
        foreach (['Доставена пратка', 'Доставена', 'Доставено', 'Delivered'] as $s) {
            $this->assertSame('delivered', BGCouriers_Tracking::classify($s), $s);
        }
    }

    public function test_returns_and_cancellations(): void {
        $this->assertSame('returned', BGCouriers_Tracking::classify('Върната пратка'));
        $this->assertSame('cancelled', BGCouriers_Tracking::classify('Отказана от получателя'));
    }

    /** With no courier phase (Econt/Pigeon/Sameday/BoxNow), stage() is just the text verdict. */
    public function test_stage_without_a_phase_reads_the_text(): void {
        $t = new BGCouriers_Tracking('1', 'X', [['code' => 'X', 'name' => 'Доставена пратка', 'date' => '']]);
        $this->assertSame('delivered', $t->stage());
    }

    /** Speedy's trackPhase wins when present - the text can no longer misfire. */
    public function test_phase_overrides_the_text(): void {
        $ev = [['code' => '1', 'name' => 'Предадена за доставка', 'date' => '']];
        $this->assertSame('transit', (new BGCouriers_Tracking('1', '1', $ev, 'OUT_FOR_DELIVERY'))->stage());
        $this->assertSame('delivered', (new BGCouriers_Tracking('1', '1', $ev, 'DELIVERED'))->stage());
        $this->assertSame('returned', (new BGCouriers_Tracking('1', '1', $ev, 'RETURN_TO_SENDER'))->stage());
        $this->assertSame('returned', (new BGCouriers_Tracking('1', '1', $ev, 'DELIVERED_BACK_TO_SENDER'))->stage());
        $this->assertSame('transit', (new BGCouriers_Tracking('1', '1', $ev, 'IN_TRANSIT'))->stage());
    }

    /** trackPhase has no cancelled member, so a cancellation still has to come from the text. */
    public function test_cancellation_survives_a_non_terminal_phase(): void {
        $ev = [['code' => '128', 'name' => 'Анулиране', 'date' => '']];
        $this->assertSame('cancelled', (new BGCouriers_Tracking('1', '128', $ev, 'IN_TRANSIT'))->stage());
    }

    /**
     * The handover flag is what decides "Изпратена", and it must be false while the label merely exists.
     * Speedy's first operation (148 "Получена информация за пратка") and Econt's first event ("Awaiting
     * delivery to Econt") both fire before anything is collected.
     */
    public function test_handover_is_unknown_by_default(): void {
        $t = new BGCouriers_Tracking('1', 'X', [['code' => '148', 'name' => 'Получена информация за пратка', 'date' => '']]);
        $this->assertNull($t->handover, 'couriers that do not say leave it unknown');
    }

    public function test_handover_can_be_stated_either_way(): void {
        $this->assertFalse((new BGCouriers_Tracking('1', 'X', [], '', false))->handover);
        $this->assertTrue((new BGCouriers_Tracking('1', 'X', [], '', true))->handover);
    }

    /** The middle stage: arrived, waiting for the customer. Never confused with delivered or cancelled. */
    public function test_ready_for_collection_is_its_own_stage(): void {
        foreach (['Изпратено известие за пратка в офис/автомат',
                  'Подготовка за предаване на клиент в офис',
                  'Готова за получаване',
                  'Ready for pickup'] as $s) {
            $this->assertSame('ready', BGCouriers_Tracking::classify($s), $s);
        }
        // ...and a delivered or cancelled parcel is never demoted back to "waiting".
        $this->assertSame('delivered', BGCouriers_Tracking::classify('Доставка на клиент'));
        $this->assertSame('cancelled', BGCouriers_Tracking::classify('Анулиране'));
    }

    /** Speedy states it with an operation code too, which must win over however the text reads. */
    public function test_ready_from_the_operation_code(): void {
        $ev = [['code' => '1134', 'name' => 'Some wording we have never seen', 'date' => '']];
        $this->assertSame('ready', (new BGCouriers_Tracking('1', '1134', $ev))->stage());
        $done = [['code' => '-14', 'name' => 'Доставка на клиент', 'date' => '']];
        $this->assertSame('delivered', (new BGCouriers_Tracking('1', '-14', $done))->stage());
    }

    /** Every stage has a label a merchant can read - no raw verdicts leak into the admin. */
    public function test_every_stage_has_a_label(): void {
        foreach (['transit', 'ready', 'delivered', 'returned', 'cancelled'] as $stage) {
            $this->assertNotSame('', BGCouriers_Tracking::stage_label($stage), $stage);
        }
    }

    /**
     * Regression, live: order 11182 came back - the customer refused it - and the admin still said the
     * parcel was on its way, because Speedy words a return two ways and we knew only one. "Връщане към
     * подателя" (111) matched; "Предаване обратно на подател" (124), which is what the last event said,
     * did not.
     */
    public function test_both_speedy_wordings_for_a_return(): void {
        // On its way back - the courier still has it.
        foreach (['Връщане към подателя', 'Върната обратно', 'Return to sender'] as $s) {
            $this->assertSame('returning', BGCouriers_Tracking::classify($s), $s);
        }
        // Back on the merchant's counter - the journey is over. Order 11182 sat on "coming back" while
        // the box was already in the shop, because these two were treated as one thing.
        foreach (['Предаване обратно на подател', 'Върната пратка', 'Returned to sender'] as $s) {
            $this->assertSame('returned', BGCouriers_Tracking::classify($s), $s);
        }
    }
}
