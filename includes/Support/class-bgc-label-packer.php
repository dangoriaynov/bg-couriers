<?php
defined('ABSPATH') || exit;

/**
 * Packs courier label PDFs onto sheets using the bundled FPDI + FPDF so labels come out large and readable:
 *  - 'A6': one label per A6 page, scaled to fill it (for a sticker/label printer).
 *  - 'A4': LANDSCAPE A4 pages. Labels are grouped by size (couriers have consistent sizes) and each group
 *    gets the grid that makes its labels as BIG as possible while filling the sheet - a portrait sticker
 *    (Speedy 100x147) fills half a landscape sheet, so two sit side by side at ~1.4x natural size; a full
 *    A4-landscape label (Econt 297x210) fills the whole sheet. Each label is scaled to fill its grid cell
 *    (aspect preserved) and centred; cells are cut apart with straight guillotine lines (no duplex re-feed).
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
        // FPDF only knows A3/A4/A5/Letter/Legal by name, so A6 is an explicit [w,h] mm size. A4 sheets are
        // LANDSCAPE (297x210) so a portrait sticker fills half the sheet at a big, readable size.
        $size = $isA6 ? [105, 148] : 'A4';
        [$pageW, $pageH] = $isA6 ? [105.0, 148.0] : [297.0, 210.0];

        $pdf = new \setasign\Fpdi\Fpdi($isA6 ? 'P' : 'L', 'mm', $size);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        // Import each label page (templates persist independent of output pages). A6 emits one filled label
        // per page as it goes; A4 collects them, then grids each size group below.
        $items = [];
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
                    if ($isA6) { // one label per sticker page, scaled to fill, centred
                        $sc = min($pageW / $s['width'], $pageH / $s['height']);
                        $w = $s['width'] * $sc; $h = $s['height'] * $sc;
                        $pdf->AddPage('P', $size);
                        $pdf->useTemplate($tpl, ($pageW - $w) / 2, ($pageH - $h) / 2, $w, $h);
                        continue;
                    }
                    $items[] = ['tpl' => $tpl, 'w' => (float) $s['width'], 'h' => (float) $s['height']];
                } catch (\Throwable $e) { /* skip an unreadable page but keep going */ }
            }
        }
        if ($isA6) { return $pdf->PageNo() > 0 ? $pdf->Output('S') : ''; }
        if (!$items) { return ''; }

        // Group same-size labels and give each group its best grid; lay each group out big.
        $groups = [];
        foreach ($items as $it) { $groups[round($it['w']) . 'x' . round($it['h'])][] = $it; }
        foreach ($groups as $g) {
            [$cols, $rows] = self::best_grid((float) $g[0]['w'], (float) $g[0]['h'], $pageW, $pageH);
            $cellW = $pageW / $cols; $cellH = $pageH / $rows;
            foreach (array_chunk($g, $cols * $rows) as $chunk) {
                $pdf->AddPage('L', $size);
                foreach ($chunk as $idx => $it) {
                    $cx = ($idx % $cols) * $cellW;
                    $cy = intdiv($idx, $cols) * $cellH;
                    $sc = min($cellW / $it['w'], $cellH / $it['h']); // fill the cell, keep aspect
                    $w = $it['w'] * $sc; $h = $it['h'] * $sc;
                    $pdf->useTemplate($it['tpl'], $cx + ($cellW - $w) / 2, $cy + ($cellH - $h) / 2, $w, $h);
                }
            }
        }
        return $pdf->PageNo() > 0 ? $pdf->Output('S') : '';
    }

    /**
     * The grid (cols x rows) that lets a w x h label fill the page as LARGELY as possible: it maximises the
     * per-label scale, then packs as many cells as fit at (within a hair of) that scale. So a portrait
     * sticker -> 2 columns x 1 row (two big labels side by side); a full A4-landscape label -> 1 x 1.
     *
     * @return array{0:int,1:int} [cols, rows]
     */
    private static function best_grid(float $w, float $h, float $pageW, float $pageH): array {
        $best = ['cols' => 1, 'rows' => 1, 'scale' => min($pageW / $w, $pageH / $h), 'cells' => 1];
        for ($cols = 1; $cols <= 6; $cols++) {
            for ($rows = 1; $rows <= 6; $rows++) {
                $scale = min(($pageW / $cols) / $w, ($pageH / $rows) / $h);
                $cells = $cols * $rows;
                if ($scale > $best['scale'] + 0.01
                    || (abs($scale - $best['scale']) <= 0.01 && $cells > $best['cells'])) {
                    $best = ['cols' => $cols, 'rows' => $rows, 'scale' => $scale, 'cells' => $cells];
                }
            }
        }
        return [$best['cols'], $best['rows']];
    }
}
