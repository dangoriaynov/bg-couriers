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
        return in_array($f, ['off', 'bgcouriers_30min', 'hourly', 'twicedaily', 'daily'], true) ? $f : 'twicedaily';
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

    /** The cron callback: poll a batch of active shipments and record any status changes. */
    public static function run(): void {
        if (self::freq() === 'off' || !function_exists('wc_get_orders')) { return; }
        $orders = wc_get_orders([
            'type'         => 'shop_order',
            // WITHOUT this the query falls back to WooCommerce's own status list, which does not include
            // the plugin's "Изпратена" - so the moment an order reached that status it stopped being
            // polled and froze on whatever the courier had last said. A parcel that was refused and sent
            // back sat in the admin as "on its way" for days because of it.
            'status'       => 'any',
            'limit'        => 40,
            'orderby'      => 'date',
            'order'        => 'ASC',
            'date_created' => '>' . (time() - 45 * DAY_IN_SECONDS), // don't poll ancient orders forever
            'meta_query'   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cron, batched + age-bounded
                'relation' => 'AND',
                ['key' => '_bgcouriers_waybill', 'value' => '', 'compare' => '!='],
                ['key' => '_bgcouriers_track_done', 'compare' => 'NOT EXISTS'],
            ],
        ]);
        $advance = (string) get_option('bgcouriers_autostatus_on_delivered', '');
        foreach ($orders as $order) {
            if ($order instanceof \WC_Order) { self::poll_one($order, $advance); }
        }
    }

    private static function poll_one(\WC_Order $order, string $advance): void {
        $cid = (string) $order->get_meta('_bgcouriers_courier');
        $wb  = (string) $order->get_meta('_bgcouriers_waybill');
        if ($cid === '' || $cid === 'boxnow' || $wb === '') { return; } // BoxNow updates via its webhook
        $courier = BGCouriers_Couriers::get($cid);
        if (!$courier) { return; }
        try { $t = $courier->track($wb); } catch (\Exception $e) { return; } // transient error - retry next run

        // Recorded before the change check: once the courier holds the parcel it stays held, and the
        // waybill lock depends on knowing that even on a poll where nothing else moved.
        if ($t->handover === true && (string) $order->get_meta('_bgcouriers_handover') !== 'yes') {
            $order->update_meta_data('_bgcouriers_handover', 'yes');
            $order->save();
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
            $order->save();
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
            $order->save();
        }

        $key = $t->status;
        if ($key === '' || $key === (string) $order->get_meta('_bgcouriers_track_status')) { return; } // no change
        // _track_status is the courier's own key (Speedy's is an operation code like "-14") and exists to
        // detect change - it is never what we show.
        $order->update_meta_data('_bgcouriers_track_status', $key);
        $order->update_meta_data('_bgcouriers_track_updated', time());
        // A courier that answered without a usable status ("UNKNOWN" is what our parsers fall back to)
        // has told us nothing worth writing on the order - the note only ever confused whoever read it.
        if ($human !== '' && strcasecmp($human, 'UNKNOWN') !== 0) {
            /* translators: 1: courier name, 2: what stage the shipment is at, 3: the courier's own wording */
            $order->add_order_note(sprintf(__('%1$s - %2$s: "%3$s"', 'bg-couriers'),
                $courier->label(), BGCouriers_Tracking::stage_label($t->stage()), $human));
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
     * the data, and every courier reports that as its own first tracking event (Speedy's 148 "Получена
     * информация за пратка" is exactly this). So a second event - or a status different from the very
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
        // on handover, Speedy logs "Приемане от подател"/"Приемане от куриер". Both APIs also emit events
        // BEFORE anything is collected ("Awaiting delivery to Econt", Speedy's 148 "Получена информация за
        // пратка" = the label was registered), so counting events would announce the parcel as shipped
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
