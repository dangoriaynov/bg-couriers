<?php
// phpcs:ignoreFile -- bundled third-party library (FPDF/FPDI), shipped unmodified.

/**
 * This file is part of FPDI
 *
 * @package   BGCouriers\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace BGCouriers\Fpdi\PdfReader;
if (!defined('ABSPATH')) { exit; } // direct-access protection

use BGCouriers\Fpdi\FpdiException;

/**
 * Exception for the pdf reader class
 */
class PdfReaderException extends FpdiException
{
    /**
     * @var int
     */
    const KIDS_EMPTY = 0x0101;

    /**
     * @var int
     */
    const UNEXPECTED_DATA_TYPE = 0x0102;

    /**
     * @var int
     */
    const MISSING_DATA = 0x0103;
}
