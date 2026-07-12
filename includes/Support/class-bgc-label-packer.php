<?php
defined('ABSPATH') || exit;

/**
 * Packs courier label PDFs onto sheets using the bundled FPDI + FPDF:
 *  - 'A6': one label per A6 page (for a sticker/label printer).
 *  - 'A4': as many labels per A4 sheet as fit, at their NATURAL size, using First-Fit-Decreasing-Height
 *    (FFDH) shelf packing - labels are sorted tallest-first and each is dropped into the first row (shelf)
 *    with room, so labels of ANY courier size share a sheet tightly (e.g. two 100x147 Speedy labels beside
 *    each other with a full-width Econt label below them). Rows run top-to-bottom for straight guillotine
 *    cuts - no wasted paper and no re-feeding the sheet (no duplex needed).
 * Each imported page keeps its aspect ratio (only oversized labels are shrunk to fit the page).
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
        // is passed as an explicit [w,h] mm size.
        $size = $isA6 ? [105, 148] : 'A4';
        [$pageW, $pageH] = $isA6 ? [105.0, 148.0] : [210.0, 297.0];

        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', $size);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        // Import every label page first (templates persist independent of output pages), keeping its scaled
        // natural size. A6 emits one centred label per page as it goes; A4 collects then FFDH-packs below.
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
                    $fit = min(1.0, $pageW / $s['width'], $pageH / $s['height']); // only shrink oversized labels
                    $w = $s['width'] * $fit;
                    $h = $s['height'] * $fit;
                    if ($isA6) { // one label per page, centred
                        $pdf->AddPage('P', $size);
                        $pdf->useTemplate($tpl, ($pageW - $w) / 2, ($pageH - $h) / 2, $w, $h);
                        continue;
                    }
                    $items[] = ['tpl' => $tpl, 'w' => $w, 'h' => $h];
                } catch (\Throwable $e) { /* skip an unreadable page but keep going */ }
            }
        }
        if ($isA6) { return $pdf->PageNo() > 0 ? $pdf->Output('S') : ''; }
        if (!$items) { return ''; }

        // Shelf-pack the labels twice - shortest-first and tallest-first - and keep whichever needs fewer
        // pages. On a tie, shortest-first wins: it fills the small labels together (e.g. 4 Speedy on a
        // sheet) and lets a big full-width label take its own page, instead of the big label stranding a
        // lone small label on a half-empty trailing page. Rows still cut apart with straight guillotine lines.
        $asc = $items;  usort($asc,  static function ($a, $b) { return $a['h'] <=> $b['h']; });
        $desc = $items; usort($desc, static function ($a, $b) { return $b['h'] <=> $a['h']; });
        $la = self::layout($asc, $pageW, $pageH);
        $ld = self::layout($desc, $pageW, $pageH);
        $best = ($ld['pages'] < $la['pages']) ? $ld : $la; // tie -> ascending (nicer grouping)

        for ($pg = 0; $pg < $best['pages']; $pg++) {
            $pdf->AddPage('P', $size);
            foreach ($best['placements'] as $pl) {
                if ($pl['page'] === $pg) { $pdf->useTemplate($pl['tpl'], $pl['x'], $pl['y'], $pl['w'], $pl['h']); }
            }
        }
        return $pdf->Output('S');
    }

    /**
     * Next-fit shelf packing of pre-ordered items onto pages: each item goes into the first row (shelf) on
     * the current page that still has width and is tall enough, else a new row, else a new page.
     *
     * @param array<int,array{tpl:mixed,w:float,h:float}> $items
     * @return array{placements:array<int,array>,pages:int}
     */
    private static function layout(array $items, float $pageW, float $pageH): array {
        $eps = 0.5; // mm tolerance for float rounding
        $placements = []; $shelves = []; $page = 0; $filled = 0.0;
        foreach ($items as $it) {
            $w = $it['w']; $h = $it['h']; $done = false;
            foreach ($shelves as $i => $sh) {
                if ($sh['used'] + $w <= $pageW + $eps && $h <= $sh['h'] + $eps) {
                    $placements[]        = ['page' => $page, 'tpl' => $it['tpl'], 'x' => $sh['used'], 'y' => $sh['y'], 'w' => $w, 'h' => $h];
                    $shelves[$i]['used'] += $w;
                    $done = true;
                    break;
                }
            }
            if ($done) { continue; }
            if ($filled + $h > $pageH + $eps && $shelves) { $page++; $shelves = []; $filled = 0.0; }
            $y = $filled;
            $shelves[]    = ['y' => $y, 'h' => $h, 'used' => $w];
            $filled      += $h;
            $placements[] = ['page' => $page, 'tpl' => $it['tpl'], 'x' => 0.0, 'y' => $y, 'w' => $w, 'h' => $h];
        }
        return ['placements' => $placements, 'pages' => $page + 1];
    }
}
