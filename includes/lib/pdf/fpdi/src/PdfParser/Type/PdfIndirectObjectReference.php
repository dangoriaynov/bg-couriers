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
 * Class representing an indirect object reference
 */
class PdfIndirectObjectReference extends PdfType
{
    /**
     * Helper method to create an instance.
     *
     * @param int $objectNumber
     * @param int $generationNumber
     * @return self
     */
    public static function create($objectNumber, $generationNumber)
    {
        $v = new self();
        $v->value = (int) $objectNumber;
        $v->generationNumber = (int) $generationNumber;

        return $v;
    }

    /**
     * Ensures that the passed value is a PdfIndirectObject instance.
     *
     * @param mixed $value
     * @return self
     * @throws PdfTypeException
     */
    public static function ensure($value)
    {
        return PdfType::ensureType(self::class, $value, 'Indirect reference value expected.');
    }

    /**
     * The generation number.
     *
     * @var int
     */
    public $generationNumber;
}
