<?php
defined('ABSPATH') || exit;

/**
 * Periodic tracking-status poller for the couriers that don't push webhooks (Speedy / Econt / Pigeon /
 * Sameday). On a WP-Cron schedule it looks up a batch of active shipments, and when a shipment's status
 * CHANGES it records it and adds an order note - the same visibility BOX NOW's webhook gives, without the
 * merchant clicking Track. BOX NOW is skipped (it has its own webhook). Optionally advances the order status
 * once delivered.
 */
class BGC_Tracking_Poller {
    const HOOK = 'bgc_poll_tracking';

    /** Cron recurrence id from the setting, or 'off'. */
    private static function freq(): string {
        $f = (string) get_option('bgc_tracking_poll', 'twicedaily');
        return in_array($f, ['off', 'bgc_30min', 'hourly', 'twicedaily', 'daily'], true) ? $f : 'twicedaily';
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
            'limit'        => 40,
            'orderby'      => 'date',
            'order'        => 'ASC',
            'date_created' => '>' . (time() - 45 * DAY_IN_SECONDS), // don't poll ancient orders forever
            'meta_query'   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- cron, batched + age-bounded
                'relation' => 'AND',
                ['key' => '_bgc_waybill', 'value' => '', 'compare' => '!='],
                ['key' => '_bgc_track_done', 'compare' => 'NOT EXISTS'],
            ],
        ]);
        $advance = (string) get_option('bgc_autostatus_on_delivered', '');
        foreach ($orders as $order) {
            if ($order instanceof \WC_Order) { self::poll_one($order, $advance); }
        }
    }

    private static function poll_one(\WC_Order $order, string $advance): void {
        $cid = (string) $order->get_meta('_bgc_courier');
        $wb  = (string) $order->get_meta('_bgc_waybill');
        if ($cid === '' || $cid === 'boxnow' || $wb === '') { return; } // BoxNow updates via its webhook
        $courier = BGC_Couriers::get($cid);
        if (!$courier) { return; }
        try { $t = $courier->track($wb); } catch (\Exception $e) { return; } // transient error - retry next run

        $key = $t->status;
        if ($key === '' || $key === (string) $order->get_meta('_bgc_track_status')) { return; } // no change

        $human = $t->human();
        $order->update_meta_data('_bgc_track_status', $key);
        $order->update_meta_data('_bgc_track_updated', time());
        /* translators: 1: courier name, 2: status */
        $order->add_order_note(sprintf(__('%1$s tracking update: %2$s', 'bg-couriers'), $courier->label(), $human));

        $stage = $t->stage();
        if (in_array($stage, ['delivered', 'cancelled', 'returned'], true)) {
            $order->update_meta_data('_bgc_track_done', 'yes'); // terminal - stop polling this waybill
        }
        $target = strpos($advance, 'wc-') === 0 ? substr($advance, 3) : $advance;
        if ($stage === 'delivered' && $target !== '' && $order->get_status() !== $target) {
            $order->update_status($target, __('BG Couriers: shipment delivered (auto status).', 'bg-couriers')); // saves the order
            return;
        }
        $order->save();
    }
}
