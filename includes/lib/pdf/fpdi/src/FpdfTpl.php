<?php
// phpcs:ignoreFile -- bundled third-party library (FPDI); only the parent class name is changed, to the
// prefixed BGCouriers_FPDF (see includes/lib/pdf/fpdf/fpdf.php).

/**
 * This file is part of FPDI
 *
 * @package   setasign\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace setasign\Fpdi;
if (!defined('ABSPATH')) { exit; } // direct-access protection

/**
 * Class FpdfTpl
 *
 * This class adds a templating feature to FPDF.
 */
class FpdfTpl extends \BGCouriers_FPDF
{
    use FpdfTplTrait;
}
