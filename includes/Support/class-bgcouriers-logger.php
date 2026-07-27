<?php
defined('ABSPATH') || exit;

class BGCouriers_Logger {
    public static function debug(string $msg, array $ctx = []): void {
        if (!(function_exists('get_option') && get_option('bgcouriers_debug') === 'yes')) { return; }
        // Never log credential keys.
        unset($ctx['userName'], $ctx['password'], $ctx['api_key'], $ctx['api_secret']);
        error_log('[bg-couriers] ' . $msg . ($ctx ? ' ' . wp_json_encode($ctx) : '')); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated logger, debug only
    }
}
