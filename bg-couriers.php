<?php
/**
 * Plugin Name: BG Couriers for WooCommerce
 * Description: Shipping with Bulgarian couriers (Speedy, Econt, BOX NOW, Pigeon, Sameday) - office/address/locker delivery, live rates, labels and tracking.
 * Version: 0.2.0
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

define('BGC_VERSION', '0.2.0');
define('BGC_FILE', __FILE__);
define('BGC_PATH', plugin_dir_path(__FILE__));
define('BGC_URL', plugin_dir_url(__FILE__));

require_once BGC_PATH . 'includes/class-bgc-autoloader.php';
BGC_Autoloader::register();

// Translations: WordPress auto-loads them just-in-time from /languages (bg_BG ships there) for the
// plugin's own text domain, so no manual load_plugin_textdomain() call is needed (discouraged since WP 4.6).

register_activation_hook(__FILE__, function () {
    require_once BGC_PATH . 'includes/class-bgc-autoloader.php';
    BGC_Autoloader::register();
    BGC_Schema::create();
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
    if (get_option('bgc_db_version') !== BGC_VERSION) {
        BGC_Schema::create();
        update_option('bgc_db_version', BGC_VERSION);
    }
    BGC_Plugin::instance();
});
