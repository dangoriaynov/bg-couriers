<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';

/**
 * "When did it go out, when did it arrive, when did it come back" is answered from the courier's own
 * event dates - and every courier writes them differently: Econt sends milliseconds, Speedy and Pigeon a
 * date string, Express One its own. Reading one of them as another is how a shipment ends up stamped in
 * 1970 or in the year 50,000.
 *
 * @group core
 */
final class EventTimeTest extends TestCase {

    /** Econt's milliseconds: too big to be seconds, and that is exactly how they are recognised. */
    public function test_milliseconds_are_recognised_as_milliseconds(): void {
        $this->assertSame(1790934308, BGCouriers_Tracking::event_time(['date' => '1790934308000']));
    }

    /** A plain seconds timestamp stays what it is. */
    public function test_seconds_stay_seconds(): void {
        $this->assertSame(1790934308, BGCouriers_Tracking::event_time(['date' => '1790934308']));
    }

    /** The date strings the other couriers send. */
    public function test_date_strings_are_read(): void {
        $this->assertSame(strtotime('2026-10-02 11:45:00'), BGCouriers_Tracking::event_time(['date' => '2026-10-02 11:45:00']));
        $this->assertSame(strtotime('2026-10-02T11:45:00+03:00'), BGCouriers_Tracking::event_time(['date' => '2026-10-02T11:45:00+03:00']));
    }

    /** Nothing readable is 0, and the caller falls back to the time we saw it. */
    public function test_an_unreadable_date_is_zero(): void {
        $this->assertSame(0, BGCouriers_Tracking::event_time(['date' => '']));
        $this->assertSame(0, BGCouriers_Tracking::event_time([]));
        $this->assertSame(0, BGCouriers_Tracking::event_time(['date' => 'веднага']));
    }

    /** The stages are shown in the order they happen, not in the order they were written down. */
    public function test_the_stage_order_runs_from_label_to_the_end(): void {
        $order = BGCouriers_Tracking::STAGE_ORDER;
        $this->assertSame('registered', $order[0]);
        $this->assertLessThan(array_search('delivered', $order, true), array_search('transit', $order, true));
        $this->assertLessThan(array_search('returned', $order, true), array_search('returning', $order, true));
    }
}
