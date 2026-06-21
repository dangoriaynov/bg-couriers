<?php
defined('ABSPATH') || exit;

class BGC_Autoloader {
    public static function register(): void {
        spl_autoload_register([__CLASS__, 'load']);
    }
    public static function load(string $class): void {
        if (strpos($class, 'BGC_') !== 0) { return; }
        $base = str_replace('_', '-', strtolower($class));
        $candidates = ['class-' . $base . '.php'];
        if (substr($class, -10) === '_Interface') {
            $candidates[] = 'interface-' . str_replace('_', '-', strtolower(substr($class, 0, -10))) . '.php';
        }
        if (strpos($class, 'BGC_Abstract_') === 0) {
            $candidates[] = 'abstract-bgc-' . str_replace('_', '-', strtolower(substr($class, strlen('BGC_Abstract_')))) . '.php';
        }
        foreach (['', 'Support/', 'Couriers/', 'Cache/', 'Shipping/', 'Checkout/', 'Admin/'] as $dir) {
            foreach ($candidates as $file) {
                $path = BGC_PATH . 'includes/' . $dir . $file;
                if (is_readable($path)) { require_once $path; return; }
            }
        }
    }
}
