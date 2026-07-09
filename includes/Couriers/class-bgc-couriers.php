<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

/** Registry of couriers. Resolves the `bgc_courier` filter so the rest of the plugin
 *  fetches a courier by id without knowing the concrete class. */
final class BGC_Couriers {
    /** @var array<string,array{label:string,factory:callable}> */
    private static $defs = [];
    /** @var array<string,BGC_Courier_Interface> */
    private static $built = [];
    private static $booted = false;

    /** Register a courier. Re-registering the same id overrides it (last wins) — intentional, so a
     *  site can swap in its own implementation of a courier. */
    public static function register(string $id, string $label, callable $factory): void {
        self::$defs[$id] = ['label' => $label, 'factory' => $factory];
    }

    public static function get(string $id): ?BGC_Courier_Interface {
        if (isset(self::$built[$id])) { return self::$built[$id]; }
        if (!isset(self::$defs[$id])) { return null; }
        return self::$built[$id] = (self::$defs[$id]['factory'])();
    }

    /** @return array<string,string> id => label */
    public static function all(): array {
        return array_map(static function ($d) { return $d['label']; }, self::$defs);
    }

    public static function reset(): void { self::$defs = []; self::$built = []; self::$booted = false; }

    /** Bundled brand-logo filename for a courier (assets/img/couriers/), or '' if none. */
    public static function logo_file(string $id): string {
        $map = ['speedy' => 'speedy.png', 'econt' => 'econt.png', 'pigeon' => 'pigeon.png', 'boxnow' => 'boxnow.svg', 'sameday' => 'sameday.png'];
        return $map[$id] ?? '';
    }

    /** Public URL of a courier's bundled logo, or '' when there is no readable file. */
    public static function logo_url(string $id): string {
        $f = self::logo_file($id);
        if ($f === '' || !defined('BGC_PATH') || !defined('BGC_URL') || !is_readable(BGC_PATH . 'assets/img/couriers/' . $f)) {
            return '';
        }
        return BGC_URL . 'assets/img/couriers/' . $f;
    }

    /** Wire the resolver hook once, at boot (idempotent — safe to call more than once). */
    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        add_filter('bgc_courier', static function ($courier, $id) {
            return $courier ?: self::get((string) $id);
        }, 10, 2);
    }
}
