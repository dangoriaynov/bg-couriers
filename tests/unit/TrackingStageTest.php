<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';

/**
 * BGCouriers_Tracking::classify()/stage() decide when the poller stops polling a waybill for good
 * (_bgcouriers_track_done is never cleared) and, with auto-status on, when an order is completed - so a
 * status read as terminal too early is expensive.
 *
 * @group core
 */
final class TrackingStageTest extends TestCase {
    /** Real Speedy operation descriptions observed live on the account. */
    public function test_observed_speedy_operations(): void {
        $this->assertSame('transit', BGCouriers_Tracking::classify('Получена информация за пратка'));
        $this->assertSame('cancelled', BGCouriers_Tracking::classify('Анулиране'));
    }

    /**
     * Regression: 'достав' is a substring of phrasings that mean the parcel is still moving. Reading
     * those as delivered would end tracking on a mere delivery attempt.
     */
    public function test_in_flight_delivery_phrasings_are_not_delivered(): void {
        foreach (['Предадена за доставка', 'Опит за доставка', 'Готова за доставка',
                  'Неуспешен опит за доставка', 'Out for delivery', 'Delivery attempt failed'] as $s) {
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
}
