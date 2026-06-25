<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Settings_Migrator {
    const VERSION = 2;

    public static function migrate(): void {
        $current = (int) get_option('bgc_settings_version', 0);
        if ($current >= self::VERSION) { return; }
        if ($current < 2) { self::migrate_to_flat_options(); }
        update_option('bgc_settings_version', self::VERSION);
    }

    /** v1 stored serialized bgc_speedy_settings/bgc_global_settings arrays; v2 uses flat options. */
    private static function migrate_to_flat_options(): void {
        $old = get_option('bgc_speedy_settings', []);
        if (is_array($old) && $old) {
            $map = [
                'enabled'   => 'bgc_speedy_enabled',
                'env'       => 'bgc_speedy_environment',
                'username'  => 'bgc_speedy_username',
                'password'  => 'bgc_speedy_password',
            ];
            foreach ($map as $k => $opt) {
                if (isset($old[$k])) { update_option($opt, $old[$k]); }
            }
            $flat = isset($old['flat_fallback']) ? (float) $old['flat_fallback'] : 0;
            if ($flat > 0) {
                foreach (['office', 'address', 'automat'] as $m) {
                    update_option('bgc_speedy_' . $m . '_price', $flat);
                    update_option('bgc_speedy_' . $m . '_currency', 'BGN');
                }
            }
        }
        $g = get_option('bgc_global_settings', []);
        if (is_array($g) && isset($g['dual_currency'])) {
            update_option('bgc_dual_currency', $g['dual_currency']);
        }
    }
}
