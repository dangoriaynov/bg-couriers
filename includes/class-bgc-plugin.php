<?php
defined('ABSPATH') || exit;

class BGC_Plugin {
    private static $instance;
    public static function instance(): self {
        return self::$instance ??= new self();
    }
    private function __construct() {
        add_filter('cron_schedules', function ($s) {
            $s['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
            return $s;
        });
        add_action('init', ['BGC_Sync', 'schedule']);
        add_action(BGC_Sync::HOOK, ['BGC_Sync', 'cron']);
        add_filter('woocommerce_shipping_methods', function ($methods) {
            $methods['bgc_speedy'] = 'BGC_Method_Speedy';
            return $methods;
        });
        new BGC_Checkout();
        new BGC_Ajax();
    }
}
