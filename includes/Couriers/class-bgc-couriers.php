<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

/** Registry of couriers. Resolves the `bgc_courier` filter so the rest of the plugin
 *  fetches a courier by id without knowing the concrete class. */
final class BGC_Couriers {
    /** @var array<string,array{label:string,factory:callable}> */
    private static $defs = [];
    /** @var array<string,BGC_Courier_Interface> */
    private static $built = [];

    public static function register(string $id, string $label, callable $factory): void {
        self::$defs[$id] = ['label' => $label, 'factory' => $factory];
    }

    /** @return BGC_Courier_Interface|null */
    public static function get(string $id) {
        if (isset(self::$built[$id])) { return self::$built[$id]; }
        if (!isset(self::$defs[$id])) { return null; }
        return self::$built[$id] = call_user_func(self::$defs[$id]['factory']);
    }

    /** @return array<string,string> id => label */
    public static function all(): array {
        return array_map(static function ($d) { return $d['label']; }, self::$defs);
    }

    public static function reset(): void { self::$defs = []; self::$built = []; }

    /** Wire the resolver hook once, at boot. */
    public static function boot(): void {
        add_filter('bgc_courier', static function ($courier, $id) {
            return $courier ?: self::get((string) $id);
        }, 10, 2);
    }
}
