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
        BGCouriers_Couriers::register('speedy', __('Speedy', 'bg-couriers'), static function () {
            return new BGCouriers_Speedy(BGCouriers_Settings::courier_config('speedy') ?: []);
        });
        BGCouriers_Couriers::register('econt', __('Econt', 'bg-couriers'), static function () {
            return new BGCouriers_Econt(BGCouriers_Settings::courier_config('econt') ?: []);
        });
        BGCouriers_Couriers::register('pigeon', __('Pigeon Express', 'bg-couriers'), static function () {
            // Pick the live vs sandbox host from the "Live mode" toggle (resolved here at runtime, not in the
            // constructor, so the constructor stays pure for unit tests). Default = live.
            $base = get_option('bgcouriers_pigeon_live', 'yes') === 'yes' ? BGCouriers_Pigeon::PROD : BGCouriers_Pigeon::DEMO;
            return new BGCouriers_Pigeon(array_merge(BGCouriers_Settings::courier_config('pigeon') ?: [], ['base' => $base]));
        });
        BGCouriers_Couriers::register('boxnow', __('BOX NOW', 'bg-couriers'), static function () {
            return new BGCouriers_Boxnow(array_merge(BGCouriers_Settings::courier_config('boxnow') ?: [], [
                'api_url'      => get_option('bgcouriers_boxnow_live', 'yes') === 'yes' ? BGCouriers_Boxnow::PROD : BGCouriers_Boxnow::STAGE,
                'partner_id'   => get_option('bgcouriers_boxnow_partner_id', ''),
                'warehouse_id' => get_option('bgcouriers_boxnow_warehouse_id', ''),
            ]));
        });
        BGCouriers_Couriers::register('sameday', __('Sameday', 'bg-couriers'), static function () {
            return new BGCouriers_Sameday(BGCouriers_Settings::courier_config('sameday') ?: []);
        });
        BGCouriers_Couriers::boot();
        add_filter('cron_schedules', function ($s) {
            $s['weekly']    = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
            $s['bgcouriers_30min'] = ['interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Every 30 minutes'];
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
            return $methods;
        });
        new BGCouriers_Checkout();
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
        }
    }
}
