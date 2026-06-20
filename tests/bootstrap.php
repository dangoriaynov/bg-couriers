<?php
require dirname(__DIR__) . '/vendor/autoload.php';

// Integration suite only: boot the WordPress test framework.
if (getenv('BGC_SUITE') === 'integration' || in_array('--testsuite', $_SERVER['argv'] ?? [], true) && in_array('integration', $_SERVER['argv'], true)) {
    $wp_tests = getenv('WP_PHPUNIT__DIR') ?: '/wordpress-phpunit';
    require $wp_tests . '/includes/functions.php';
    tests_add_filter('muplugins_loaded', function () {
        require dirname(__DIR__) . '/bg-couriers.php';
    });
    require $wp_tests . '/includes/bootstrap.php';
}
