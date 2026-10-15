<?php
defined('ABSPATH') || exit;

/** Packs label PDFs onto sheets with FPDI, never scaling or cropping: 'A6' one per native-size page, 'A4' FFDH shelf packing. */
class BGCouriers_Label_Packer {

    public static function available(): bool {
        if (!defined('BGCOURIERS_PATH')) { return false; }
        require_once BGCOURIERS_PATH . 'includes/lib/pdf/load.php';
        return class_exists('BGCouriers\\Fpdi\\Fpdi');
    }

    /** @param string[] $pdfs Raw PDF byte strings; returns the combined PDF, '' if nothing could be imported. */
    public static function pack(array $pdfs, string $paper): string {
        if (!self::available()) { return ''; }
        $isA6 = strtoupper($paper) === 'A6';

        $pdf = new \BGCouriers\Fpdi\Fpdi('P', 'mm', 'A4');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);

        $items = [];
        foreach ($pdfs as $bytes) {
            if (!is_string($bytes) || $bytes === '') { continue; }
            try {
                $reader = \BGCouriers\Fpdi\PdfParser\StreamReader::createByString($bytes);
                $count  = $pdf->setSourceFile($reader);
            } catch (\Throwable $e) { continue; }
            for ($p = 1; $p <= $count; $p++) {
                try {
                    $tpl = $pdf->importPage($p);
                    $s   = $pdf->getTemplateSize($tpl);
                    $items[] = ['tpl' => $tpl, 'w' => (float) $s['width'], 'h' => (float) $s['height']];
                } catch (\Throwable $e) { /* skip an unreadable page but keep going */ }
            }
        }
        if (!$items) { return ''; }

        if ($isA6) {
            foreach ($items as $it) { self::add_native_page($pdf, $it); }
            return $pdf->PageNo() > 0 ? $pdf->Output('S') : '';
        }

        [$pageW, $pageH] = [210.0, 297.0];
        $fit = []; $over = [];
        foreach ($items as $it) {
            if ($it['w'] <= $pageW + 0.5 && $it['h'] <= $pageH + 0.5) { $fit[] = $it; } else { $over[] = $it; }
        }
        if ($fit) {
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
        foreach ($over as $it) { self::add_native_page($pdf, $it); }
        return $pdf->PageNo() > 0 ? $pdf->Output('S') : '';
    }

    private static function add_native_page(\BGCouriers\Fpdi\Fpdi $pdf, array $it): void {
        $w = $it['w']; $h = $it['h'];
        // FPDF reads the size in portrait terms and swaps for 'L', so hand it [short,long] with the orientation.
        if ($w <= $h) { $pdf->AddPage('P', [$w, $h]); } else { $pdf->AddPage('L', [$h, $w]); }
        $pdf->useTemplate($it['tpl'], 0, 0, $w, $h);
    }

    /** @param string[] $pdfs Concatenated keeping every page at native size and orientation. */
    public static function concat(array $pdfs): string {
        if (!self::available()) { return ''; }
        $pdf = new \BGCouriers\Fpdi\Fpdi('P', 'mm', 'A4');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $any = false;
        foreach ($pdfs as $bytes) {
            if (!is_string($bytes) || $bytes === '') { continue; }
            try {
                $reader = \BGCouriers\Fpdi\PdfParser\StreamReader::createByString($bytes);
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

    /** Speedy's A4 form paints 5.6..97.5 mm; the mirrored second form lands flush right with the same margin. */
    const TWO_UP_MIRROR_SHIFT = 193.9;
    const HALF_COL_X0     = 5.6;   // the form's own left margin - small fillers mirror it on the right
    const HALF_COL_TOP    = 8.0;   // the form's own top margin
    const HALF_COL_BOTTOM = 202.0; // 210 - 8 bottom margin
    const HALF_COL_MAX_W  = 143.0; // 148.5 column minus the 5.6 outer margin

    /**
     * Pair half-sheet forms two per landscape A4 (second mirrored); a leftover half column takes sticker labels.
     * @return array{pdf:string, leftover:string[]} leftover = small PDFs that found no half column
     */
    public static function compose_a4(array $half_pdfs, array $small_pdfs = []): array {
        if (!self::available()) { return ['pdf' => '', 'leftover' => array_values($small_pdfs)]; }
        $pdf = new \BGCouriers\Fpdi\Fpdi('L', 'mm', 'A4');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $import = static function (string $bytes) use ($pdf): array {
            $out = [];
            try {
                $reader = \BGCouriers\Fpdi\PdfParser\StreamReader::createByString($bytes);
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
        $fillers = []; $leftover = [];
        foreach ($small_pdfs as $bytes) {
            if (!is_string($bytes) || $bytes === '') { continue; }
            $pages = $import($bytes);
            if (count($pages) === 1 && self::is_half_sheet($pages[0])) {
                $halves[] = $pages[0];
            } elseif (count($pages) === 1 && $pages[0]['w'] <= self::HALF_COL_MAX_W
                && $pages[0]['h'] <= self::HALF_COL_BOTTOM - self::HALF_COL_TOP) {
                $fillers[] = ['bytes' => $bytes] + $pages[0];
            } else {
                $leftover[] = $bytes;
            }
        }

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
                $fill_column();
            }
        }
        foreach ($others as $it) { self::add_native_page($pdf, $it); }

        foreach ($fillers as $f) { $leftover[] = $f['bytes']; }
        return ['pdf' => $pdf->PageNo() > 0 ? $pdf->Output('S') : '', 'leftover' => $leftover];
    }

    /** @param array{w:float,h:float} $page Landscape A4 with a form in its left half? Geometry only, no courier named. */
    private static function is_half_sheet(array $page): bool {
        return abs($page['w'] - 297.0) <= 5.0 && abs($page['h'] - 210.0) <= 5.0;
    }

    /**
     * @param array<int,array{tpl:mixed,w:float,h:float}> $items pre-ordered; next-fit shelf packing onto pages
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
