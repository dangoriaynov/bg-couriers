<?php
/**
 * Plugin Name: BG Couriers for WooCommerce
 * Description: Shipping with Bulgarian couriers (Speedy, Econt, BOX NOW, Pigeon, Sameday) - office/address/locker delivery, live rates, labels and tracking.
 * Plugin URI: https://github.com/dangoriaynov/bg-couriers
 * Version: 0.2.2
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

define('BGCOURIERS_VERSION', '0.2.2');
define('BGCOURIERS_FILE', __FILE__);
define('BGCOURIERS_PATH', plugin_dir_path(__FILE__));
define('BGCOURIERS_URL', plugin_dir_url(__FILE__));

require_once BGCOURIERS_PATH . 'includes/class-bgcouriers-autoloader.php';
BGCouriers_Autoloader::register();

// Translations: WordPress auto-loads them just-in-time from /languages (bg_BG ships there) for the
// plugin's own text domain, so no manual load_plugin_textdomain() call is needed (discouraged since WP 4.6).

register_activation_hook(__FILE__, function () {
    require_once BGCOURIERS_PATH . 'includes/class-bgcouriers-autoloader.php';
    BGCouriers_Autoloader::register();
    BGCouriers_Schema::create();
});

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) { return; }
    // Run schema upgrades on version change (dbDelta is idempotent - adds new columns like office lat/lng
    // to existing installs, since the activation hook doesn't fire on a plugin update).
    if (get_option('bgcouriers_db_version') !== BGCOURIERS_VERSION) {
        BGCouriers_Schema::create();
        update_option('bgcouriers_db_version', BGCOURIERS_VERSION);
    }
    BGCouriers_Plugin::instance();
});
