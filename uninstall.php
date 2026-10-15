<?php
/** Uninstall: remove everything this plugin created; order meta is deliberately kept. */

defined('WP_UNINSTALL_PLUGIN') || exit;

if (!defined('BGCOURIERS_PATH')) { define('BGCOURIERS_PATH', plugin_dir_path(__FILE__)); }
require_once BGCOURIERS_PATH . 'includes/class-bgcouriers-autoloader.php';
BGCouriers_Autoloader::register();

function bgcouriers_uninstall_site() {
    global $wpdb;

    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, one pass, no cache to prime
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE 'bgcouriers\_%'
             OR option_name LIKE '\_transient\_bgcouriers\_%'
             OR option_name LIKE '\_transient\_timeout\_bgcouriers\_%'
             OR option_name LIKE 'woocommerce\_bgcouriers\_%\_settings'"
    );

    foreach ((array) $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}bgcouriers\_%'") as $table) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be bound
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping this plugin's own tables IS the point of an uninstall
    }

    $zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $zone_methods)) === $zone_methods) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall
        $wpdb->query("DELETE FROM `" . esc_sql($zone_methods) . "` WHERE method_id LIKE 'bgcouriers\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name cannot be bound
    }

    // Label PDFs hold personal data; WP_Filesystem is not loaded during uninstall.
    $uploads = wp_upload_dir();
    $dir     = trailingslashit($uploads['basedir']) . 'bgc-labels';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->is_dir($dir)) {
        $wp_filesystem->delete($dir, true);
    }

    BGCouriers_Plugin::clear_cron();
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $bgcouriers_site_id) {
        switch_to_blog((int) $bgcouriers_site_id);
        bgcouriers_uninstall_site();
        restore_current_blog();
    }
} else {
    bgcouriers_uninstall_site();
}
