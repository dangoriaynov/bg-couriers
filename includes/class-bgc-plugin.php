<?php
defined('ABSPATH') || exit;

class BGC_Plugin {
    private static $instance;
    public static function instance(): self {
        return self::$instance ??= new self();
    }
    private function __construct() {
        BGC_Couriers::register('speedy', __('Speedy', 'bg-couriers'), static function () {
            return new BGC_Speedy(BGC_Settings::courier_config('speedy') ?: []);
        });
        BGC_Couriers::register('econt', __('Econt', 'bg-couriers'), static function () {
            return new BGC_Econt(BGC_Settings::courier_config('econt') ?: []);
        });
        BGC_Couriers::register('pigeon', __('Pigeon Express', 'bg-couriers'), static function () {
            return new BGC_Pigeon(array_merge(BGC_Settings::courier_config('pigeon') ?: [], ['base' => get_option('bgc_pigeon_base_url', '')]));
        });
        BGC_Couriers::register('sameday', __('Sameday', 'bg-couriers'), static function () {
            return new BGC_Sameday(BGC_Settings::courier_config('sameday') ?: []);
        });
        BGC_Couriers::boot();
        add_filter('cron_schedules', function ($s) {
            $s['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
            return $s;
        });
        add_action('init', ['BGC_Sync', 'schedule']);
        add_action(BGC_Sync::HOOK, ['BGC_Sync', 'cron']);
        add_action(BGC_Sync::RATES_HOOK, ['BGC_Sync', 'refresh_rates']); // daily reference-price refresh
        add_filter('woocommerce_shipping_methods', function ($methods) {
            $methods['bgc_speedy'] = 'BGC_Method_Speedy';
            $methods['bgc_econt'] = 'BGC_Method_Econt';
            $methods['bgc_pigeon'] = 'BGC_Method_Pigeon';
            return $methods;
        });
        new BGC_Checkout();
        new BGC_Ajax();
        new BGC_Labels(); // status-change hook must fire on front-end order transitions too
        if (is_admin()) {
            new BGC_Settings();
            BGC_Settings_Migrator::migrate();
            new BGC_Order_Metabox();
            new BGC_Order_Columns();
            new BGC_Bulk_Labels();
        }
    }
}
