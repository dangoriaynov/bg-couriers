<?php
defined('ABSPATH') || exit;

/**
 * Periodic tracking-status poller for the couriers that don't push webhooks (Speedy / Econt / Pigeon /
 * Sameday). On a WP-Cron schedule it looks up a batch of active shipments, and when a shipment's status
 * CHANGES it records it and adds an order note - the same visibility BOX NOW's webhook gives, without the
 * merchant clicking Track. BOX NOW is skipped (it has its own webhook). Optionally advances the order status
 * once delivered.
 */
class BGCouriers_Tracking_Poller {
    const HOOK = 'bgcouriers_poll_tracking';

    /** Cron recurrence id from the setting, or 'off'. */
    private static function freq(): string {
        $f = (string) get_option('bgcouriers_tracking_poll', 'twicedaily');
        return in_array($f, ['off', 'bgcouriers_30min', 'hourly', 'bgcouriers_6h', 'twicedaily', 'daily'], true) ? $f : 'twicedaily';
    }

    /** Ensure the cron event matches the setting - runs on init and whenever the setting changes. */
    public static function schedule(): void {
        $freq = self::freq();
        $next = wp_next_scheduled(self::HOOK);
        if ($freq === 'off') { if ($next) { wp_unschedule_event($next, self::HOOK); } return; }
        // Reschedule if the recurrence changed.
        if ($next) {
            $ev = wp_get_scheduled_event(self::HOOK);
            if ($ev && ($ev->schedule ?? '') !== $freq) { wp_unschedule_event($next, self::HOOK); $next = false; }
        }
        if (!$next) { wp_schedule_event(time() + 300, $freq, self::HOOK); }
    }

    /** Shipments asked about per query. A page size, not a cap - see run(). */
    const BATCH = 40;
    /**
     * Seconds a run may spend asking couriers. It used to be one batch of BATCH, oldest first, and that
     * was the whole run: on a shop with more parcels out than that, the newer ones were never asked about
     * until the older ones finished, and sat frozen on whatever the courier had last said. The run goes
     * on in batches until nothing is left or this is spent; the budget is what keeps a cron run bounded.
     * Filterable through bgcouriers_poll_budget.
     */
    const BUDGET = 20;

