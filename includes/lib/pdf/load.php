<?php
/** Bootstraps the bundled FPDF + FPDI (for packing courier label PDFs onto A4/A6 sheets). */
defined('ABSPATH') || exit;
// phpcs:ignoreFile -- bundled third-party library (FPDF/FPDI), shipped unmodified.
if (!class_exists('FPDF')) {
    if (!defined('FPDF_FONTPATH')) { define('FPDF_FONTPATH', __DIR__ . '/fpdf/font/'); }
    require_once __DIR__ . '/fpdf/fpdf.php';
}
spl_autoload_register(static function ($class) {
    $prefix = 'setasign\\Fpdi\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $file = __DIR__ . '/fpdi/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require_once $file; }
});
