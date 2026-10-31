<?php
defined('ABSPATH') || exit;

class BGCouriers_Plugin {
    /** Every WP-Cron hook this plugin schedules; read by deactivation and uninstall.php. */
    const CRON_HOOKS = ['bgcouriers_poll_tracking', 'bgcouriers_weekly_sync', 'bgcouriers_daily_rates', 'bgcouriers_retry_autolabel'];

    /** wp_unschedule_hook(), not wp_clear_scheduled_hook(): events with arguments must go too. */
    public static function clear_cron(): void {
        foreach (self::CRON_HOOKS as $hook) { wp_unschedule_hook($hook); }
    }

    private static $instance;
    public static function instance(): self {
        return self::$instance ??= new self();
    }
    /** Existing installs keep the old "ship in total" default; only never-saved options are filled. */
    private static function pin_who_pays_delivery(): void {
        if (get_option('bgcouriers_who_pays_pinned', '') === 'yes') { return; }
        update_option('bgcouriers_who_pays_pinned', 'yes');
        if (defined('BGCOURIERS_NEW_INSTALL') && BGCOURIERS_NEW_INSTALL) { return; }
        foreach (array_keys(BGCouriers_Couriers::all()) as $id) {
            if (get_option('bgcouriers_' . $id . '_ship_in_total', '') !== '') { continue; }
            $was = get_option('bgcouriers_' . $id . '_service_payer', 'sender') !== 'recipient' ? 'yes' : 'no';
            update_option('bgcouriers_' . $id . '_ship_in_total', $was);
        }
    }

    /** Existing installs keep their current autolabel answer for pickup-on-create couriers. */
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

    /** Existing installs keep issuing the automatic waybill at once. */
    private static function pin_autolabel_wait(?bool $new_install = null): void {
        if (get_option('bgcouriers_autolabel_wait_pinned', '') === 'yes') { return; }
        update_option('bgcouriers_autolabel_wait_pinned', 'yes');
        if ($new_install ?? (defined('BGCOURIERS_NEW_INSTALL') && BGCOURIERS_NEW_INSTALL)) { return; }
        if (get_option('bgcouriers_autolabel_wait', '') === '') { update_option('bgcouriers_autolabel_wait', 'no'); }
    }

