<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

class BGC_Settings_Migrator {
    const VERSION = 1;
    public static function migrate(): void {
        $current = (int) get_option('bgc_settings_version', 0);
        if ($current >= self::VERSION) { return; }
        // (future migrations switch on $current here)
        update_option('bgc_settings_version', self::VERSION);
    }
}
