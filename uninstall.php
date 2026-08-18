<?php
/**
 * Uninstall: remove everything this plugin created, and nothing else.
 *
 * WordPress runs this only when the plugin is DELETED, not on deactivation. Until it existed, deleting
 * the plugin left 183 options, three tables and the shipping-zone rows behind on a real site - so
 * "install it again from scratch" was not something a merchant could actually do.
 *
 * What is deliberately KEPT: the orders. Their _bgcouriers_* meta is the record of what was shipped,
 * which waybill went out and what the customer was told - removing it would rewrite the shop's own
 * history to tidy up a plugin. A reinstall reads that meta again and carries on.
 *
 * @package BG_Couriers
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Remove the plugin's own state from the current site.
 */
function bgcouriers_uninstall_site() {
    global $wpdb;

    // Settings, cached credentials-validation flags and every transient this plugin parked in options.
    // The instance settings WooCommerce writes for each shipping method it holds in the zone are named
    // woocommerce_bgcouriers_<courier>_<instance>_settings, so they need their own sweep.
    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, one pass, no cache to prime
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE 'bgcouriers\_%'
             OR option_name LIKE '\_transient\_bgcouriers\_%'
             OR option_name LIKE '\_transient\_timeout\_bgcouriers\_%'
             OR option_name LIKE 'woocommerce\_bgcouriers\_%\_settings'"
    );

    // The nomenclature cache: cities, offices and the daily reference prices.
    foreach ((array) $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}bgcouriers\_%'") as $table) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be bound
        $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping this plugin's own tables IS the point of an uninstall
    }

    // The couriers this plugin added to a shipping zone. Left behind, they show as broken rows in
    // WooCommerce's own settings for a shipping method whose code is gone.
    $zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $zone_methods)) === $zone_methods) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall
        $wpdb->query("DELETE FROM `" . esc_sql($zone_methods) . "` WHERE method_id LIKE 'bgcouriers\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name cannot be bound
    }

    // Label PDFs. These are the one thing here that is personal data - a name, an address and a phone
    // number per sheet - so they go with the plugin that wrote them. Through WP_Filesystem, which is not
    // loaded during an uninstall and has to be asked for.
    $uploads = wp_upload_dir();
    $dir     = trailingslashit($uploads['basedir']) . 'bgc-labels';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->is_dir($dir)) {
        $wp_filesystem->delete($dir, true); // recursive: the PDFs and the directory holding them
    }

    // Every schedule this plugin books, so a deleted plugin stops waking WP-Cron. The names are written
    // out rather than read from the classes: WordPress loads THIS FILE ALONE on uninstall, with none of
    // the plugin's code, so a constant reference here would be a fatal.
    foreach (['bgcouriers_poll_tracking', 'bgcouriers_weekly_sync', 'bgcouriers_daily_rates', 'bgcouriers_retry_autolabel'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

if (is_multisite()) {
    // Each site in the network has its own options, tables and zones.
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $bgcouriers_site_id) {
        switch_to_blog((int) $bgcouriers_site_id);
        bgcouriers_uninstall_site();
        restore_current_blog();
    }
} else {
    bgcouriers_uninstall_site();
}
