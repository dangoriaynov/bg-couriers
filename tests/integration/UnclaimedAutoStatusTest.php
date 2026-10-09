<?php
/**
 * "Nobody came for it" end to end: the stage reaches the database, and the order moves to the status the
 * merchant chose for it - while the parcel is still AT the courier, which is the whole point. The return
 * rule next to it only fires once the box is back on the counter, and by then the sale has been lost for
 * a fortnight without the orders list ever saying so.
 *
 * Asked against real WooCommerce because the branch ends in update_status(), and a stubbed order cannot
 * tell "saved" from "set in memory".
 *
 * @group core
 */
final class UnclaimedAutoStatusTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
    }
    public function tear_down() {
        delete_option('bgcouriers_autostatus_on_unclaimed');
        delete_option('bgcouriers_unclaimed_days');
        parent::tear_down();
    }

    /** A courier that answers track() with exactly this, under an id of its own (the registry caches). */
    private function courier(string $id, BGCouriers_Tracking $t): void {
        $fake = new class($id, $t) implements BGCouriers_Courier_Interface {
            public function __construct(private string $cid, private BGCouriers_Tracking $t) {}
            public function track(string $w): BGCouriers_Tracking { return $this->t; }
            public function id(): string { return $this->cid; }
            public function label(): string { return 'Unclaimed Fake'; }
            public function capabilities(): array { return ['office']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return []; }
            public function fetch_offices(int $c): array { return []; }
            public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('not used'); }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $format = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function tracking_url(string $w): string { return ''; }
        };
        BGCouriers_Couriers::register($id, 'Unclaimed Fake', static function () use ($fake) { return $fake; });
    }

    /** A real order carrying a real waybill, as the poller finds one. */
    private function order(string $courier_id, array $meta = []): int {
        $o = new WC_Order();
        $o->set_status('processing');
        $o->update_meta_data('_bgcouriers_courier', $courier_id);
        $o->update_meta_data('_bgcouriers_waybill', 'W1');
        foreach ($meta as $k => $v) { $o->update_meta_data($k, $v); }
        $o->save();
        return $o->get_id();
    }

    /** Pigeon says it outright: "Непотърсена", and it says it from the first poll. */
    public function test_the_couriers_own_verdict_moves_the_order_at_once(): void {
        update_option('bgcouriers_autostatus_on_unclaimed', 'wc-failed');
        $this->courier('unclaimed_said', new BGCouriers_Tracking('W1', 'Непотърсена',
            [['code' => 'shipment_untracked', 'name' => 'Непотърсена', 'date' => '']],
            'shipment_untracked', true, true));
        $id = $this->order('unclaimed_said');

        BGCouriers_Tracking_Poller::refresh_one($id);

        $fresh = wc_get_order($id);
        $this->assertSame('failed', $fresh->get_status(), 'the merchant asked for this status');
        $this->assertSame('unclaimed', (string) $fresh->get_meta('_bgcouriers_track_stage'));
        // NOT finished: the parcel is still at the courier and what happens next (collected after all,
        // or sent back) is exactly what the shop needs to keep hearing about.
        $this->assertSame('', (string) $fresh->get_meta('_bgcouriers_track_done'));
    }

    /**
     * Speedy, Econt, Sameday and Express One publish no code for it - they keep saying "the parcel is at
     * the office" for as long as it stands there. So the days are the signal, and they are counted from
     * the moment it arrived.
     */
    public function test_a_parcel_left_waiting_too_long_is_flagged_on_the_clock(): void {
        update_option('bgcouriers_autostatus_on_unclaimed', 'wc-failed');
        update_option('bgcouriers_unclaimed_days', '7');
        $this->courier('unclaimed_clock', new BGCouriers_Tracking('W1', 'Известие за пратка',
            [['code' => '1134', 'name' => 'Известие за пратка', 'date' => '']], '', true, true));
        $id = $this->order('unclaimed_clock', ['_bgcouriers_track_times' => ['ready' => time() - 10 * DAY_IN_SECONDS]]);

        BGCouriers_Tracking_Poller::refresh_one($id);

        $fresh = wc_get_order($id);
        $this->assertSame('unclaimed', (string) $fresh->get_meta('_bgcouriers_track_stage'), 'ten days is nobody coming');
        $this->assertSame('failed', $fresh->get_status());
    }

    /** ...and a parcel that arrived this morning is simply waiting, which is not news at all. */
    public function test_a_parcel_that_has_just_arrived_is_left_alone(): void {
        update_option('bgcouriers_autostatus_on_unclaimed', 'wc-failed');
        update_option('bgcouriers_unclaimed_days', '7');
        $this->courier('unclaimed_fresh', new BGCouriers_Tracking('W1', 'Известие за пратка',
            [['code' => '1134', 'name' => 'Известие за пратка', 'date' => '']], '', true, true));
        $id = $this->order('unclaimed_fresh', ['_bgcouriers_track_times' => ['ready' => time() - DAY_IN_SECONDS]]);

        BGCouriers_Tracking_Poller::refresh_one($id);

        $fresh = wc_get_order($id);
        $this->assertSame('ready', (string) $fresh->get_meta('_bgcouriers_track_stage'));
        $this->assertSame('processing', $fresh->get_status(), 'nothing has gone wrong yet');
    }

    /** With no status chosen the stage is still recorded: the orders list shows it, the order does not move. */
    public function test_without_a_chosen_status_it_is_a_colour_and_a_note_only(): void {
        $this->courier('unclaimed_note', new BGCouriers_Tracking('W1', 'Непотърсена',
            [['code' => 'shipment_untracked', 'name' => 'Непотърсена', 'date' => '']],
            'shipment_untracked', true, true));
        $id = $this->order('unclaimed_note');

        BGCouriers_Tracking_Poller::refresh_one($id);

        $fresh = wc_get_order($id);
        $this->assertSame('processing', $fresh->get_status(), 'off by default - the merchant decides');
        $this->assertSame('unclaimed', (string) $fresh->get_meta('_bgcouriers_track_stage'));
    }
}
