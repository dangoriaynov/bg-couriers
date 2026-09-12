<?php
/**
 * The tracking poll reaches every shipment in flight, not the oldest forty. One batch of forty, oldest
 * first, was the whole run - so on a shop with more than forty parcels out at once the newer ones were
 * never asked about until the older ones finished, and a run that could have taken a few seconds more
 * left them frozen on whatever the courier last said. The run now goes on in batches until nothing is
 * left or its time budget is spent; the budget is what keeps a cron run bounded, not the batch.
 *
 * @group core
 */
final class PollerReachesEveryShipmentTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::register('pollprobe', 'Probe', static function () { return new BGCouriers_Poll_Probe(); });
        BGCouriers_Poll_Probe::$asked = [];
        update_option('bgcouriers_tracking_poll', 'twicedaily');
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['pollprobe']); $r->setValue(null, $v);
        }
        remove_all_filters('bgcouriers_poll_budget');
        parent::tear_down();
    }

    private function shipments(int $n): array {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $o = new WC_Order();
            $o->update_meta_data('_bgcouriers_courier', 'pollprobe');
            $o->update_meta_data('_bgcouriers_method', 'office');
            $o->update_meta_data('_bgcouriers_waybill', 'W' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
            $o->save();
            $ids[] = $o->get_id();
        }
        return $ids;
    }

    public function test_forty_five_in_flight_are_all_asked_about_in_one_run(): void {
        $this->shipments(45);
        // And one that is finished: it carries a waybill, but the poll has marked it done. On the classic
        // order store the meta conditions used to be passed in a form WooCommerce flags, so this is
        // also the check that they are applied at all.
        $done = new WC_Order();
        $done->update_meta_data('_bgcouriers_courier', 'pollprobe');
        $done->update_meta_data('_bgcouriers_waybill', 'WDONE');
        $done->update_meta_data('_bgcouriers_track_done', '1');
        $done->save();

        BGCouriers_Tracking_Poller::run();
        $this->assertCount(45, BGCouriers_Poll_Probe::$asked, 'every shipment in flight, not the oldest forty');
        $this->assertCount(45, array_unique(BGCouriers_Poll_Probe::$asked), 'and each exactly once');
        $this->assertNotContains('WDONE', BGCouriers_Poll_Probe::$asked, 'a finished shipment is left alone');
    }

    public function test_the_time_budget_ends_a_run_that_is_not_finished(): void {
        $this->shipments(5);
        add_filter('bgcouriers_poll_budget', '__return_zero'); // no time at all: the first shipment, then stop
        BGCouriers_Tracking_Poller::run();
        $this->assertCount(1, BGCouriers_Poll_Probe::$asked, 'the budget is checked after each shipment');
    }
}

final class BGCouriers_Poll_Probe extends BGCouriers_Abstract_Courier {
    public static array $asked = [];
    public function id(): string { return 'pollprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { self::$asked[] = $w; return new BGCouriers_Tracking($w, 'In transit', [], 'transit'); }
    public function tracking_url(string $w): string { return ''; }
}
