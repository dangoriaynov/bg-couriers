<?php
// phpcs:ignoreFile -- bundled third-party library (FPDF/FPDI). Modified only to carry this plugin's
// namespace instead of the vendor's, plus the direct-access guard below. Not actually used: the plugin
// registers its own autoloader in includes/lib/pdf/load.php. Kept working so it cannot mislead anyone.
if (!defined('ABSPATH')) { exit; } // direct-access protection

/**
 * This file is part of FPDI
 *
 * @package   BGCouriers\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

spl_autoload_register(static function ($class) {
    // The offset is measured, not written down: upstream hardcoded 14 for its own 'setasign\Fpdi\'.
    $prefix = 'BGCouriers\\Fpdi\\';
    if (strpos($class, $prefix) === 0) {
        $filename = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
        $fullpath = __DIR__ . DIRECTORY_SEPARATOR . $filename;

        if (is_file($fullpath)) {
            /** @noinspection PhpIncludeInspection */
            require_once $fullpath;
        }
    }
});
