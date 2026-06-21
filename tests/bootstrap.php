<?php
require dirname(__DIR__) . '/vendor/autoload.php';

// Integration suite only: boot the WordPress test framework.
if (getenv('BGC_SUITE') === 'integration' || in_array('--testsuite', $_SERVER['argv'] ?? [], true) && in_array('integration', $_SERVER['argv'], true)) {
    $wp_tests = getenv('WP_PHPUNIT__DIR') ?: (getenv('WP_TESTS_DIR') ?: '/wordpress-phpunit');
    // Point the WP test bootstrap at the real wp-tests-config.php that has DB + constant definitions.
    if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
        define('WP_TESTS_CONFIG_FILE_PATH', '/wordpress-phpunit/wp-tests-config.php');
    }
    require $wp_tests . '/includes/functions.php';
    tests_add_filter('muplugins_loaded', function () {
        require dirname(__DIR__) . '/bg-couriers.php';
    });
    require $wp_tests . '/includes/bootstrap.php';
}
