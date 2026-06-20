<?php
defined('ABSPATH') || exit;

class BGC_Autoloader {
    public static function register(): void {
        spl_autoload_register([__CLASS__, 'load']);
    }
    public static function load(string $class): void {
        if (strpos($class, 'BGC_') !== 0) { return; }
        $slug = 'class-' . str_replace('_', '-', strtolower($class)) . '.php';
        foreach (['', 'Support/', 'Couriers/', 'Cache/', 'Shipping/', 'Checkout/', 'Admin/'] as $dir) {
            $path = BGC_PATH . 'includes/' . $dir . $slug;
            if (is_readable($path)) { require_once $path; return; }
            $iface = BGC_PATH . 'includes/' . $dir . str_replace('class-', 'interface-', $slug);
            if (is_readable($iface)) { require_once $iface; return; }
        }
    }
}