    /** The cron callback: poll every active shipment, in batches, within the run's time budget. */
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
                self::poll_one($order, $advance);
                if (microtime(true) >= $deadline) { return; } // the rest waits for the next run
            }
        } while (count($orders) === self::BATCH);
    }

    /**
     * The next batch of shipments still in flight, oldest first, skipping the ones already asked this run.
     *
     * Two conditions on order meta - a waybill, and no "finished" mark - and the two order stores take
     * them differently. The orders table (HPOS) takes a meta_query; the classic posts store does not, and
     * WooCommerce 9.2+ says so with a doing_it_wrong on every run, while WP_Query underneath takes the
     * same meta_query through the store's own filter. Each store gets the form it supports.
     *
     * @param int[] $seen order ids already asked this run
     * @return \WC_Order[]
     */
    private static function in_flight(array $seen): array {
        $args = [
            'type'         => 'shop_order',
            // WITHOUT this the query falls back to WooCommerce's own status list, which does not include
            // the plugin's own shipped status - so the moment an order reached that status it stopped being
            // polled and froze on whatever the courier had last said. A parcel that was refused and sent
            // back sat in the admin as "on its way" for days because of it.
            'status'       => 'any',
            'limit'        => self::BATCH,
            'orderby'      => 'date',
            'order'        => 'ASC',
            // Already asked this run. A shipment the poll has just marked finished drops out of the
            // query on its own; one it has not is asked once, whatever page it would have been on.
            // (A NOT IN list, bounded by what one run can ask within its budget - a few hundred at most.)
            'exclude'      => $seen, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
            'date_created' => '>' . (time() - 45 * DAY_IN_SECONDS), // don't poll ancient orders forever
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

    /**
     * Re-read ONE order's tracking right now, outside the schedule.
     *
     * The poll runs a few times a day; when someone is looking at a single order and wants to know where
     * it is, waiting hours for the next run is not an answer. Same code path as the cron, so a manual
     * refresh cannot behave differently from an automatic one.
     *
     * @param int $order_id The order.
     * @return bool True when the order was polled (it has one of our waybills), false when there was
     *              nothing to ask about.
     */
    public static function refresh_one(int $order_id): bool {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) { return false; }
        if ((string) $order->get_meta('_bgcouriers_waybill') === '') { return false; }
        // A manual refresh also un-freezes a shipment that was written off as finished: the merchant is
        // asking precisely because they think the recorded state is wrong.
        $order->delete_meta_data('_bgcouriers_track_done');
        $order->save();
        self::poll_one($order, (string) get_option('bgcouriers_autostatus_on_delivered', ''));
        return true;
    }

    private static function poll_one(\WC_Order $order, string $advance): void {
        $cid = (string) $order->get_meta('_bgcouriers_courier');
        $wb  = (string) $order->get_meta('_bgcouriers_waybill');
        if ($cid === '' || $cid === 'boxnow' || $wb === '') { return; } // BoxNow updates via its webhook
        $courier = BGCouriers_Couriers::get($cid);
        if (!$courier) { return; }
        // \Throwable, not \Exception. An adapter that hits a TypeError on an answer it did not expect
        // throws an Error, and an Error used to escape this loop: every order behind it in the batch
        // went unpolled, every courier's, not only the broken one's - and since this order never gets
        // marked finished it sat at the front of the batch on every run. Tracking for the whole shop
        // stopped, silently, on one adapter's bad day. An API that refuses is retried next run as it
        // always was; a broken adapter is now logged and skipped the same way.
        try { $t = $courier->track($wb); }
        catch (\Throwable $e) {
            if (!($e instanceof \Exception)) {
                BGCouriers_Logger::debug('tracking: a broken adapter, skipped', ['courier' => $cid, 'err' => $e->getMessage()]);
            }
            return;
        }
        self::record($order, $t, $courier->label(), $advance);
    }

    /**
     * Record one answer about a shipment on its order - whoever brought the answer.
     *
     * Six couriers are asked; BOX NOW tells us, through its webhook. What is written is the same either
     * way, and it used not to be: the webhook wrote the parcel state to a key of its own that nothing
     * read, while every screen and every rule reads what THIS writes. Separated from the asking so the
     * webhook can bring its answer here.
     *
     * @param string $label   The courier's name, for the order notes.
     * @param string $advance The status a delivered order is moved to, '' for none (the merchant's setting).
     */
    public static function record(\WC_Order $order, BGCouriers_Tracking $t, string $label, string $advance): void {
        $wb = (string) $order->get_meta('_bgcouriers_waybill');

        // One answer from the courier is ONE write of the order. Each block below used to flush as it
        // went, so a single poll could save the same order five times - five database writes, five
        // woocommerce_update_order hooks for every other plugin on the shop to run, for one reading.
        // Nothing about WHAT is written changes; the flag just decides whether there is anything to
        // flush at the one point that flushes it.
        $dirty = false;

        // Recorded before the change check: once the courier holds the parcel it stays held, and the
        // waybill lock depends on knowing that even on a poll where nothing else moved.
        if ($t->handover === true && (string) $order->get_meta('_bgcouriers_handover') !== 'yes') {
            $order->update_meta_data('_bgcouriers_handover', 'yes');
            $dirty = true;
        }

        // Pigeon carries a return home under a BRAND NEW waybill and freezes the booked one on
        // "unclaimed" for good, so the number the shop must quote at the counter to get its goods back
        // is one it has never been told. The order's own waybill is deliberately left alone: the label,
        // the cancel call and the waybill lock are all keyed on it.
        if ($t->waybill !== '' && $t->waybill !== $wb
            && (string) $order->get_meta('_bgcouriers_return_waybill') !== $t->waybill) {
            $order->update_meta_data('_bgcouriers_return_waybill', $t->waybill);
            /* translators: 1: courier name, 2: the waybill number the return travels under */
            $order->add_order_note(sprintf(__('%1$s: the parcel is coming back under a new waybill - %2$s.', 'bg-couriers'),
                $label, $t->waybill));
            $dirty = true;
        }

        $stage = $t->stage();
        // Mark a finished shipment done BEFORE the change check. A parcel that is already delivered stops
        // changing, so a check that returns early on "no change" never got here - and the same handful of
        // long-finished orders were re-polled on every run, forever, crowding out the live ones.
        // 'returning' is deliberately NOT terminal: the parcel is still moving, just the other way, and
        // marking it done froze the order on "coming back" while it was already back on the counter.
        if (in_array($stage, ['delivered', 'cancelled', 'returned'], true)
            && (string) $order->get_meta('_bgcouriers_track_done') !== 'yes') {
            $order->update_meta_data('_bgcouriers_track_done', 'yes');
            $dirty = true;
        }

        // What the admin displays is refreshed on EVERY poll, even when the courier says the same thing
        // as last time. It is derived from our own rules, and those change - after a rule fix, orders
        // whose status happened not to move would otherwise keep showing the old verdict for good.
        $human = $t->human();
        $stored_stage = (string) $order->get_meta('_bgcouriers_track_stage');
        if ($human !== '' && ($stored_stage !== $stage || (string) $order->get_meta('_bgcouriers_track_text') !== $human)) {
            $order->update_meta_data('_bgcouriers_track_text', $human);
            $order->update_meta_data('_bgcouriers_track_stage', $stage);
            $order->update_meta_data('_bgcouriers_track_updated', time());
            $dirty = true;
        }

        $key = $t->status;
        // No change - but the STAGE is checked too, not just the courier's wording. The two do not always
        // move together: Pigeon words the end of a return exactly as it words the end of a delivery, so a
        // parcel that came back to the shop's own office can arrive carrying a status line the order has
        // already seen, and a text-only check would swallow the one poll where 'returned' fires - the
        // order marked finished and never moved.
        if (($key === '' || $key === (string) $order->get_meta('_bgcouriers_track_status'))
            && $stage === $stored_stage) {
            // Whatever the blocks above decided still has to reach the database - and on the commonest
            // poll of all, where the courier says exactly what it said last time, there is nothing to
            // write and the order is not touched.
            if ($dirty) { $order->save(); }
            return;
        }
        // _track_status is the courier's own key (Speedy's is an operation code like "-14") and exists to
        // detect change - it is never what we show.
        $order->update_meta_data('_bgcouriers_track_status', $key);
        $order->update_meta_data('_bgcouriers_track_updated', time());
        // A courier that answered without a usable status ("UNKNOWN" is what our parsers fall back to)
        // has told us nothing worth writing on the order - the note only ever confused whoever read it.
        if ($human !== '' && strcasecmp($human, 'UNKNOWN') !== 0) {
            /* translators: 1: courier name, 2: what stage the shipment is at, 3: the courier's own wording */
            $order->add_order_note(sprintf(__('%1$s - %2$s: "%3$s"', 'bg-couriers'),
                $label, BGCouriers_Tracking::stage_label($t->stage()), $human));
        }

        // A refused parcel that has come all the way BACK: the goods are on the shelf again, so the order
        // is over. Only on 'returned' - while it is still travelling back nothing has been recovered yet.
        // Cancelling is what a merchant picks here, and WooCommerce puts the stock back with it.
        $back = (string) get_option('bgcouriers_autostatus_on_returned', '');
        $back = strpos($back, 'wc-') === 0 ? substr($back, 3) : $back;
        if ($stage === 'returned' && $back !== '' && $order->get_status() !== $back) {
            $order->update_status($back, __('BG Couriers: the parcel came back to you (auto status).', 'bg-couriers'));
            return; // update_status() saved it
        }

        $target = strpos($advance, 'wc-') === 0 ? substr($advance, 3) : $advance;
        if ($stage === 'delivered' && $target !== '' && $order->get_status() !== $target) {
            $order->update_status($target, __('BG Couriers: shipment delivered (auto status).', 'bg-couriers')); // saves the order
            return;
        }
        if (in_array($stage, ['transit', 'ready', 'returning'], true) && self::mark_shipped($order, $t)) { return; } // update_status() saved it
        $order->save();
    }

    /**
     * Move the order to the configured "shipped" status the first time the courier has actually taken the
     * parcel. Returns true when the status was changed (which saves the order). Public because this single
     * decision is the whole feature and is worth testing directly.
     *
     * "Taken" means the shipment moved BEYOND being registered: creating a waybill only hands the courier
     * the data, and every courier reports that as its own first tracking event (Speedy's 148, "shipment
     * information received", is exactly this). So a second event - or a status different from the very
     * first one we recorded, for couriers with a thin event list - is the signal that it is on its way.
     */
    public static function mark_shipped(\WC_Order $order, BGCouriers_Tracking $t): bool {
        $target = (string) get_option('bgcouriers_autostatus_on_shipped', '');
        $target = strpos($target, 'wc-') === 0 ? substr($target, 3) : $target;
        if ($target === '') { return false; }                                  // feature is off
        if ($order->get_meta('_bgcouriers_shipped_marked') === 'yes') { return false; } // only ever once
        // Never drag an order backwards out of a state the merchant (or the delivered rule) already set.
        if (in_array($order->get_status(), [$target, 'completed', 'cancelled', 'refunded', 'failed'], true)) { return false; }

        // Where the courier says outright whether it holds the parcel, believe it - Econt stamps sendTime
        // on handover, Speedy logs "collected from the sender"/"accepted by the courier". Both APIs also emit events
        // BEFORE anything is collected ("Awaiting delivery to Econt", Speedy's 148 "shipment information
        // received" = the label was registered), so counting events would announce the parcel as shipped
        // while it is still on our own desk.
        if ($t->handover !== null) {
            if ($t->handover === false) { return false; }
        } else {
            // Couriers that do not say (Pigeon, Sameday): wait for the history to grow past the single
            // registration event before assuming the parcel moved.
            $first = (string) $order->get_meta('_bgcouriers_track_first');
            if ($first === '') {
                $order->update_meta_data('_bgcouriers_track_first', $t->status);
                if (count($t->events) <= 1) { return false; }
            } elseif ($t->status === $first && count($t->events) <= 1) {
                return false; // still only registered
            }
        }

        $order->update_meta_data('_bgcouriers_shipped_marked', 'yes');
        $order->update_status($target, __('BG Couriers: the courier has picked up the shipment (auto status).', 'bg-couriers'));
        return true;
    }
}
