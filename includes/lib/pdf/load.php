<?php
/** Bootstraps the bundled FPDF + FPDI (for packing courier label PDFs onto A4/A6 sheets). */
defined('ABSPATH') || exit;
// phpcs:ignoreFile -- bundled third-party libraries (FPDF/FPDI).
// Both are namespaced to this plugin so they cannot collide with another plugin's copy: FPDF is loaded as
// BGCouriers_FPDF, and FPDI keeps its own setasign\Fpdi\ namespace. Loaded UNCONDITIONALLY on purpose - a
// class_exists() guard would hand control to whichever copy loaded first, which is exactly the failure mode
// the prefix removes.
if (!defined('BGCOURIERS_FPDF_FONTPATH')) { define('BGCOURIERS_FPDF_FONTPATH', __DIR__ . '/fpdf/font/'); }
require_once __DIR__ . '/fpdf/fpdf.php';
spl_autoload_register(static function ($class) {
    $prefix = 'setasign\\Fpdi\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $file = __DIR__ . '/fpdi/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require_once $file; }
});
