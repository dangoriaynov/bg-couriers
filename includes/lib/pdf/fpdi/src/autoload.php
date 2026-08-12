<?php
// phpcs:ignoreFile -- bundled third-party library (FPDF/FPDI), shipped unmodified.
if (!defined('ABSPATH')) { exit; } // direct-access protection

/**
 * This file is part of FPDI
 *
 * @package   BGCouriers\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

spl_autoload_register(static function ($class) {
    if (strpos($class, 'BGCouriers\Fpdi\\') === 0) {
        $filename = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 14)) . '.php';
        $fullpath = __DIR__ . DIRECTORY_SEPARATOR . $filename;

        if (is_file($fullpath)) {
            /** @noinspection PhpIncludeInspection */
            require_once $fullpath;
        }
    }
});
