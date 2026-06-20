<?php
/**
 * Plugin Name: BG Couriers for WooCommerce
 * Description: Shipping with Bulgarian couriers (Speedy, Econt, BoxNow, Pigeon).
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Text Domain: bg-couriers
 */
defined('ABSPATH') || exit;

define('BGC_VERSION', '0.1.0');
define('BGC_FILE', __FILE__);
define('BGC_PATH', plugin_dir_path(__FILE__));
define('BGC_URL', plugin_dir_url(__FILE__));

require_once BGC_PATH . 'includes/class-bgc-autoloader.php';
BGC_Autoloader::register();

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) { return; }
    BGC_Plugin::instance();
});
