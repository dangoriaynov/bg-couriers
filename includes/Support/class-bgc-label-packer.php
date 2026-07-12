<?php
defined('ABSPATH') || defined('PHPUNIT_COMPOSER_INSTALL') || exit;

/**
 * Packs courier label PDFs onto sheets using the bundled FPDI + FPDF:
 *  - 'A6': one label per A6 page (for a sticker/label printer).
 *  - 'A4': four labels per A4 sheet in a 2x2 grid, so a full sheet is cut into quarters with two
 *    straight guillotine cuts - no paper wasted and no re-feeding the sheet (no duplex needed).
 * Each imported page is scaled to fit its cell preserving aspect, so mixed courier label sizes still tile.
 */
class BGC_Label_Packer {

    public static function available(): bool {
        if (!defined('BGC_PATH')) { return false; }
        require_once BGC_PATH . 'includes/lib/pdf/load.php';
        return class_exists('setasign\\Fpdi\\Fpdi');
    }

    /**
     * @param string[] $pdfs Raw PDF byte strings (one per label).
     * @param string   $paper 'A4' or 'A6'.
     * @return string Combined PDF bytes, or '' if nothing could be imported.
     */
    public static function pack(array $pdfs, string $paper): string {
        if (!self::available()) { return ''; }
        $isA6 = strtoupper($paper) === 'A6';
        // A6 = one label per page (sticker printer). FPDF only knows A3/A4/A5/Letter/Legal by name, so A6
        // is passed as an explicit [w,h] mm size. A4 = shelf-pack labels at NATURAL size (small Speedy
        // labels pack ~4/sheet, large A4-landscape Econt ones ~2/sheet), rows top-to-bottom for straight cuts.
        $size = $isA6 ? [105, 148] : 'A4';
        [$pageW, $pageH] = $isA6 ? [105.0, 148.0] : [210.0, 297.0];

        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', $size);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $imported = 0; $x = 0.0; $y = 0.0; $rowH = 0.0; $onPage = false;

        foreach ($pdfs as $bytes) {
            if (!is_string($bytes) || $bytes === '') { continue; }
            try {
                $reader = \setasign\Fpdi\PdfParser\StreamReader::createByString($bytes);
                $count  = $pdf->setSourceFile($reader);
            } catch (\Throwable $e) { continue; } // FPDI can't read this one (encrypted / object streams) - skip it
            for ($p = 1; $p <= $count; $p++) {
                try {
                    $tpl = $pdf->importPage($p);
                    $s   = $pdf->getTemplateSize($tpl);
                    $fit = min(1.0, $pageW / $s['width'], $pageH / $s['height']); // only shrink oversized labels
                    $w = $s['width'] * $fit;
                    $h = $s['height'] * $fit;
                    if ($isA6) { // one label per page, centred
                        $pdf->AddPage('P', $size);
                        $pdf->useTemplate($tpl, ($pageW - $w) / 2, ($pageH - $h) / 2, $w, $h);
                        $imported++;
                        continue;
                    }
                    if ($onPage && $x + $w > $pageW + 0.5) { $x = 0; $y += $rowH; $rowH = 0; } // wrap to next row
                    if (!$onPage || $y + $h > $pageH + 0.5) { $pdf->AddPage('P', $size); $x = 0; $y = 0; $rowH = 0; $onPage = true; }
                    $pdf->useTemplate($tpl, $x, $y, $w, $h);
                    $x += $w;
                    $rowH = max($rowH, $h);
                    $imported++;
                } catch (\Throwable $e) { /* skip an unreadable page but keep going */ }
            }
        }
        return $imported > 0 ? $pdf->Output('S') : '';
    }
}
