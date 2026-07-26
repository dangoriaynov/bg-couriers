<?php
defined('ABSPATH') || exit;

class BGC_Settings_Migrator {
    const VERSION = 3;

    public static function migrate(): void {
        $current = (int) get_option('bgc_settings_version', 0);
        if ($current >= self::VERSION) { return; }
        if ($current < 2) { self::migrate_to_flat_options(); }
        if ($current < 3) { self::migrate_shipment_contents(); }
        update_option('bgc_settings_version', self::VERSION);
    }

    /**
     * The parcel-contents description moved from per-courier fields to one General field, but the read
     * silently fell back to the old options - so the General field looked empty while waybills kept
     * printing the legacy value, with no way to tell where it came from. Copy it across once.
     */
    private static function migrate_shipment_contents(): void {
        if (trim((string) get_option('bgc_shipment_contents', '')) !== '') { return; }
        $legacy = trim((string) get_option('bgc_speedy_contents', ''))
            ?: trim((string) get_option('bgc_econt_shipment_description', ''));
        if ($legacy !== '') { update_option('bgc_shipment_contents', $legacy); }
    }

    /** v1 stored serialized bgc_speedy_settings/bgc_global_settings arrays; v2 uses flat options. */
    private static function migrate_to_flat_options(): void {
        $old = get_option('bgc_speedy_settings', []);
        if (is_array($old) && $old) {
            $map = [
                'enabled'   => 'bgc_speedy_enabled',
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
