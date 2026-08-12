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

use BGCouriers\Fpdi\PdfParser\StreamReader;
use BGCouriers\Fpdi\PdfParser\Tokenizer;

/**
 * Class representing a PDF name object
 */
class PdfName extends PdfType
{
    /**
     * Parses a name object from the passed tokenizer and stream-reader.
     *
     * @param Tokenizer $tokenizer
     * @param StreamReader $streamReader
     * @return self
     */
    public static function parse(Tokenizer $tokenizer, StreamReader $streamReader)
    {
        $v = new self();
        if (\strspn($streamReader->getByte(), "\x00\x09\x0A\x0C\x0D\x20()<>[]{}/%") === 0) {
            $v->value = (string) $tokenizer->getNextToken();
            return $v;
        }

        $v->value = '';
        return $v;
    }

    /**
     * Unescapes a name string.
     *
     * @param string $value
     * @return string
     */
    public static function unescape($value)
    {
        if (strpos($value, '#') === false) {
            return $value;
        }

        return preg_replace_callback('/#([a-fA-F\d]{2})/', function ($matches) {
            return chr(hexdec($matches[1]));
        }, $value);
    }

    /**
     * Helper method to create an instance.
     *
     * @param string $string
     * @return self
     */
    public static function create($string)
    {
        $v = new self();
        $v->value = $string;

        return $v;
    }

    /**
     * Ensures that the passed value is a PdfName instance.
     *
     * @param mixed $name
     * @return self
     * @throws PdfTypeException
     */
    public static function ensure($name)
    {
        return PdfType::ensureType(self::class, $name, 'Name value expected.');
    }
}
