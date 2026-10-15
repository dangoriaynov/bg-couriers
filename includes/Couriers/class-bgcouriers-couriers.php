<?php
defined('ABSPATH') || exit;

/** Registry of couriers, resolved through the `bgcouriers_courier` filter. */
final class BGCouriers_Couriers {
    /** @var array<string,array{label:string,factory:callable}> */
    private static $defs = [];
    /** @var array<string,BGCouriers_Courier_Interface> */
    private static $built = [];
    private static $booted = false;

    /** Re-registering an id overrides it (last wins), so a site can swap in its own implementation. */
    public static function register(string $id, string $label, callable $factory): void {
        self::$defs[$id] = ['label' => $label, 'factory' => $factory];
    }

    public static function get(string $id): ?BGCouriers_Courier_Interface {
        if (isset(self::$built[$id])) { return self::$built[$id]; }
        if (!isset(self::$defs[$id])) { return null; }
        return self::$built[$id] = (self::$defs[$id]['factory'])();
    }

    /** @return array<string,string> id => label */
    public static function all(): array {
        return array_map(static function ($d) { return $d['label']; }, self::$defs);
    }

    public static function reset(): void { self::$defs = []; self::$built = []; self::$booted = false; }

    /** Bundled logo filename (assets/img/couriers/<id>.svg, else .png), or '' when there is none. */
    public static function logo_file(string $id): string {
        if (!defined('BGCOURIERS_PATH') || preg_match('/[^a-z0-9_-]/', $id)) { return ''; }
        foreach (['svg', 'png'] as $ext) {
            if (is_readable(BGCOURIERS_PATH . 'assets/img/couriers/' . $id . '.' . $ext)) { return $id . '.' . $ext; }
        }
        return '';
    }

    public static function logo_url(string $id): string {
        $f = self::logo_file($id);
        if ($f === '' || !defined('BGCOURIERS_URL')) { return ''; }
        return BGCOURIERS_URL . 'assets/img/couriers/' . $f;
    }

    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        add_filter('bgcouriers_courier', static function ($courier, $id) {
            return $courier ?: self::get((string) $id);
        }, 10, 2);
    }
}
