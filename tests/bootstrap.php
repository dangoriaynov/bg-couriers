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
// class_exists('BGCouriers_Nomenclature'); with the plugin's autoloader registered below that guard
// answers yes in every test file alike (the class is found and loaded), so such code always runs -
// against this $wpdb. Any test needing real query behaviour overrides $GLOBALS['wpdb'].
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

// Unit suite: the plugin's own autoloader, so a test file that names a class gets that class - the
// same class the plugin would load - instead of depending on which classes an EARLIER test file
// happened to require. Measured on 2026-09-13: 33 of the unit files failed when run on their own
// ("Class BGCouriers_Settings not found" and the like) and passed only inside the full run. The
// explicit require_once lines in the tests stay: they say what a test is about, and they still
// work. Nothing here loads WordPress - the sources guard on ABSPATH, defined above.
if (!$bgcouriers_is_integration) {
    if (!defined('BGCOURIERS_PATH')) { define('BGCOURIERS_PATH', dirname(__DIR__) . '/'); }
    require_once dirname(__DIR__) . '/includes/class-bgcouriers-autoloader.php';
    BGCouriers_Autoloader::register();
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

/**
 * Set a courier up the way a merchant has to, so the checkout will actually offer it.
 *
 * Since 0.4.3 a courier passes four gates before a single rate is quoted - switched on, credentials
 * saved, credentials validated, and at least one delivery option left on (BGCouriers_Settings::
 * courier_offerable()). The integration tests were written before the last three existed and set only
 * the first, so seven of them had been failing against every build since: not one of them was testing
 * what it says it tests, because the courier they set up was never offerable in the first place.
 *
 * One helper rather than four copies of the same four lines, so the next gate is added in one place.
 */
function bgcouriers_test_set_up_courier(string $courier, array $creds = []): void {
    update_option('bgcouriers_' . $courier . '_enabled', 'yes');
    $fields = class_exists('BGCouriers_Settings')
        ? BGCouriers_Settings::credential_fields($courier)
        : ['username', 'password'];
    foreach ($fields as $f) {
        update_option('bgcouriers_' . $courier . '_' . $f, $creds[$f] ?? ('test-' . $f));
    }
    update_option('bgcouriers_' . $courier . '_validated', 'yes');
}
