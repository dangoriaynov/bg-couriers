<?php
/**
 * Plugin Name: BG Couriers for WooCommerce
 * Description: Shipping with Bulgarian couriers (Speedy, Econt, BOX NOW, Pigeon, Sameday, Express One, Evropat) - office/address/locker delivery, live rates, labels and tracking.
 * Plugin URI: https://github.com/dangoriaynov/bg-couriers
 * Version: 0.4.36
 * Author: Дан Горяйнов
 * Author URI: https://github.com/dangoriaynov
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * Text Domain: bg-couriers
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
defined('ABSPATH') || exit;

define('BGCOURIERS_VERSION', '0.4.36');
define('BGCOURIERS_FILE', __FILE__);
define('BGCOURIERS_PATH', plugin_dir_path(__FILE__));
define('BGCOURIERS_URL', plugin_dir_url(__FILE__));

require_once BGCOURIERS_PATH . 'includes/class-bgcouriers-autoloader.php';
BGCouriers_Autoloader::register();

// Fallback for shops where just-in-time loading fails (renamed plugin folder, stale plugin cache).
add_action('init', static function () {
    load_plugin_textdomain('bg-couriers', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 0);

register_activation_hook(__FILE__, function () {
    require_once BGCOURIERS_PATH . 'includes/class-bgcouriers-autoloader.php';
    BGCouriers_Autoloader::register();
    BGCouriers_Schema::create();
});

register_deactivation_hook(__FILE__, function () {
    BGCouriers_Plugin::clear_cron();
});

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) { return; }
    // Must be asked before the db_version write below makes it unanswerable.
    if (!defined('BGCOURIERS_NEW_INSTALL')) {
        define('BGCOURIERS_NEW_INSTALL', get_option('bgcouriers_db_version', '') === '');
    }
    if (get_option('bgcouriers_db_version') !== BGCOURIERS_VERSION) {
        BGCouriers_Schema::create();
        update_option('bgcouriers_db_version', BGCOURIERS_VERSION);
        if (!BGCOURIERS_NEW_INSTALL) { BGCouriers_Sync::schedule_once(); }
    }
    BGCouriers_Plugin::instance();
});