    private static function migrate_env_flags(): void {
        foreach (['pigeon', 'sameday', 'boxnow'] as $c) {
            if (get_option("bgcouriers_{$c}_live", null) === null && get_option("bgcouriers_{$c}_sandbox", null) !== null) {
                update_option("bgcouriers_{$c}_live", get_option("bgcouriers_{$c}_sandbox") === 'yes' ? 'no' : 'yes');
            }
        }
        if (get_option('bgcouriers_boxnow_live', null) === null) {
            $url = get_option('bgcouriers_boxnow_api_url', null);
            if ($url !== null) {
                update_option('bgcouriers_boxnow_live', strpos((string) $url, 'stage') !== false ? 'no' : 'yes');
            }
        }
    }
    private function __construct() {
        self::migrate_env_flags();
        // courier_credentials(), not courier_config(): a disabled courier still needs a working adapter.
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () {
            return new BGCouriers_Speedy(BGCouriers_Settings::courier_credentials('speedy') ?: []);
        });
        BGCouriers_Couriers::register('econt', 'Econt', static function () {
            return new BGCouriers_Econt(BGCouriers_Settings::courier_credentials('econt') ?: []);
        });
        BGCouriers_Couriers::register('pigeon', 'Pigeon Express', static function () {
            $base = get_option('bgcouriers_pigeon_live', 'yes') === 'yes' ? BGCouriers_Pigeon::PROD : BGCouriers_Pigeon::DEMO;
            return new BGCouriers_Pigeon(array_merge(BGCouriers_Settings::courier_credentials('pigeon') ?: [], ['base' => $base]));
        });
        BGCouriers_Couriers::register('boxnow', 'BOX NOW', static function () {
            return new BGCouriers_Boxnow(array_merge(BGCouriers_Settings::courier_credentials('boxnow') ?: [], [
                'api_url'      => get_option('bgcouriers_boxnow_live', 'yes') === 'yes' ? BGCouriers_Boxnow::PROD : BGCouriers_Boxnow::STAGE,
                'partner_id'   => get_option('bgcouriers_boxnow_partner_id', ''),
                'warehouse_id' => get_option('bgcouriers_boxnow_warehouse_id', ''),
            ]));
        });
        BGCouriers_Couriers::register('sameday', 'Sameday', static function () {
            return new BGCouriers_Sameday(BGCouriers_Settings::courier_credentials('sameday') ?: []);
        });
        BGCouriers_Couriers::register('expressone', 'Express One', static function () {
            return new BGCouriers_Expressone(BGCouriers_Settings::courier_credentials('expressone') ?: []);
        });
        BGCouriers_Couriers::register('evropat', 'Европът', static function () {
            return new BGCouriers_Evropat(BGCouriers_Settings::courier_credentials('evropat') ?: []);
        });
        BGCouriers_Couriers::boot();
        // After the registry is populated: these walk every courier and record that they ran.
        self::pin_who_pays_delivery();
        self::pin_autolabel();
        self::pin_autolabel_wait();
        add_filter('cron_schedules', function ($s) {
            $s['weekly']    = ['interval' => WEEK_IN_SECONDS, 'display' => __('Once weekly', 'bg-couriers')];
            $s['bgcouriers_30min'] = ['interval' => 30 * MINUTE_IN_SECONDS, 'display' => __('Every 30 minutes', 'bg-couriers')];
            $s['bgcouriers_6h']    = ['interval' => 6 * HOUR_IN_SECONDS, 'display' => __('Four times a day', 'bg-couriers')];
            return $s;
        });
        add_action('init', ['BGCouriers_Sync', 'schedule']);
        add_action(BGCouriers_Sync::HOOK, ['BGCouriers_Sync', 'cron']);
        add_action(BGCouriers_Sync::RATES_HOOK, ['BGCouriers_Sync', 'refresh_rates']);
        foreach (array_keys(BGCouriers_Couriers::all()) as $cid) {
            foreach (['update_option', 'add_option'] as $when) {
                add_action("{$when}_bgcouriers_{$cid}_enabled", ['BGCouriers_Sync', 'on_courier_enabled'], 10, 2);
            }
        }
        add_action('init', ['BGCouriers_Tracking_Poller', 'schedule']);
        add_action(BGCouriers_Tracking_Poller::HOOK, ['BGCouriers_Tracking_Poller', 'run']);
        add_action('update_option_bgcouriers_tracking_poll', ['BGCouriers_Tracking_Poller', 'schedule']);
        // add_option_ as well: the first save of a never-existing option fires that, not update_.
        foreach (['speedy', 'econt'] as $cid) {
            foreach (['update_option', 'add_option'] as $when) {
                add_action("{$when}_bgcouriers_{$cid}_dropoff_office", static function () use ($cid) {
                    $c = BGCouriers_Couriers::get($cid);
                    if ($c) { BGCouriers_Sync::seed_rates($c); }
                });
            }
        }
        // The admin order editor does not hide underscore-prefixed item meta on its own.
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
            $methods['bgcouriers_evropat'] = 'BGCouriers_Method_Evropat';
            return $methods;
        });
        $checkout = new BGCouriers_Checkout();
        new BGCouriers_Blocks($checkout);
        new BGCouriers_Customer_Tracking();
        new BGCouriers_Thankyou();
        new BGCouriers_Ajax();
        new BGCouriers_Labels();
        new BGCouriers_Boxnow_Webhook();
        // Not admin-only: set_status() falls back to "pending" for a status that is not registered.
        new BGCouriers_Order_Status();
        if (is_admin()) {
            new BGCouriers_Settings_Admin();
            BGCouriers_Settings_Migrator::migrate();
            new BGCouriers_Order_Metabox();
            new BGCouriers_Order_Columns();
            new BGCouriers_Bulk_Labels();
            new BGCouriers_Pickup();
        }
    }
}
