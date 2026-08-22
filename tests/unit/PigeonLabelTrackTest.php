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

    // ── Returns ────────────────────────────────────────────────────────────
    //
    // Pigeon does NOT walk the outward waybill back through 'returning_to_sender'/'returned'. Those two
    // codes exist, but on a parcel nobody collected the outward number FREEZES at "Непотърсена" for good
    // and Pigeon opens a SECOND waybill for the journey home, linked from the first one's `chain_after`.
    // Reading only the number we booked, a return is therefore completely invisible - which is exactly
    // what happened to order 11244: outward 458640894807 stopped at "Непотърсена" on 21 Aug while
    // return 458824370227 travelled back and reached the shop's own office, and the order never moved.
    // Every fixture below is the live payload for that pair, except track-redirect-leg.json.

    public function test_chain_after_names_the_shipment_that_carries_on(): void {
        $this->assertSame('458824370227',
            BGCouriers_Pigeon::chained_ref($this->fx('track-unclaimed-return-chain.json')));
    }

    /** No chain, nothing to follow - the common case must not cost a second request. */
    public function test_a_shipment_with_no_chain_asks_for_nothing_more(): void {
        $this->assertSame('', BGCouriers_Pigeon::chained_ref($this->fx('track.json')));
        $this->assertSame('', BGCouriers_Pigeon::chained_ref($this->fx('track-delivered.json')));
    }

    /** The parcel is on its way back: the order should say so, and must NOT be written off yet. */
    public function test_a_parcel_travelling_back_reads_as_returning(): void {
        $t = BGCouriers_Pigeon::follow_chain(
            $this->fx('track-unclaimed-return-chain.json'), $this->fx('track-return-leg-travelling.json'));
        $this->assertSame('returning', $t->stage());
        $this->assertSame('В сортировачен център', $t->status);
    }

    /**
     * And the parcel is home. Pigeon words the end of a return exactly as it words the end of a delivery
     * - "Готова за взимане в офис/АПС" - so on the return leg that wording is the shop's own office
     * telling them to come and get their goods back, not a customer's.
     */
    public function test_a_parcel_back_at_your_office_reads_as_returned(): void {
        $t = BGCouriers_Pigeon::follow_chain(
            $this->fx('track-unclaimed-return-chain.json'), $this->fx('track-return-leg-home.json'));
        $this->assertSame('returned', $t->stage());
        $this->assertSame('Готова за взимане в офис/АПС', $t->status);
    }

    /** The return's own number is what the shop has to quote at the counter to get the box. */
    public function test_the_return_carries_its_own_waybill_and_the_whole_history(): void {
        $t = BGCouriers_Pigeon::follow_chain(
            $this->fx('track-unclaimed-return-chain.json'), $this->fx('track-return-leg-home.json'));
        $this->assertSame('458824370227', $t->waybill);
        $this->assertCount(12, $t->events, 'seven events out, five back');
        $this->assertSame('Регистрирана пратка', $t->events[0]['name']);
        $this->assertSame('Връщане към подател', $t->events[7]['name']);
        $this->assertTrue($t->handover);
    }

    /**
     * A chained shipment is not always a return - a redirection makes one too, and that parcel is still
     * going FORWARD. Only a chain whose opening event is "Връщане към подател" is a journey home; anything
     * else leaves the outward verdict alone rather than announcing a return that is not happening.
     */
    public function test_a_redirected_parcel_is_not_a_return(): void {
        $t = BGCouriers_Pigeon::follow_chain(
            $this->fx('track-unclaimed-return-chain.json'), $this->fx('track-redirect-leg.json'));
        $this->assertSame('ready', $t->stage());
        $this->assertSame('458640894807', $t->waybill);
    }

    /**
     * Every code Pigeon publishes, pinned to the verdict we reach on it.
     *
     * Half of these have no entry in BGCouriers_Tracking::PHASES on purpose - the map lists only the ones
     * our text rules would get wrong - so for the rest the verdict is reached by reading Bulgarian prose
     * that Pigeon is free to reword whenever it likes. The pairs below are the code and the wording from
     * GET /v1/shipment-statuses on 2026-08-22; this test is what turns a rewording, or a change to the
     * classifier, into a failure here instead of an order that quietly stops being polled.
     *
     * Two verdicts are worth reading twice: 'ready' is NOT terminal (a parcel sitting unclaimed in an
     * office still has to be watched, which is the only reason we ever see it go back), and only
     * "Отказана" is allowed to be read as a cancellation - "Отхвърлено пренасочване" is a rejected
     * REDIRECT, and reading its "отхвърлено" as a cancellation would end tracking mid-journey.
     */
    public function test_every_published_status_code_lands_where_we_expect(): void {
        $expected = [
            ['shipment_registered',              'Създадена пратка от подател',  'registered'],
            ['shipment_awaiting_courier_pickup', 'Очаква товарене от куриер',    'registered'],
            ['shipment_courier_assigned',        'Взета от куриер',              'transit'],
            ['shipment_accepted_by_courier',     'Приета от куриер',             'transit'],
            ['shipment_accepted_in_office',      'Приета в офис',                'transit'],
            ['shipment_in_sorting_center',       'В сортировачен център',        'transit'],
            ['shipment_in_delivery',             'В процес на доставка',         'transit'],
            ['shipment_delivery_attempt_failed', 'Неуспешен опит за доставка',   'transit'],
            ['shipment_delivery_problem',        'Проблем при доставка',         'transit'],
            ['shipment_redirection_requested',   'Заявено пренасочване',         'transit'],
            ['shipment_redirection_rejected',    'Отхвърлено пренасочване',      'transit'],
            ['shipment_redirected',              'Пренасочена',                  'transit'],
            ['shipment_abandoned',               'Изоставена',                   'transit'],
            ['complaint',                        'Рекламация',                   'transit'],
            ['shipment_delivered_to_office',     'Доставена в офис/локър',       'ready'],
            ['shipment_left_in_locker',          'Оставена в локър',             'ready'],
            ['shipment_held_by_sender',          'Задържана в офис',             'ready'],
            ['shipment_untracked',               'Непотърсена',                  'ready'],
            ['shipment_locker_time_expired',     'Изтекъл престой в локъра',     'ready'],
            ['shipment_storage_expired',         'Изтекъл срок на съхранение',   'ready'],
            ['shipment_delivered_to_recipient',  'Взета от получателя',          'delivered'],
            ['shipment_cancelled',               'Отказана',                     'cancelled'],
            ['shipment_returning_to_sender',     'Връщане към подател',          'returning'],
            ['shipment_returned',                'Върната',                      'returned'],
        ];
        $this->assertCount(24, $expected, 'Pigeon publishes 24 codes - add the new one here first');
        foreach ($expected as [$code, $name, $stage]) {
            $t = new BGCouriers_Tracking('1', $name, [['code' => $code, 'name' => $name, 'date' => '']], $code, true, true);
            $this->assertSame($stage, $t->stage(), $code . ' / ' . $name);
        }
    }
}
