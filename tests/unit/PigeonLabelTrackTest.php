<?php
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';

/**
 * Pigeon label and tracking parsers. Tracking fixtures are live payloads except track-office.json and
 * track-redirect-leg.json, assembled from Pigeon's own status list.
 *
 * @group pigeon
 */
final class PigeonLabelTrackTest extends BGCouriers_Unit_TestCase {
    private function fx(string $f): array {
        return $this->fixture('pigeon', $f);
    }
    private function track(string $f = 'track.json'): BGCouriers_Tracking {
        return BGCouriers_Pigeon::parse_tracking($this->fx($f));
    }

    public function test_parse_shipment_id_returns_reference_number(): void {
        $this->assertSame('455791936383', BGCouriers_Pigeon::parse_shipment_id($this->fx('create.json')));
    }

    public function test_parse_tracking_reads_the_history_under_tracking(): void {
        $t = $this->track();
        $this->assertSame('458640894807', $t->waybill);
        $this->assertSame('В сортировачен център', $t->status);
        $this->assertCount(4, $t->events);
        $this->assertSame(['code' => 'shipment_registered', 'name' => 'Регистрирана пратка', 'date' => '2026-08-10T15:10:27+03:00'],
            array_intersect_key($t->events[0], ['code' => 1, 'name' => 1, 'date' => 1]));
        $this->assertSame(['code' => 'shipment_in_sorting_center', 'name' => 'В сортировачен център', 'date' => '2026-08-12T22:12:57+03:00'],
            array_intersect_key($t->events[3], ['code' => 1, 'name' => 1, 'date' => 1]));
    }

    public function test_parse_tracking_passes_the_status_code_as_the_phase(): void {
        $this->assertSame('shipment_in_sorting_center', $this->track()->phase);
        $this->assertSame('shipment_registered', $this->track('track-registered.json')->phase);
    }

    // ── Stage ───────────────────────────────────────────────────────────────

    public function test_a_moving_parcel_is_in_transit(): void {
        $t = $this->track();
        $this->assertSame('transit', $t->stage());
        $this->assertTrue($t->handover, 'the courier plainly has it');
    }

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

    /** Arrived at the pickup point is not in the customer's hands. */
    public function test_delivered_to_the_office_is_waiting_for_collection_not_delivered(): void {
        $this->assertSame('ready', $this->track('track-office.json')->stage());
    }

    // ── Returns: the outward waybill freezes at "unclaimed" and the journey home is a chained second waybill.

    public function test_chain_after_names_the_shipment_that_carries_on(): void {
        $this->assertSame('458824370227',
            BGCouriers_Pigeon::chained_ref($this->fx('track-unclaimed-return-chain.json')));
    }

    /** The newest leg is decided by instant, not by text order or array order. */
    public function test_the_newest_leg_wins_across_the_clock_change(): void {
        $resp = ['data' => ['chain_after' => [
            ['reference_number' => 'earlier', 'created_at' => '2026-10-25T03:30:00+03:00'], // 00:30 UTC
            ['reference_number' => 'later',   'created_at' => '2026-10-25T03:00:00+02:00'], // 01:00 UTC
        ]]];
        $this->assertSame('later', BGCouriers_Pigeon::chained_ref($resp));
        $this->assertSame('later', BGCouriers_Pigeon::chained_ref(
            ['data' => ['chain_after' => array_reverse($resp['data']['chain_after'])]]));
    }

    public function test_a_leg_with_no_number_never_displaces_a_real_one(): void {
        $blank = ['reference_number' => '',             'created_at' => '2026-08-22T10:00:00+03:00'];
        $real  = ['reference_number' => '458824370227', 'created_at' => '2026-08-21T06:00:05+03:00'];
        $this->assertSame('458824370227', BGCouriers_Pigeon::chained_ref(['data' => ['chain_after' => [$blank, $real]]]));
        $this->assertSame('458824370227', BGCouriers_Pigeon::chained_ref(['data' => ['chain_after' => [$real, $blank]]]));
        $this->assertSame('', BGCouriers_Pigeon::chained_ref(
            ['data' => ['chain_after' => [['created_at' => '2026-08-22T10:00:00+03:00']]]]));
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

    /** On the return leg "ready for collection" means the parcel is back at the shop's office. */
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

    /** Only a chain opened by "returning to the sender" is a journey home; a redirect keeps going forward. */
    public function test_a_redirected_parcel_is_not_a_return(): void {
        $t = BGCouriers_Pigeon::follow_chain(
            $this->fx('track-unclaimed-return-chain.json'), $this->fx('track-redirect-leg.json'));
        $this->assertSame('transit', $t->stage(), 'on its way to the new address, not unclaimed');
        $this->assertNotSame('returned', $t->stage());
        $this->assertSame('458640894807', $t->waybill, 'the number the shop booked');
        $this->assertSame('В процес на доставка', $t->status);
    }

    /** @return array<string,array{string,string,string}> every code Pigeon publishes => our stage */
    public static function published_statuses(): array {
        $rows = [
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
            ['shipment_untracked',               'Непотърсена',                  'unclaimed'],
            ['shipment_locker_time_expired',     'Изтекъл престой в локъра',     'unclaimed'],
            ['shipment_storage_expired',         'Изтекъл срок на съхранение',   'unclaimed'],
            ['shipment_delivered_to_recipient',  'Взета от получателя',          'delivered'],
            ['shipment_cancelled',               'Отказана',                     'cancelled'],
            ['shipment_returning_to_sender',     'Връщане към подател',          'returning'],
            ['shipment_returned',                'Върната',                      'returned'],
        ];
        return array_combine(array_column($rows, 0), $rows);
    }

    /**
     * 'ready' is not terminal, and only "refused" may read as a cancellation.
     *
     * @dataProvider published_statuses
     */
    public function test_every_published_status_code_lands_where_we_expect(string $code, string $name, string $stage): void {
        $t = new BGCouriers_Tracking('1', $name, [['code' => $code, 'name' => $name, 'date' => '']], $code, true, true);
        $this->assertSame($stage, $t->stage());
    }
}
