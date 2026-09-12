<?php
/**
 * The automatic waybill can be issued on the day the order says it ships, not the moment it is paid.
 *
 * The shop's orders carry a dispatch day - "Изпращане в", shown at the checkout - and auto-label knew
 * nothing of it: it issued the waybill on the status change, seconds after checkout. Measured on prod
 * on 2026-09-12: four Speedy waybills registered on 4, 6, 9 and 11 September, still "information
 * received" a week later, for orders that all say they ship on 2 October - a closure the shop had set,
 * and a live shipment at the courier for a month, with a customer page saying "Track this parcel" the
 * whole time. On 2026-08-26 the same gap cost a Sameday collection: the courier came the day the
 * waybill was made, not the day the order said, found nothing, and voided it. And on 2 September the
 * same shop shipped an order straight through that closure, the day after it was placed.
 *
 * So whether the day is honoured is a setting: on for a shop set up today, and for one that already
 * exists the answer it had - at once - is written down until the merchant ticks the box. The day comes
 * from the order - the Order Delivery Date plugin's own stamp is read, and any other source can answer
 * through the bgcouriers_dispatch_time filter - and the waybill is scheduled for the morning of that
 * day. An appointment that fires weeks later checks the order is still going out at all, and an
 * edited day moves it.
 *
 * @group core
 */
final class AutoLabelWaitsForDispatchDayTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        BGCouriers_Dispatch_Probe::$created = [];
        BGCouriers_Couriers::register('dispprobe', 'Probe', static function () { return new BGCouriers_Dispatch_Probe(); });
        update_option('bgcouriers_dispprobe_enabled', 'yes');
        update_option('bgcouriers_dispprobe_autolabel', 'yes');
        update_option('bgcouriers_autolabel_enabled', 'yes');
        update_option('bgcouriers_autolabel_status', 'wc-processing');
        update_option('bgcouriers_autolabel_wait', 'yes');
        update_option('timezone_string', 'Europe/Sofia');
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['dispprobe']); $r->setValue(null, $v);
        }
        remove_all_filters('bgcouriers_dispatch_time');
        parent::tear_down();
    }

    private function order(?int $dispatch_midnight): WC_Order {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'dispprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        if ($dispatch_midnight !== null) {
            // What Order Delivery Date writes: the day, as a unix stamp of its midnight.
            $o->update_meta_data('_orddd_timestamp', $dispatch_midnight);
            $o->update_meta_data('_orddd_delivery_date', wp_date('l j F', $dispatch_midnight));
        }
        $o->set_status('pending');
        $o->save();
        return $o;
    }

    public function test_an_order_shipping_in_three_weeks_gets_no_waybill_today(): void {
        $day = strtotime('+21 days 00:00:00', time());
        $o   = $this->order($day);
        $o->set_status('processing'); $o->save(); // the auto-label trigger

        $this->assertSame([], BGCouriers_Dispatch_Probe::$created, 'nothing at the courier yet');
        $this->assertSame('', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_waybill'));
        $at = wp_next_scheduled(BGCouriers_Labels::RETRY_HOOK, [$o->get_id()]);
        $this->assertNotFalse($at, 'the waybill is scheduled instead');
        $this->assertSame(wp_date('Y-m-d', $day), wp_date('Y-m-d', $at), 'for the dispatch day');
        $this->assertSame('07:00', wp_date('H:i', $at), 'in the morning, before any courier comes');
        $notes = array_map(static fn($n) => $n->content, wc_get_order_notes(['order_id' => $o->get_id()]));
        $this->assertNotEmpty(array_filter($notes, static fn($n) => strpos($n, wp_date(get_option('date_format'), $at)) !== false), 'and the order says so');
    }

    public function test_the_scheduled_morning_issues_it(): void {
        $day = strtotime('+21 days 00:00:00', time());
        $o   = $this->order($day);
        $o->set_status('processing'); $o->save();
        // Time passes. The dispatch day is here: the retry hook fires, and the day is no longer ahead.
        add_filter('bgcouriers_dispatch_time', static fn() => time() - 60);
        BGCouriers_Labels::attempt_auto_label($o->get_id());
        $this->assertCount(1, BGCouriers_Dispatch_Probe::$created, 'issued on the day');
    }

    /**
     * Cancelled, paid back, never paid, or done by hand: the appointment fires, and books nothing. The
     * completed case is the one that would cost a real shipment - on the shop this was measured on the
     * poller completes an order once the courier has DELIVERED it, so completed with no waybill means
     * the merchant shipped it themselves, and a waybill weeks later would be a second parcel.
     */
    public function test_an_order_that_no_longer_ships_gets_no_waybill_when_its_day_comes(): void {
        foreach (['cancelled', 'refunded', 'failed', 'completed', 'on-hold', 'pending'] as $status) {
            BGCouriers_Dispatch_Probe::$created = [];
            $day = strtotime('+21 days 00:00:00', time());
            $o   = $this->order($day);
            $o->set_status('processing'); $o->save();
            $this->assertNotSame('', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_autolabel_at'), 'waiting');
            $o->set_status($status); $o->save();
            add_filter('bgcouriers_dispatch_time', static fn() => time() - 60);
            BGCouriers_Labels::attempt_auto_label($o->get_id());
            remove_all_filters('bgcouriers_dispatch_time');
            $this->assertSame([], BGCouriers_Dispatch_Probe::$created, "a $status order ships nothing");
            $this->assertSame('', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_autolabel_at'), 'and the promise of a day is withdrawn');
        }
    }

    /** The trigger status is never in the way of itself, whichever the shop chose. */
    public function test_a_shop_that_labels_on_hold_orders_still_does(): void {
        update_option('bgcouriers_autolabel_status', 'wc-on-hold');
        $o = $this->order(null);
        $o->set_status('on-hold'); $o->save();
        $this->assertCount(1, BGCouriers_Dispatch_Probe::$created, 'on-hold is the trigger here, not a dead end');
    }

    /** With the setting off, the day on the order changes nothing - the waybill is issued at once. */
    public function test_with_the_wait_switched_off_the_waybill_is_issued_at_once(): void {
        update_option('bgcouriers_autolabel_wait', 'no');
        $day = strtotime('+21 days 00:00:00', time());
        $o   = $this->order($day);
        $o->set_status('processing'); $o->save();
        $this->assertCount(1, BGCouriers_Dispatch_Probe::$created, 'issued now, as before');
        $this->assertFalse(wp_next_scheduled(BGCouriers_Labels::RETRY_HOOK, [$o->get_id()]), 'nothing scheduled');
        $this->assertSame('', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_autolabel_at'));
    }

    /**
     * An install that already existed keeps what it had. The pin runs once, on the first load after
     * the update, before the new default can be reached; a fresh install is left with the default.
     */
    public function test_an_existing_install_is_pinned_to_issuing_at_once_and_a_new_one_waits(): void {
        $pin = new ReflectionMethod('BGCouriers_Plugin', 'pin_autolabel_wait');
        $pin->setAccessible(true);

        delete_option('bgcouriers_autolabel_wait');
        delete_option('bgcouriers_autolabel_wait_pinned');
        $pin->invoke(null, false);
        $this->assertSame('no', get_option('bgcouriers_autolabel_wait'), 'an existing install: at once, as it was');
        $this->assertFalse(BGCouriers_Settings::autolabel_wait());

        // A merchant who has since ticked the box is not un-ticked by a later load.
        update_option('bgcouriers_autolabel_wait', 'yes');
        $pin->invoke(null, false);
        $this->assertSame('yes', get_option('bgcouriers_autolabel_wait'), 'pinned once, never again');

        // An existing install whose merchant had ALREADY ticked the box before the pin ran keeps that.
        delete_option('bgcouriers_autolabel_wait_pinned');
        $pin->invoke(null, false);
        $this->assertSame('yes', get_option('bgcouriers_autolabel_wait'), 'a saved answer is never overwritten');

        // And a shop being set up right now gets the new default.
        delete_option('bgcouriers_autolabel_wait');
        delete_option('bgcouriers_autolabel_wait_pinned');
        $pin->invoke(null, true);
        $this->assertSame('', (string) get_option('bgcouriers_autolabel_wait', ''), 'nothing written: the default stands');
        $this->assertTrue(BGCouriers_Settings::autolabel_wait(), 'and the default is to wait');
    }

    public function test_a_dispatch_day_already_here_or_gone_issues_at_once(): void {
        foreach ([strtotime('today 00:00:00'), strtotime('-3 days 00:00:00')] as $day) {
            BGCouriers_Dispatch_Probe::$created = [];
            $o = $this->order($day);
            $o->set_status('processing'); $o->save();
            $this->assertCount(1, BGCouriers_Dispatch_Probe::$created, 'today, or a day that has passed: now');
        }
    }

    public function test_an_order_with_no_dispatch_day_is_issued_as_before(): void {
        $o = $this->order(null);
        $o->set_status('processing'); $o->save();
        $this->assertCount(1, BGCouriers_Dispatch_Probe::$created);
    }

    public function test_a_changed_dispatch_day_moves_the_appointment(): void {
        $day = strtotime('+21 days 00:00:00', time());
        $o   = $this->order($day);
        $o->set_status('processing'); $o->save();
        $first = wp_next_scheduled(BGCouriers_Labels::RETRY_HOOK, [$o->get_id()]);
        // The merchant moves the day. When the old appointment fires, it re-reads the order and waits again.
        $later = strtotime('+30 days 00:00:00', time());
        $o = wc_get_order($o->get_id()); $o->update_meta_data('_orddd_timestamp', $later); $o->save();
        BGCouriers_Labels::attempt_auto_label($o->get_id());
        $this->assertSame([], BGCouriers_Dispatch_Probe::$created);
        $second = wp_next_scheduled(BGCouriers_Labels::RETRY_HOOK, [$o->get_id()]);
        $this->assertSame(wp_date('Y-m-d', $later), wp_date('Y-m-d', $second), 'one appointment, on the new day');
        $this->assertNotSame($first, $second);
    }

    /**
     * A day moved EARLIER is the case the firing appointment cannot repair on its own: it would fire
     * on the old day and find the new one gone by. So the Update button on the order looks again.
     */
    public function test_a_day_moved_earlier_on_the_order_screen_moves_the_appointment_earlier(): void {
        $day = strtotime('+21 days 00:00:00', time());
        $o   = $this->order($day);
        $o->set_status('processing'); $o->save();
        $sooner = strtotime('+3 days 00:00:00', time());
        $o = wc_get_order($o->get_id()); $o->update_meta_data('_orddd_timestamp', $sooner); $o->save();
        do_action('woocommerce_process_shop_order_meta', $o->get_id(), null);
        $this->assertSame([], BGCouriers_Dispatch_Probe::$created, 'still ahead, still waiting');
        $at = wp_next_scheduled(BGCouriers_Labels::RETRY_HOOK, [$o->get_id()]);
        $this->assertSame(wp_date('Y-m-d', $sooner), wp_date('Y-m-d', $at), 'on the new, earlier day');
        $this->assertSame($at, (int) wc_get_order($o->get_id())->get_meta('_bgcouriers_autolabel_at'));

        // Moved to today: issued on the spot.
        $o = wc_get_order($o->get_id()); $o->update_meta_data('_orddd_timestamp', strtotime('today 00:00:00')); $o->save();
        do_action('woocommerce_process_shop_order_meta', $o->get_id(), null);
        $this->assertCount(1, BGCouriers_Dispatch_Probe::$created, 'today: now');
        $this->assertSame('', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_autolabel_at'));
    }

    /**
     * The stamp names a calendar day, and the day must come out the same in any zone. On the shop this
     * was measured on the stamp is a UTC midnight (1790899200, "2 October"), which read in a zone west
     * of Greenwich is the evening of 1 October; a plugin stamping the LOCAL midnight of a Sofia shop
     * writes 21:00 UTC of the day before. Both must name the 2nd, and 07:00 of it in the site's zone.
     */
    public function test_the_stamp_names_the_same_day_in_every_zone(): void {
        $utc_midnight   = 1790899200;                 // 2026-10-02 00:00:00 UTC, what Order Delivery Date wrote
        $sofia_midnight = $utc_midnight - 3 * 3600;   // 2026-10-01 21:00:00 UTC, the same day's local midnight
        foreach (['Europe/Sofia', 'America/New_York', 'Asia/Tokyo'] as $zone) {
            update_option('timezone_string', $zone);
            foreach ([$utc_midnight, $sofia_midnight] as $stamp) {
                $o = new WC_Order();
                $o->update_meta_data('_orddd_timestamp', $stamp);
                $o->save();
                $at = BGCouriers_Labels::dispatch_time($o);
                $this->assertSame('2026-10-02 07:00', wp_date('Y-m-d H:i', $at), "stamp $stamp read in $zone");
            }
        }
        update_option('timezone_string', 'Europe/Sofia');
    }

    /** An order with no appointment is not touched by the Update button - the hook is not a second trigger. */
    public function test_the_update_button_does_not_label_an_order_that_was_never_scheduled(): void {
        update_option('bgcouriers_dispprobe_autolabel', 'no');
        $o = $this->order(null);
        $o->set_status('processing'); $o->save();
        do_action('woocommerce_process_shop_order_meta', $o->get_id(), null);
        $this->assertSame([], BGCouriers_Dispatch_Probe::$created);
    }
}

final class BGCouriers_Dispatch_Probe extends BGCouriers_Abstract_Courier {
    public static array $created = [];
    public function id(): string { return 'dispprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { self::$created[] = $o->get_id(); return new BGCouriers_Label('D' . count(self::$created)); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
