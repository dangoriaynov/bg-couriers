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
            // Also try abstract- prefix (e.g. abstract-bgc-courier.php for BGC_Abstract_Courier).
            $abstract = BGC_PATH . 'includes/' . $dir . preg_replace('/^class-bgc-abstract-/', 'abstract-bgc-', $slug);
            if ($abstract !== $slug && is_readable($abstract)) { require_once $abstract; return; }
            // Also try interface- without trailing "-interface" suffix
            // (e.g. interface-bgc-courier.php for BGC_Courier_Interface).
            $iface_short = BGC_PATH . 'includes/' . $dir . preg_replace('/^class-(.+)-interface\.php$/', 'interface-$1.php', $slug);
            if ($iface_short !== $slug && is_readable($iface_short)) { require_once $iface_short; return; }
        }
    }
}
