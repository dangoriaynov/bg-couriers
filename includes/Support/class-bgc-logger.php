<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Logger {
    public static function debug(string $msg, array $ctx = []): void {
        if (!(function_exists('get_option') && get_option('bgc_debug') === 'yes')) { return; }
        // Never log credential keys.
        unset($ctx['userName'], $ctx['password'], $ctx['api_key'], $ctx['api_secret']);
        error_log('[bg-couriers] ' . $msg . ($ctx ? ' ' . wp_json_encode($ctx) : ''));
    }
}
