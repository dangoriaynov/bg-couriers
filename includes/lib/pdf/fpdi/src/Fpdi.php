<?php
// phpcs:ignoreFile -- bundled third-party library (FPDF/FPDI), shipped unmodified.

/**
 * This file is part of FPDI
 *
 * @package   BGCouriers\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace BGCouriers\Fpdi;
if (!defined('ABSPATH')) { exit; } // direct-access protection

use BGCouriers\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use BGCouriers\Fpdi\PdfParser\PdfParserException;
use BGCouriers\Fpdi\PdfParser\Type\PdfIndirectObject;
use BGCouriers\Fpdi\PdfParser\Type\PdfNull;

/**
 * Class Fpdi
 *
 * This class let you import pages of existing PDF documents into a reusable structure for FPDF.
 */
class Fpdi extends FpdfTpl
{
    use FpdiTrait;
    use FpdfTrait;

    /**
     * FPDI version
     *
     * @string
     */
    const VERSION = '2.6.3';
}
