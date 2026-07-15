<?php
defined('ABSPATH') || exit;

/**
 * Packs courier label PDFs onto sheets using the bundled FPDI + FPDF, NEVER scaling or cropping a label - it
 * is always placed at the exact native size the courier produced (only the PDF's own CropBox, i.e. declared
 * empty margin, is trimmed on import). Two modes:
 *  - 'A6': one label per page, each page sized to that label's own native dimensions (sticker/label printer).
 *  - 'A4': First-Fit-Decreasing-Height (FFDH) shelf packing of labels that fit within A4, at their NATURAL
 *    size - labels sorted by height and dropped into the first row with room, so labels of any courier size
 *    share a sheet tightly with straight guillotine cuts. A label larger than A4 gets its own native-size page
 *    (rather than being shrunk).
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

        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4'); // base doc; pages are added per label with explicit sizes
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        // Import every label page first (templates persist independent of output pages), at its NATIVE size.
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
                    $items[] = ['tpl' => $tpl, 'w' => (float) $s['width'], 'h' => (float) $s['height']];
                } catch (\Throwable $e) { /* skip an unreadable page but keep going */ }
            }
        }
        if (!$items) { return ''; }

        if ($isA6) { // one label per page, each page the label's own native size - never scaled
            foreach ($items as $it) { self::add_native_page($pdf, $it); }
            return $pdf->PageNo() > 0 ? $pdf->Output('S') : '';
        }

        // A4: labels that fit within an A4 sheet are FFDH-packed at native size; anything larger gets its own
        // native-size page (never shrunk).
        [$pageW, $pageH] = [210.0, 297.0];
        $fit = []; $over = [];
        foreach ($items as $it) {
            if ($it['w'] <= $pageW + 0.5 && $it['h'] <= $pageH + 0.5) { $fit[] = $it; } else { $over[] = $it; }
        }
        if ($fit) {
            // Shelf-pack twice - shortest-first and tallest-first - and keep whichever needs fewer pages. On a
            // tie, shortest-first wins (fills small labels together, lets a big one take its own page).
            $asc  = $fit; usort($asc,  static function ($a, $b) { return $a['h'] <=> $b['h']; });
            $desc = $fit; usort($desc, static function ($a, $b) { return $b['h'] <=> $a['h']; });
            $la = self::layout($asc, $pageW, $pageH);
            $ld = self::layout($desc, $pageW, $pageH);
            $best = ($ld['pages'] < $la['pages']) ? $ld : $la;
            for ($pg = 0; $pg < $best['pages']; $pg++) {
                $pdf->AddPage('P', 'A4');
                foreach ($best['placements'] as $pl) {
                    if ($pl['page'] === $pg) { $pdf->useTemplate($pl['tpl'], $pl['x'], $pl['y'], $pl['w'], $pl['h']); }
                }
            }
        }
        foreach ($over as $it) { self::add_native_page($pdf, $it); } // oversized -> own native page, never scaled
        return $pdf->PageNo() > 0 ? $pdf->Output('S') : '';
    }

    /** Add a page sized exactly to the label's native dimensions and place the label on it 1:1 (no scaling). */
    private static function add_native_page(\setasign\Fpdi\Fpdi $pdf, array $it): void {
        $w = $it['w']; $h = $it['h'];
        // FPDF interprets the size in portrait terms and swaps for 'L', so hand it [short,long] with the right
        // orientation to get a page of exactly w x h.
        if ($w <= $h) { $pdf->AddPage('P', [$w, $h]); } else { $pdf->AddPage('L', [$h, $w]); }
        $pdf->useTemplate($it['tpl'], 0, 0, $w, $h);
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
