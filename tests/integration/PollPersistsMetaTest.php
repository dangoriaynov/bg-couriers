<?php
/**
 * Everything a poll decides must reach the DATABASE, not just the order object in memory.
 *
 * The poller writes the order once per answer now instead of up to five times, which means several
 * pieces of meta are set and left pending until something flushes them - and on the branches that
 * change the order's status, the thing that flushes them is WooCommerce's own update_status(). That is
 * a real method with real early returns, and a stubbed order cannot tell "saved" from "set in memory".
 * So this asks real WooCommerce and a real database: poll, then re-read the order from scratch.
 *
 * The meta that matters most here is `_bgcouriers_shipped_marked`. It is the "only ever once" guard on
 * the shipped status, it is written inside mark_shipped() immediately before update_status(), and if it
 * were not flushed the order would be moved to Shipped again on every single poll, with an order note
 * each time.
 *
 * @group core
 */
final class PollPersistsMetaTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
    }
    public function tear_down() {
        delete_option('bgcouriers_autostatus_on_delivered');
        delete_option('bgcouriers_autostatus_on_shipped');
        parent::tear_down();
    }

    /**
     * A courier that answers track() with exactly this.
     *
     * Registered under an id of its OWN, one per test. BGCouriers_Couriers::get() caches the instance it
     * built, so re-registering a factory under an id another test already asked for hands this test the
     * PREVIOUS test's courier - which is how two of these first passed against the wrong tracking and
     * looked like plugin faults. Resetting the registry instead is not an option: other integration
     * tests rely on the couriers registered at boot.
     */
    private function courier(string $id, BGCouriers_Tracking $t): void {
        $fake = new class($id, $t) implements BGCouriers_Courier_Interface {
            public function __construct(private string $cid, private BGCouriers_Tracking $t) {}
            public function track(string $w): BGCouriers_Tracking { return $this->t; }
            public function id(): string { return $this->cid; }
            public function label(): string { return 'Poll Fake'; }
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
        BGCouriers_Couriers::register($id, 'Poll Fake', static function () use ($fake) { return $fake; });
    }

    /** A real order carrying a real waybill, as the poller finds one. */
    private function order(string $courier_id): int {
        $o = new WC_Order();
        $o->set_status('processing');
        $o->update_meta_data('_bgcouriers_courier', $courier_id);
        $o->update_meta_data('_bgcouriers_waybill', 'W1');
        $o->save();
        return $o->get_id();
    }

    /**
     * The delivered branch: the poll sets the handover flag and the finished flag, then hands off to
     * update_status() - which is the only thing that writes them.
     */
    public function test_the_delivered_branch_flushes_everything_it_set(): void {
        update_option('bgcouriers_autostatus_on_delivered', 'wc-completed');
        $this->courier('pollfake_delivered', new BGCouriers_Tracking('W1', 'Доставена',
            [['code' => '-14', 'name' => 'Доставена', 'date' => '']], '', true, true));
        $id = $this->order('pollfake_delivered');

        BGCouriers_Tracking_Poller::refresh_one($id);

        // Read it back from the database, not from the object the poll was holding.
        $fresh = wc_get_order($id);
        $this->assertSame('completed', $fresh->get_status(), 'the status moved');
        $this->assertSame('yes', (string) $fresh->get_meta('_bgcouriers_handover'), 'the handover flag reached the database');
        $this->assertSame('yes', (string) $fresh->get_meta('_bgcouriers_track_done'), 'and the finished flag');
        $this->assertSame('delivered', (string) $fresh->get_meta('_bgcouriers_track_stage'), 'and the stage the admin shows');
        $this->assertSame('Доставена', (string) $fresh->get_meta('_bgcouriers_track_text'));
    }

    /**
     * The shipped branch, and the guard that stops it firing twice. mark_shipped() writes
     * `_bgcouriers_shipped_marked` and then calls update_status(); if that write did not persist, a
     * second poll would move the order again and note it again.
     */
    public function test_the_shipped_mark_persists_so_it_only_ever_fires_once(): void {
        update_option('bgcouriers_autostatus_on_shipped', 'wc-on-hold');
        $this->courier('pollfake_shipped', new BGCouriers_Tracking('W1', 'Приета от куриер',
            [['code' => '1', 'name' => 'a', 'date' => ''], ['code' => '2', 'name' => 'b', 'date' => '']], '', true, true));
        $id = $this->order('pollfake_shipped');

        BGCouriers_Tracking_Poller::refresh_one($id);

        $fresh = wc_get_order($id);
        $this->assertSame('on-hold', $fresh->get_status(), 'the courier took the parcel, so the order moved');
        $this->assertSame('yes', (string) $fresh->get_meta('_bgcouriers_shipped_marked'),
            'the only-ever-once guard reached the database');

        // Put the merchant's own status back and poll again: the guard must hold.
        $fresh->set_status('processing');
        $fresh->save();
        BGCouriers_Tracking_Poller::refresh_one($id);
        $this->assertSame('processing', wc_get_order($id)->get_status(),
            'a shipment already marked shipped is never marked again');
    }

    /** The ordinary branch, where no status changes and poll_one does its own single save. */
    public function test_an_ordinary_poll_persists_what_it_recorded(): void {
        $this->courier('pollfake_ordinary', new BGCouriers_Tracking('W2', 'Приета от куриер',
            [['code' => '1', 'name' => 'a', 'date' => ''], ['code' => '2', 'name' => 'b', 'date' => '']], '', true, true));
        $id = $this->order('pollfake_ordinary');

        BGCouriers_Tracking_Poller::refresh_one($id);

        $fresh = wc_get_order($id);
        $this->assertSame('processing', $fresh->get_status(), 'no auto-status is configured, so nothing moved');
        $this->assertSame('yes', (string) $fresh->get_meta('_bgcouriers_handover'));
        $this->assertSame('W2', (string) $fresh->get_meta('_bgcouriers_return_waybill'),
            'a parcel coming back under a new number is recorded');
        $this->assertSame('Приета от куриер', (string) $fresh->get_meta('_bgcouriers_track_text'));
        $this->assertSame('transit', (string) $fresh->get_meta('_bgcouriers_track_stage'));
        $this->assertSame('W1', (string) $fresh->get_meta('_bgcouriers_waybill'), 'the order keeps its own waybill');
    }
}
