<?php
// phpcs:ignoreFile -- bundled third-party library (FPDF/FPDI), shipped unmodified.

/**
 * This file is part of FPDI
 *
 * @package   BGCouriers\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace BGCouriers\Fpdi\PdfParser\Type;
if (!defined('ABSPATH')) { exit; } // direct-access protection

/**
 * Class representing a PDF null object
 */
class PdfNull extends PdfType
{
    // empty body
}
