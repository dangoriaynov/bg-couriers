<?php
/** Bootstraps the bundled FPDF + FPDI (for packing courier label PDFs onto A4/A6 sheets). */
defined('ABSPATH') || exit;
// phpcs:ignoreFile -- bundled third-party libraries (FPDF/FPDI).
// Both are namespaced to THIS PLUGIN so they cannot collide with another plugin's copy: FPDF is loaded as
// BGCouriers_FPDF, and FPDI's namespace is rewritten from the vendor's setasign\Fpdi\ to BGCouriers\Fpdi\.
// The vendor namespace is not a unique one - every plugin that bundles FPDI declares the same symbols, so
// whichever loaded first would win and ours would silently be somebody else's version. Loaded
// UNCONDITIONALLY on purpose: a class_exists() guard IS that failure mode, not a defence against it.
// The libraries are otherwise untouched, copyright notices included (FPDF: unrestricted; FPDI: MIT).
if (!defined('BGCOURIERS_FPDF_FONTPATH')) { define('BGCOURIERS_FPDF_FONTPATH', __DIR__ . '/fpdf/font/'); }
require_once __DIR__ . '/fpdf/fpdf.php';
spl_autoload_register(static function ($class) {
    $prefix = 'BGCouriers\\Fpdi\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $file = __DIR__ . '/fpdi/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require_once $file; }
});
