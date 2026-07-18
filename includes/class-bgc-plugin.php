<?php
defined('ABSPATH') || exit;

class BGC_Plugin {
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
            if (get_option("bgc_{$c}_live", null) === null && get_option("bgc_{$c}_sandbox", null) !== null) {
                update_option("bgc_{$c}_live", get_option("bgc_{$c}_sandbox") === 'yes' ? 'no' : 'yes');
            }
        }
        // BOX NOW earlier stored the environment as a URL (bgc_boxnow_api_url); carry that over as well.
        if (get_option('bgc_boxnow_live', null) === null) {
            $url = get_option('bgc_boxnow_api_url', null);
            if ($url !== null) {
                update_option('bgc_boxnow_live', strpos((string) $url, 'stage') !== false ? 'no' : 'yes');
            }
        }
    }
    private function __construct() {
        self::migrate_env_flags();
        BGC_Couriers::register('speedy', __('Speedy', 'bg-couriers'), static function () {
            return new BGC_Speedy(BGC_Settings::courier_config('speedy') ?: []);
        });
        BGC_Couriers::register('econt', __('Econt', 'bg-couriers'), static function () {
            return new BGC_Econt(BGC_Settings::courier_config('econt') ?: []);
        });
        BGC_Couriers::register('pigeon', __('Pigeon Express', 'bg-couriers'), static function () {
            // Pick the live vs sandbox host from the "Live mode" toggle (resolved here at runtime, not in the
            // constructor, so the constructor stays pure for unit tests). Default = live.
            $base = get_option('bgc_pigeon_live', 'yes') === 'yes' ? BGC_Pigeon::PROD : BGC_Pigeon::DEMO;
            return new BGC_Pigeon(array_merge(BGC_Settings::courier_config('pigeon') ?: [], ['base' => $base]));
        });
        BGC_Couriers::register('boxnow', __('BOX NOW', 'bg-couriers'), static function () {
            return new BGC_Boxnow(array_merge(BGC_Settings::courier_config('boxnow') ?: [], [
                'api_url'      => get_option('bgc_boxnow_live', 'yes') === 'yes' ? BGC_Boxnow::PROD : BGC_Boxnow::STAGE,
                'partner_id'   => get_option('bgc_boxnow_partner_id', ''),
                'warehouse_id' => get_option('bgc_boxnow_warehouse_id', ''),
            ]));
        });
        BGC_Couriers::register('sameday', __('Sameday', 'bg-couriers'), static function () {
            return new BGC_Sameday(BGC_Settings::courier_config('sameday') ?: []);
        });
        BGC_Couriers::boot();
        add_filter('cron_schedules', function ($s) {
            $s['weekly']    = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
            $s['bgc_30min'] = ['interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Every 30 minutes'];
            return $s;
        });
        add_action('init', ['BGC_Sync', 'schedule']);
        add_action(BGC_Sync::HOOK, ['BGC_Sync', 'cron']);
        add_action(BGC_Sync::RATES_HOOK, ['BGC_Sync', 'refresh_rates']); // daily reference-price refresh
        // Tracking auto-update: poll couriers without a webhook (Speedy/Econt/Pigeon/Sameday) on a schedule.
        add_action('init', ['BGC_Tracking_Poller', 'schedule']);
        add_action(BGC_Tracking_Poller::HOOK, ['BGC_Tracking_Poller', 'run']);
        add_action('update_option_bgc_tracking_poll', ['BGC_Tracking_Poller', 'schedule']); // re-schedule on change
        // Hide our internal shipping-line meta from the admin order screen. New orders store it under the
        // underscore-prefixed keys WC hides automatically (admin + customer emails/pages); this covers legacy
        // orders that stored the unprefixed keys.
        add_filter('woocommerce_hidden_order_itemmeta', static function ($keys) {
            $keys[] = 'bgc_source';
            $keys[] = 'bgc_method';
            return $keys;
        });
        add_filter('woocommerce_shipping_methods', function ($methods) {
            $methods['bgc_speedy'] = 'BGC_Method_Speedy';
            $methods['bgc_econt'] = 'BGC_Method_Econt';
            $methods['bgc_pigeon'] = 'BGC_Method_Pigeon';
            $methods['bgc_boxnow'] = 'BGC_Method_Boxnow';
            $methods['bgc_sameday'] = 'BGC_Method_Sameday';
            return $methods;
        });
        new BGC_Checkout();
        new BGC_Ajax();
        new BGC_Labels(); // status-change hook must fire on front-end order transitions too
        new BGC_Boxnow_Webhook(); // REST receiver for BOX NOW parcel-event webhooks (front-end route)
        if (is_admin()) {
            new BGC_Settings();
            BGC_Settings_Migrator::migrate();
            new BGC_Order_Metabox();
            new BGC_Order_Columns();
            new BGC_Bulk_Labels();
        }
    }
}
