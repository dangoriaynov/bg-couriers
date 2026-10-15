<?php
defined('ABSPATH') || exit;

/** WP-Cron poller for couriers without webhooks; records status changes and optionally advances the order. */
class BGCouriers_Tracking_Poller {
    const HOOK = 'bgcouriers_poll_tracking';

    private static function freq(): string {
        $f = (string) get_option('bgcouriers_tracking_poll', 'twicedaily');
        return in_array($f, ['off', 'bgcouriers_30min', 'hourly', 'bgcouriers_6h', 'twicedaily', 'daily'], true) ? $f : 'twicedaily';
    }

    public static function schedule(): void {
        $freq = self::freq();
        $next = wp_next_scheduled(self::HOOK);
        if ($freq === 'off') { if ($next) { wp_unschedule_event($next, self::HOOK); } return; }
        if ($next) {
            $ev = wp_get_scheduled_event(self::HOOK);
            if ($ev && ($ev->schedule ?? '') !== $freq) { wp_unschedule_event($next, self::HOOK); $next = false; }
        }
        if (!$next) { wp_schedule_event(time() + 300, $freq, self::HOOK); }
    }

    /** Shipments asked about per query. A page size, not a cap - see run(). */
    const BATCH = 40;
    /** Seconds a run may spend asking couriers; batches continue until nothing is left or this is spent. */
    const BUDGET = 20;

    public static function run(): void {
        if (self::freq() === 'off' || !function_exists('wc_get_orders')) { return; }
        $advance  = (string) get_option('bgcouriers_autostatus_on_delivered', '');
        $deadline = microtime(true) + max(0, (int) apply_filters('bgcouriers_poll_budget', self::BUDGET));
        $seen     = [];
        do {
            $orders = self::in_flight($seen);
            foreach ($orders as $order) {
                if (!$order instanceof \WC_Order) { continue; }
                $seen[] = $order->get_id();
                if (self::close_if_final($order)) { continue; }
                self::poll_one($order, $advance);
                if (microtime(true) >= $deadline) { return; }
            }
        } while (count($orders) === self::BATCH);
    }

    private static function close_if_final(\WC_Order $order): bool {
        if (!BGCouriers_Tracking::is_final((string) $order->get_meta('_bgcouriers_track_stage'))) { return false; }
        $order->update_meta_data('_bgcouriers_track_done', 'yes');
        $order->save();
        return true;
    }

