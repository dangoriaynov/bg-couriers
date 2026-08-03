<?php
require dirname(__DIR__) . '/vendor/autoload.php';

$bgcouriers_is_integration = getenv('BGCouriers_SUITE') === 'integration'
    || (in_array('--testsuite', $_SERVER['argv'] ?? [], true) && in_array('integration', $_SERVER['argv'], true));

// Unit suite: source files guard on ABSPATH (`defined('ABSPATH') || exit;`), so define it here to let the
// files be required standalone under Brain Monkey. The integration suite boots real WordPress, which
// defines ABSPATH itself, so only set it for units.
if (!$bgcouriers_is_integration && !defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Unit suite: a do-nothing $wpdb, so code that reaches the database layer answers "nothing found"
// instead of fataling on a null global. Several call sites are guarded with
// class_exists('BGCouriers_Nomenclature'), which means whether they run at all depends on which
// classes some OTHER test file happened to require - a test that loads one more class should not be
// able to break an unrelated one. Any test needing real query behaviour overrides $GLOBALS['wpdb'].
if (!$bgcouriers_is_integration && !isset($GLOBALS['wpdb'])) {
    class BGCouriers_Null_Wpdb {
        public $prefix = 'wp_';
        public function prepare($sql, ...$args) { return $sql; }
        public function query($sql) { return 0; }
        public function get_results($sql, $mode = null) { return []; }
        public function get_row($sql, $mode = null) { return null; }
        public function get_col($sql) { return []; }
        public function get_var($sql) { return null; }
        public function esc_like($s) { return $s; }
    }
    $GLOBALS['wpdb'] = new BGCouriers_Null_Wpdb();
    // wpdb's result-format constants, which those same call sites pass through.
    foreach (['OBJECT' => 'OBJECT', 'ARRAY_A' => 'ARRAY_A', 'ARRAY_N' => 'ARRAY_N'] as $k => $v) {
        if (!defined($k)) { define($k, $v); }
    }
}

// Integration suite only: boot the WordPress test framework.
if ($bgcouriers_is_integration) {
    $wp_tests = getenv('WP_PHPUNIT__DIR') ?: (getenv('WP_TESTS_DIR') ?: '/wordpress-phpunit');
    // Point the WP test bootstrap at the real wp-tests-config.php that has DB + constant definitions.
    if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
        define('WP_TESTS_CONFIG_FILE_PATH', '/wordpress-phpunit/wp-tests-config.php');
    }
    require $wp_tests . '/includes/functions.php';
    tests_add_filter('muplugins_loaded', function () {
        // Load WooCommerce before our plugin so WC_Shipping_Method is available.
        $wc_plugin = WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
        if (file_exists($wc_plugin)) {
            require_once $wc_plugin;
        }
        require dirname(__DIR__) . '/bg-couriers.php';
    });
    require $wp_tests . '/includes/bootstrap.php';
}
