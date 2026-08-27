<?php
defined('ABSPATH') || exit;

class BGCouriers_Plugin {
    private static $instance;
    public static function instance(): self {
        return self::$instance ??= new self();
    }
    /**
     * v0.2.x: the per-courier "sandbox / test" checkbox became an inverted "Live mode" toggle. Carry any
     * existing setting over (once) so a courier that was left on test is not silently switched to the live
     * API. After the first save the _live option exists and this is a no-op.
     */
    /**
     * Keep an existing shop charging delivery the way it charges it today.
     *
     * "Delivery in the order total" used to default to ON, and now defaults to OFF - the shop that
     * asked for this one wants the customer to pay the courier at the door, so its books carry the
     * goods and not a delivery it collects and pays straight out again. That is the right default for
     * a new shop and a silent change of the money for an old one: a shop that never opened the setting
     * would stop charging delivery with the order the moment it updated, and would find out from its
     * customers.
     *
     * So the change applies to NEW installs only. An install that already exists - which is exactly an
     * install that has a bgcouriers_db_version recorded - gets its current answer written down as its
     * own choice, once, and goes on doing what it was doing. Nothing here overwrites a setting a
     * merchant has actually made: only an option that was never saved is filled in.
     */
    private static function pin_who_pays_delivery(): void {
        if (get_option('bgcouriers_who_pays_pinned', '') === 'yes') { return; }
        update_option('bgcouriers_who_pays_pinned', 'yes');
        // A site being set up right now should have the new default, so there is nothing to pin. The
        // flag is read rather than the db_version, because by the time this runs the version has just
        // been written and a fresh install is indistinguishable from an old one.
        if (defined('BGCOURIERS_NEW_INSTALL') && BGCOURIERS_NEW_INSTALL) { return; }
        foreach (array_keys(BGCouriers_Couriers::all()) as $id) {
            if (get_option('bgcouriers_' . $id . '_ship_in_total', '') !== '') { continue; }
            // What this shop is charging at this moment, worked out the old way: the pre-toggle
            // "who pays delivery" select when there is one, and otherwise the old default, ON.
            $was = get_option('bgcouriers_' . $id . '_service_payer', 'sender') !== 'recipient' ? 'yes' : 'no';
            update_option('bgcouriers_' . $id . '_ship_in_total', $was);
        }
    }

    /**
     * A courier that comes for the parcel as soon as a waybill exists now starts with automatic labels
     * OFF (see BGCouriers_Settings::autolabel_for). That is the right default for a shop being set up
     * today, and it must NOT quietly become the answer for one that is already running: a shop relying
     * on automatic labels would simply stop producing them, and find out when nothing shipped.
     *
     * So the answer such a shop has right now - the general setting - is written down for those
     * couriers, once, before the new default can ever be reached.
     */
    private static function pin_autolabel(): void {
        if (get_option('bgcouriers_autolabel_pinned', '') === 'yes') { return; }
        update_option('bgcouriers_autolabel_pinned', 'yes');
        if (defined('BGCOURIERS_NEW_INSTALL') && BGCOURIERS_NEW_INSTALL) { return; }
        $was = get_option('bgcouriers_autolabel_enabled', 'no') === 'yes' ? 'yes' : 'no';
        foreach (array_keys(BGCouriers_Couriers::all()) as $id) {
            $co = BGCouriers_Couriers::get($id);
            if (!$co || !method_exists($co, 'books_pickup_on_create') || !$co->books_pickup_on_create()) { continue; }
            if (get_option('bgcouriers_' . $id . '_autolabel', '') !== '') { continue; }
            update_option('bgcouriers_' . $id . '_autolabel', $was);
        }
    }

