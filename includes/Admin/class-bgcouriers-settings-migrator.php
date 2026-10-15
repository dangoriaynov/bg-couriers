<?php
defined('ABSPATH') || exit;

class BGCouriers_Settings_Migrator {
    const VERSION = 4;

    public static function migrate(): void {
        $current = (int) get_option('bgcouriers_settings_version', 0);
        if ($current >= self::VERSION) { return; }
        if ($current < 2) { self::migrate_to_flat_options(); }
        if ($current < 3) { self::migrate_shipment_contents(); }
        if ($current < 4) { self::drop_dual_currency(); }
        update_option('bgcouriers_settings_version', self::VERSION);
    }

    /** Own numbered step: earlier steps never re-run on installs already past them. */
    private static function drop_dual_currency(): void {
        delete_option('bgcouriers_dual_currency');
    }

    private static function migrate_shipment_contents(): void {
        if (trim((string) get_option('bgcouriers_shipment_contents', '')) !== '') { return; }
        $legacy = trim((string) get_option('bgcouriers_speedy_contents', ''))
            ?: trim((string) get_option('bgcouriers_econt_shipment_description', ''));
        if ($legacy !== '') { update_option('bgcouriers_shipment_contents', $legacy); }
    }

    private static function migrate_to_flat_options(): void {
        $old = get_option('bgcouriers_speedy_settings', []);
        if (is_array($old) && $old) {
            $map = [
                'enabled'   => 'bgcouriers_speedy_enabled',
                'username'  => 'bgcouriers_speedy_username',
                'password'  => 'bgcouriers_speedy_password',
            ];
            foreach ($map as $k => $opt) {
                if (isset($old[$k])) { update_option($opt, $old[$k]); }
            }
            $flat = isset($old['flat_fallback']) ? (float) $old['flat_fallback'] : 0;
            if ($flat > 0) {
                foreach (['office', 'address', 'automat'] as $m) {
                    update_option('bgcouriers_speedy_' . $m . '_price', $flat);
                    update_option('bgcouriers_speedy_' . $m . '_currency', 'BGN');
                }
            }
        }
    }
}
