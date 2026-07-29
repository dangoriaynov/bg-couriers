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
class BGCouriers_Label_Packer {

    public static function available(): bool {
        if (!defined('BGCOURIERS_PATH')) { return false; }
        require_once BGCOURIERS_PATH . 'includes/lib/pdf/load.php';
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
     * Concatenate several label PDFs preserving EVERY source page at its native size and orientation - no
     * re-packing, no scaling. Used for batch printing where each courier already returns a correctly laid-out
     * sheet (e.g. Speedy's native A4 with landscape labels), so we just stitch the sheets together in order.
     *
     * @param string[] $pdfs
     * @return string
     */
    public static function concat(array $pdfs): string {
        if (!self::available()) { return ''; }
        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $any = false;
        foreach ($pdfs as $bytes) {
            if (!is_string($bytes) || $bytes === '') { continue; }
            try {
                $reader = \setasign\Fpdi\PdfParser\StreamReader::createByString($bytes);
                $count  = $pdf->setSourceFile($reader);
            } catch (\Throwable $e) { continue; }
            for ($p = 1; $p <= $count; $p++) {
                try {
                    $tpl = $pdf->importPage($p);
                    $s   = $pdf->getTemplateSize($tpl);
                    self::add_native_page($pdf, ['tpl' => $tpl, 'w' => (float) $s['width'], 'h' => (float) $s['height']]);
                    $any = true;
                } catch (\Throwable $e) { /* skip an unreadable page */ }
            }
        }
        return $any ? $pdf->Output('S') : '';
    }

    /**
     * Speedy's plain-A4 waybill form paints from 5.6 mm to 97.5 mm (measured off the live /print
     * output; the form is a fixed-width table, identical on every sample). Shifting the second
     * template by 297 - 97.5 - 5.6 puts its form at 199.5..291.4 mm - flush right with the SAME
     * 5.6 mm margin the left form has. Also keeps the two forms far apart (97.5 vs 199.5).
     */
    const TWO_UP_MIRROR_SHIFT = 193.9;
    const HALF_COL_X0     = 5.6;   // the form's own left margin - small fillers mirror it on the right
    const HALF_COL_TOP    = 8.0;   // the form's own top margin
    const HALF_COL_BOTTOM = 202.0; // 210 - 8 bottom margin
    const HALF_COL_MAX_W  = 143.0; // 148.5 column minus the 5.6 outer margin

    /**
     * Compose the A4 batch: half-sheet waybill forms (Speedy's plain-A4 print - one form in the
     * LEFT half of a landscape sheet, right half empty) are paired two per sheet, the second one
     * MIRRORED against the right edge so both have equal outer margins (the merchant cuts down the
     * middle, like a re-fed half-printed sheet). A leftover half column is then FILLED with
     * sticker-size labels from other couriers (right-aligned, stacked top-down) instead of wasting
     * a sheet on them. Nothing is ever scaled; pages that are not landscape-A4 half-sheets keep
     * their own native page.
     *
     * @param string[] $half_pdfs  PDFs whose landscape-A4 pages carry a form in the left half.
     * @param string[] $small_pdfs Individual small-label PDFs (single sticker-size page each).
     * @return array{pdf:string, leftover:string[]} pdf='' if nothing was rendered; leftover = the
     *                small PDFs that found no half column (the caller packs them onto A4 pages).
     */
    public static function compose_a4(array $half_pdfs, array $small_pdfs = []): array {
        if (!self::available()) { return ['pdf' => '', 'leftover' => array_values($small_pdfs)]; }
        $pdf = new \setasign\Fpdi\Fpdi('L', 'mm', 'A4');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $import = static function (string $bytes) use ($pdf): array {
            $out = [];
            try {
                $reader = \setasign\Fpdi\PdfParser\StreamReader::createByString($bytes);
                $count  = $pdf->setSourceFile($reader);
            } catch (\Throwable $e) { return []; }
            for ($p = 1; $p <= $count; $p++) {
                try {
                    $tpl = $pdf->importPage($p);
                    $s   = $pdf->getTemplateSize($tpl);
                    $out[] = ['tpl' => $tpl, 'w' => (float) $s['width'], 'h' => (float) $s['height']];
                } catch (\Throwable $e) { /* skip an unreadable page */ }
            }
            return $out;
        };

        $halves = []; $others = [];
        foreach ($half_pdfs as $bytes) {
            if (!is_string($bytes) || $bytes === '') { continue; }
            foreach ($import($bytes) as $it) {
                if (self::is_half_sheet($it)) { $halves[] = $it; } else { $others[] = $it; }
            }
        }
        // Small fillers: only single-page sticker-size PDFs qualify for a half column; the rest is
        // returned to the caller untouched.
        $fillers = []; $leftover = [];
        foreach ($small_pdfs as $bytes) {
            if (!is_string($bytes) || $bytes === '') { continue; }
            $pages = $import($bytes);
            // A courier that hands over a full LANDSCAPE A4 form with its content in the left half - Econt
            // does exactly what Speedy does - belongs in the two-up pool. Routing it to the leftovers, as
            // before, spent a whole sheet on every such label with the right half blank.
            if (count($pages) === 1 && self::is_half_sheet($pages[0])) {
                $halves[] = $pages[0];
            } elseif (count($pages) === 1 && $pages[0]['w'] <= self::HALF_COL_MAX_W
                && $pages[0]['h'] <= self::HALF_COL_BOTTOM - self::HALF_COL_TOP) {
                $fillers[] = ['bytes' => $bytes] + $pages[0];
            } else {
                $leftover[] = $bytes;
            }
        }

        // Fill one half column (x = left edge of the column) with as many fillers as stack, each
        // mirrored to the right sheet edge with the form's own outer margins.
        $fill_column = static function () use ($pdf, &$fillers): void {
            $y = self::HALF_COL_TOP;
            while ($fillers && $y + $fillers[0]['h'] <= self::HALF_COL_BOTTOM) {
                $f = array_shift($fillers);
                $pdf->useTemplate($f['tpl'], 297.0 - self::HALF_COL_X0 - $f['w'], $y, $f['w'], $f['h']);
                $y += $f['h'] + 6.0;
            }
        };

        for ($i = 0; $i < count($halves); $i += 2) {
            $pdf->AddPage('L', 'A4');
            $pdf->useTemplate($halves[$i]['tpl'], 0, 0, $halves[$i]['w'], $halves[$i]['h']);
            if (isset($halves[$i + 1])) {
                $pdf->useTemplate($halves[$i + 1]['tpl'], self::TWO_UP_MIRROR_SHIFT, 0, $halves[$i + 1]['w'], $halves[$i + 1]['h']);
            } else {
                $fill_column(); // odd form: give its empty right half to the small labels
            }
        }
        foreach ($others as $it) { self::add_native_page($pdf, $it); }

        // Fillers that found no half column go back to the caller as raw PDFs.
        foreach ($fillers as $f) { $leftover[] = $f['bytes']; }
        return ['pdf' => $pdf->PageNo() > 0 ? $pdf->Output('S') : '', 'leftover' => $leftover];
    }

    /**
     * Is this page a courier form that occupies a landscape A4 sheet with its content in the left half?
     * Speedy and Econt both hand those over, so two of them fit on one sheet side by side at native size.
     * Geometry only - no courier is named here, so a new courier with the same form pairs up for free.
     *
     * @param array{w:float,h:float} $page
     */
    private static function is_half_sheet(array $page): bool {
        return abs($page['w'] - 297.0) <= 5.0 && abs($page['h'] - 210.0) <= 5.0;
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