    private static function migrate_env_flags(): void {
        foreach (['pigeon', 'sameday', 'boxnow'] as $c) {
            if (get_option("bgcouriers_{$c}_live", null) === null && get_option("bgcouriers_{$c}_sandbox", null) !== null) {
                update_option("bgcouriers_{$c}_live", get_option("bgcouriers_{$c}_sandbox") === 'yes' ? 'no' : 'yes');
            }
        }
        // BOX NOW earlier stored the environment as a URL (bgcouriers_boxnow_api_url); carry that over as well.
        if (get_option('bgcouriers_boxnow_live', null) === null) {
            $url = get_option('bgcouriers_boxnow_api_url', null);
            if ($url !== null) {
                update_option('bgcouriers_boxnow_live', strpos((string) $url, 'stage') !== false ? 'no' : 'yes');
            }
        }
    }
    private function __construct() {
        self::migrate_env_flags();
        // Built from courier_credentials(), NOT courier_config(): an adapter has to know how to reach its
        // API whether or not the shop is currently offering that courier. Whether it is OFFERED is asked
        // separately, by the code that decides it (checkout, the sync loop) - see courier_config().
        // Built the other way, a disabled courier got an adapter with no credentials at all, so
        // validating them was impossible until it was enabled, and it could not be enabled until they
        // were validated.
        BGCouriers_Couriers::register('speedy', __('Speedy', 'bg-couriers'), static function () {
            return new BGCouriers_Speedy(BGCouriers_Settings::courier_credentials('speedy') ?: []);
        });
        BGCouriers_Couriers::register('econt', __('Econt', 'bg-couriers'), static function () {
            return new BGCouriers_Econt(BGCouriers_Settings::courier_credentials('econt') ?: []);
        });
        BGCouriers_Couriers::register('pigeon', __('Pigeon Express', 'bg-couriers'), static function () {
            // Pick the live vs sandbox host from the "Live mode" toggle (resolved here at runtime, not in the
            // constructor, so the constructor stays pure for unit tests). Default = live.
            $base = get_option('bgcouriers_pigeon_live', 'yes') === 'yes' ? BGCouriers_Pigeon::PROD : BGCouriers_Pigeon::DEMO;
            return new BGCouriers_Pigeon(array_merge(BGCouriers_Settings::courier_credentials('pigeon') ?: [], ['base' => $base]));
        });
        BGCouriers_Couriers::register('boxnow', __('BOX NOW', 'bg-couriers'), static function () {
            return new BGCouriers_Boxnow(array_merge(BGCouriers_Settings::courier_credentials('boxnow') ?: [], [
                'api_url'      => get_option('bgcouriers_boxnow_live', 'yes') === 'yes' ? BGCouriers_Boxnow::PROD : BGCouriers_Boxnow::STAGE,
                'partner_id'   => get_option('bgcouriers_boxnow_partner_id', ''),
                'warehouse_id' => get_option('bgcouriers_boxnow_warehouse_id', ''),
            ]));
        });
        BGCouriers_Couriers::register('sameday', __('Sameday', 'bg-couriers'), static function () {
            return new BGCouriers_Sameday(BGCouriers_Settings::courier_credentials('sameday') ?: []);
        });
        BGCouriers_Couriers::register('expressone', __('Express One', 'bg-couriers'), static function () {
            return new BGCouriers_Expressone(BGCouriers_Settings::courier_credentials('expressone') ?: []);
        });
        BGCouriers_Couriers::boot();
        // AFTER the registry is populated: this walks every courier, and called any earlier it walks an
        // empty list and writes down that it is done. Which is exactly what it did the first time.
        self::pin_who_pays_delivery();
        self::pin_autolabel();
        add_filter('cron_schedules', function ($s) {
            $s['weekly']    = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
            $s['bgcouriers_30min'] = ['interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Every 30 minutes'];
            $s['bgcouriers_6h']    = ['interval' => 6 * HOUR_IN_SECONDS, 'display' => '4 times a day'];
            return $s;
        });
        add_action('init', ['BGCouriers_Sync', 'schedule']);
        add_action(BGCouriers_Sync::HOOK, ['BGCouriers_Sync', 'cron']);
        add_action(BGCouriers_Sync::RATES_HOOK, ['BGCouriers_Sync', 'refresh_rates']); // daily reference-price refresh
        // Tracking auto-update: poll couriers without a webhook (Speedy/Econt/Pigeon/Sameday) on a schedule.
        add_action('init', ['BGCouriers_Tracking_Poller', 'schedule']);
        add_action(BGCouriers_Tracking_Poller::HOOK, ['BGCouriers_Tracking_Poller', 'run']);
        add_action('update_option_bgcouriers_tracking_poll', ['BGCouriers_Tracking_Poller', 'schedule']); // re-schedule on change
        // Hide our internal shipping-line meta from the admin order screen. The front end (emails, order
        // pages) hides underscore-prefixed keys on its own, but the admin order editor renders item meta via
        // get_all_formatted_meta_data('') - no prefix hiding - so every key must be listed here explicitly. Both
        // the current underscore-prefixed keys and the unprefixed ones legacy orders stored.
        add_filter('woocommerce_hidden_order_itemmeta', static function ($keys) {
            foreach (['bgcouriers_source', 'bgcouriers_method', 'bgcouriers_info_price'] as $k) {
                $keys[] = $k;
                $keys[] = '_' . $k;
            }
            return $keys;
        });
        add_filter('woocommerce_shipping_methods', function ($methods) {
            $methods['bgcouriers_speedy'] = 'BGCouriers_Method_Speedy';
            $methods['bgcouriers_econt'] = 'BGCouriers_Method_Econt';
            $methods['bgcouriers_pigeon'] = 'BGCouriers_Method_Pigeon';
            $methods['bgcouriers_boxnow'] = 'BGCouriers_Method_Boxnow';
            $methods['bgcouriers_sameday'] = 'BGCouriers_Method_Sameday';
            $methods['bgcouriers_expressone'] = 'BGCouriers_Method_Expressone';
            return $methods;
        });
        $checkout = new BGCouriers_Checkout();
        // The block checkout fires none of the hooks the classic one does, so the same rules are
        // re-attached to their Store API counterparts - reusing that same object, not a copy of it.
        new BGCouriers_Blocks($checkout);
        // The waybill and the track link, for the person actually waiting for the parcel - the plugin
        // had no customer-facing tracking at all, and not one WC_Email hook.
        new BGCouriers_Customer_Tracking();
        new BGCouriers_Thankyou(); // order summary on the thank-you step (native hook + shortcode)
        new BGCouriers_Ajax();
        new BGCouriers_Labels(); // status-change hook must fire on front-end order transitions too
        new BGCouriers_Boxnow_Webhook(); // REST receiver for BOX NOW parcel-event webhooks (front-end route)
        // NOT admin-only: WC_Abstract_Order::set_status() silently falls back to "pending" for a status
        // that is not in wc_get_order_statuses(), so the cron tracking poller (and the customer-facing
        // order pages) must see it registered too.
        new BGCouriers_Order_Status();
        if (is_admin()) {
            new BGCouriers_Settings();
            BGCouriers_Settings_Migrator::migrate();
            new BGCouriers_Order_Metabox();
            new BGCouriers_Order_Columns();
            new BGCouriers_Bulk_Labels();
            new BGCouriers_Pickup(); // the "come and collect these" step, from the orders list
            // No Print Invoices & Packing Lists integration lives here. A shop that prints its documents
            // with PIP - or with any of the other document plugins - decides for itself what goes on the
            // sheet and where; that code belongs to whatever prints it. The courier facts it needs are
            // public: BGCouriers_Labels::order_courier(), BGCouriers_Couriers::logo_url() and
            // BGCouriers_Icons::method_label(), plus the _bgcouriers_waybill / _bgcouriers_method meta.
        }
    }
}