    /**
     * Next batch still in flight, oldest first, skipping ids already asked this run.
     * HPOS takes a meta_query; the classic store needs it through its own filter (WC 9.2+ warns otherwise).
     * @param int[] $seen
     * @return \WC_Order[]
     */
    private static function in_flight(array $seen): array {
        $args = [
            'type'         => 'shop_order',
            // Without this the query uses WC's own status list, which lacks the plugin's shipped status.
            'status'       => 'any',
            'limit'        => self::BATCH,
            'orderby'      => 'date',
            'order'        => 'ASC',
            'exclude'      => $seen, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
            'date_created' => '>' . (time() - 45 * DAY_IN_SECONDS),
        ];
        $meta = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cron, batched + age-bounded
            'relation' => 'AND',
            ['key' => '_bgcouriers_waybill', 'value' => '', 'compare' => '!='],
            ['key' => '_bgcouriers_track_done', 'compare' => 'NOT EXISTS'],
        ];
        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        if ($hpos) {
            $args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cron, batched + age-bounded
            return array_values(array_filter(wc_get_orders($args), static function ($o) { return $o instanceof \WC_Order; }));
        }
        $inject = static function ($query) use ($meta) { $query['meta_query'] = $meta; return $query; }; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cron, batched + age-bounded
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', $inject);
        try { $orders = wc_get_orders($args); }
        finally { remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $inject); }
        return array_values(array_filter($orders, static function ($o) { return $o instanceof \WC_Order; }));
    }

    /** Re-read one order's tracking now, through the same path as the cron; false when it has no waybill of ours. */
    public static function refresh_one(int $order_id): bool {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) { return false; }
        if ((string) $order->get_meta('_bgcouriers_waybill') === '') { return false; }
        // Also un-freezes a shipment written off as finished: the merchant thinks the recorded state is wrong.
        $order->delete_meta_data('_bgcouriers_track_done');
        $order->save();
        self::poll_one($order, (string) get_option('bgcouriers_autostatus_on_delivered', ''));
        return true;
    }

    private static function poll_one(\WC_Order $order, string $advance): void {
        $cid = (string) $order->get_meta('_bgcouriers_courier');
        $wb  = (string) $order->get_meta('_bgcouriers_waybill');
        if ($cid === '' || $wb === '') { return; }
        $courier = BGCouriers_Couriers::get($cid);
        if (!$courier) { return; }
        if (method_exists($courier, 'pushes_tracking') && $courier->pushes_tracking()) { return; }
        // \Throwable, not \Exception: an Error from one adapter must not stop the whole batch.
        try { $t = $courier->track($wb); }
        catch (\Throwable $e) {
            if (!($e instanceof \Exception)) {
                BGCouriers_Logger::debug('tracking: a broken adapter, skipped', ['courier' => $cid, 'err' => $e->getMessage()]);
            }
            return;
        }
        self::record($order, $t, $courier->label(), $advance);
    }

    /** Record one answer about a shipment on its order; the webhook brings its answer here too. */
    public static function record(\WC_Order $order, BGCouriers_Tracking $t, string $label, string $advance): void {
        $wb = (string) $order->get_meta('_bgcouriers_waybill');

        // One answer is one save of the order; the flag decides whether there is anything to flush.
        $dirty = false;

        // Before the change check: the waybill lock needs this even on a poll where nothing else moved.
        if ($t->handover === true && (string) $order->get_meta('_bgcouriers_handover') !== 'yes') {
            $order->update_meta_data('_bgcouriers_handover', 'yes');
            $dirty = true;
        }

        // Pigeon carries a return under a new waybill; the order's own waybill is left alone (label, cancel and lock key on it).
        if ($t->waybill !== '' && $t->waybill !== $wb
            && (string) $order->get_meta('_bgcouriers_return_waybill') !== $t->waybill) {
            $order->update_meta_data('_bgcouriers_return_waybill', $t->waybill);
            /* translators: 1: courier name, 2: the waybill number the return travels under */
            $order->add_order_note(sprintf(__('%1$s: the parcel is coming back under a new waybill - %2$s.', 'bg-couriers'),
                $label, $t->waybill));
            $dirty = true;
        }

        $stage = self::derive_unclaimed($order, $t->stage());
        // Mark finished before the change check, or long-finished orders are re-polled forever; 'returning' is not terminal.
        if (BGCouriers_Tracking::is_final($stage)
            && (string) $order->get_meta('_bgcouriers_track_done') !== 'yes') {
            $order->update_meta_data('_bgcouriers_track_done', 'yes');
            $dirty = true;
        }

        // Refreshed on every poll: derived from our rules, which change.
        $human = $t->human();
        // "UNKNOWN" is the parsers' fallback for an unreadable answer: record nothing from such a poll.
        if ($human === '' || strcasecmp(trim($human), 'UNKNOWN') === 0) {
            if ($dirty) { $order->save(); }
            return;
        }
        $stored_stage = (string) $order->get_meta('_bgcouriers_track_stage');
        if ($human !== '' && ($stored_stage !== $stage || (string) $order->get_meta('_bgcouriers_track_text') !== $human)) {
            // Written only on a real change of stage, so a poll that finds nothing writes nothing.
            if ($stored_stage !== $stage) { self::remember_stage_time($order, $stage, $t); }
            $order->update_meta_data('_bgcouriers_track_text', $human);
            $order->update_meta_data('_bgcouriers_track_stage', $stage);
            $order->update_meta_data('_bgcouriers_track_updated', time());
            $dirty = true;
        }

        $key = $t->status;
        // The stage is checked too: Pigeon words the end of a return exactly like the end of a delivery.
        if (($key === '' || $key === (string) $order->get_meta('_bgcouriers_track_status'))
            && $stage === $stored_stage) {
            if ($dirty) { $order->save(); }
            return;
        }
        // _track_status is the courier's own key, used to detect change, never shown.
        $order->update_meta_data('_bgcouriers_track_status', $key);
        $order->update_meta_data('_bgcouriers_track_updated', time());
        $order->add_order_note(BGCouriers_Tracking::status_note($label, $stage, $human));

        // Only on 'returned': while still travelling back nothing has been recovered yet.
        $back = (string) get_option('bgcouriers_autostatus_on_returned', '');
        $back = strpos($back, 'wc-') === 0 ? substr($back, 3) : $back;
        if ($stage === 'returned' && $back !== '' && $order->get_status() !== $back) {
            $order->update_status($back, __('BG Couriers: the parcel came back to you (auto status).', 'bg-couriers'));
            return;
        }

        $stuck = (string) get_option('bgcouriers_autostatus_on_unclaimed', '');
        $stuck = strpos($stuck, 'wc-') === 0 ? substr($stuck, 3) : $stuck;
        if ($stage === 'unclaimed' && $stuck !== '' && $order->get_status() !== $stuck) {
            $order->update_status($stuck, __('BG Couriers: nobody has collected the parcel (auto status).', 'bg-couriers'));
            return;
        }

        $target = strpos($advance, 'wc-') === 0 ? substr($advance, 3) : $advance;
        if ($stage === 'delivered' && $target !== '' && $order->get_status() !== $target) {
            $order->update_status($target, __('BG Couriers: shipment delivered (auto status).', 'bg-couriers'));
            return;
        }
        if (in_array($stage, ['transit', 'ready', 'unclaimed', 'returning'], true) && self::mark_shipped($order, $t)) { return; }
        $order->save();
    }

    /** Days a parcel may wait before "nobody came", 0 when off; capped below the 45-day polling window. */
    public static function unclaimed_days(): int {
        $d = (int) get_option('bgcouriers_unclaimed_days', 7);
        return $d > 0 ? min($d, 45) : 0;
    }

    /** 'unclaimed' for a 'ready' parcel that has waited longer than the limit; derived, never applied to other stages. */
    public static function derive_unclaimed(\WC_Order $order, string $stage): string {
        if ($stage !== 'ready') { return $stage; }
        $days = self::unclaimed_days();
        if ($days === 0) { return $stage; }
        $since = self::stage_since($order, 'ready');
        if ($since <= 0) { return $stage; }
        return (time() - $since) >= $days * DAY_IN_SECONDS ? 'unclaimed' : $stage;
    }

    /** When the shipment reached a stage, unix time; falls back to the last change when no stamp exists. */
    private static function stage_since(\WC_Order $order, string $stage): int {
        $times = $order->get_meta('_bgcouriers_track_times');
        $when  = is_array($times) ? (int) ($times[$stage] ?? 0) : 0;
        if ($when <= 0) { $when = (int) $order->get_meta('_bgcouriers_track_updated'); }
        return $when;
    }

    /** Stamp the moment a stage was first reached; true when something was written. */
    public static function remember_stage_time(\WC_Order $order, string $stage, ?BGCouriers_Tracking $t = null): bool {
        if ($stage === '') { return false; }
        $times = $order->get_meta('_bgcouriers_track_times');
        $times = is_array($times) ? $times : [];
        if (isset($times[$stage]) && (int) $times[$stage] > 0) { return false; }

        $when = 0;
        $events = $t ? ($t->events ?? []) : [];
        if (is_array($events) && $events) {
            $when = BGCouriers_Tracking::event_time((array) end($events));
        }
        // A date in the future or before this plugin existed is a misread format.
        if ($when <= 0 || $when > time() + DAY_IN_SECONDS || $when < strtotime('2020-01-01')) { $when = time(); }

        $times[$stage] = $when;
        $order->update_meta_data('_bgcouriers_track_times', $times);
        return true;
    }

    /** Move the order to the shipped status once the courier has actually taken the parcel; true when changed. */
    public static function mark_shipped(\WC_Order $order, BGCouriers_Tracking $t): bool {
        $target = (string) get_option('bgcouriers_autostatus_on_shipped', '');
        $target = strpos($target, 'wc-') === 0 ? substr($target, 3) : $target;
        if ($target === '') { return false; }
        if ($order->get_meta('_bgcouriers_shipped_marked') === 'yes') { return false; }
        // Never drag an order backwards out of a state the merchant (or the delivered rule) already set.
        if (in_array($order->get_status(), [$target, 'completed', 'cancelled', 'refunded', 'failed'], true)) { return false; }

        // Believe the courier where it says outright; counting events would announce shipped while still on our desk.
        if ($t->handover !== null) {
            if ($t->handover === false) { return false; }
        } else {
            $first = (string) $order->get_meta('_bgcouriers_track_first');
            if ($first === '') {
                $order->update_meta_data('_bgcouriers_track_first', $t->status);
                if (count($t->events) <= 1) { return false; }
            } elseif ($t->status === $first && count($t->events) <= 1) {
                return false;
            }
        }

        $order->update_meta_data('_bgcouriers_shipped_marked', 'yes');
        $order->update_status($target, __('BG Couriers: the courier has picked up the shipment (auto status).', 'bg-couriers'));
        return true;
    }
}
