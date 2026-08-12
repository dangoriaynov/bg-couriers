<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';

/**
 * Pure parser tests for BGCouriers_Pigeon label creation and tracking responses.
 *
 * Every tracking fixture here is a payload Pigeon really returned (captured from the live API for the
 * shop's own waybills 458640894807 and 458388094369), except track-office.json - that one is assembled
 * from Pigeon's own GET /v1/shipment-statuses list, because no shipment of ours has sat in that state
 * while anyone was watching. The fixtures this file used before were WRITTEN FROM THE SPEC and did not
 * match reality: they put the history under `events` with a `timestamp` and invented status codes
 * ("registered", "delivered"). Pigeon actually sends `tracking` / `created_at` / `shipment_*`, so the
 * parser read an empty history for every real shipment and no Pigeon order ever left "Label created".
 *
 * @group pigeon
 */
final class PigeonLabelTrackTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pigeon/' . $f), true);
    }
    private function track(string $f = 'track.json'): BGCouriers_Tracking {
        return BGCouriers_Pigeon::parse_tracking($this->fx($f));
    }

    public function test_parse_shipment_id_returns_reference_number(): void {
        $resp = $this->fx('create.json');
        $this->assertSame('455791936383', BGCouriers_Pigeon::parse_shipment_id($resp));
    }

    public function test_parse_tracking_returns_bgc_tracking_instance(): void {
        $this->assertInstanceOf(BGCouriers_Tracking::class, $this->track());
    }

    public function test_parse_tracking_waybill(): void {
        $this->assertSame('458640894807', $this->track()->waybill);
    }

    public function test_parse_tracking_status(): void {
        $this->assertSame('В сортировачен център', $this->track()->status);
    }

    /** Regression: the history lives under `tracking`, and reading `events` found none of it. */
    public function test_parse_tracking_event_count(): void {
        $this->assertCount(4, $this->track()->events);
    }

    public function test_parse_tracking_first_event_fields(): void {
        $e0 = $this->track()->events[0];
        $this->assertSame('shipment_registered', $e0['code']);
        $this->assertSame('Регистрирана пратка', $e0['name']);
        $this->assertSame('2026-08-10T15:10:27+03:00', $e0['date']);
    }

    public function test_parse_tracking_last_event_fields(): void {
        $e = $this->track()->events[3];
        $this->assertSame('shipment_in_sorting_center', $e['code']);
        $this->assertSame('В сортировачен център', $e['name']);
        $this->assertSame('2026-08-12T22:12:57+03:00', $e['date']);
    }

    /** Pigeon publishes a machine status code, so nothing about a Pigeon parcel is decided by reading text. */
    public function test_parse_tracking_passes_the_status_code_as_the_phase(): void {
        $this->assertSame('shipment_in_sorting_center', $this->track()->phase);
        $this->assertSame('shipment_registered', $this->track('track-registered.json')->phase);
    }

    // ── Stage ───────────────────────────────────────────────────────────────

    /**
     * The reported bug, order 11244: the parcel was accepted at the office, went out for delivery and
     * reached the sorting centre, and the shop still showed "Label created" and never moved the order to
     * Shipped.
     */
    public function test_a_moving_parcel_is_in_transit(): void {
        $t = $this->track();
        $this->assertSame('transit', $t->stage());
        $this->assertTrue($t->handover, 'the courier plainly has it');
    }

    /** The opposite failure: a freshly printed label must NOT read as shipped. */
    public function test_a_freshly_registered_parcel_is_not_moving(): void {
        $t = $this->track('track-registered.json');
        $this->assertSame('registered', $t->stage());
        $this->assertFalse($t->handover, 'it is still on our own desk');
    }

    public function test_taken_by_the_recipient_is_delivered(): void {
        $t = $this->track('track-delivered.json');
        $this->assertSame('Взета от получателя', $t->status);
        $this->assertSame('delivered', $t->stage());
    }

    /**
     * "Доставена в офис/локър" means the parcel ARRIVED at the pickup point - the customer has not been
     * near it. Read as delivered it would complete the order and, because delivered is terminal, stop the
     * shipment being polled at all while it sat in a locker for a week and went back to the sender.
     */
    public function test_delivered_to_the_office_is_waiting_for_collection_not_delivered(): void {
        $this->assertSame('ready', $this->track('track-office.json')->stage());
    }
}
