<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label-packer.php';

/**
 * compose_a4 pairs Speedy's plain-A4 half-sheet waybill pages (form in the LEFT half of a
 * landscape A4) two per sheet - the second mirrored to the right edge - and fills a leftover
 * half column with sticker-size labels from other couriers. Never scales anything.
 * Uses the bundled FPDF/FPDI, no WP needed.
 *
 * @group speedy
 */
final class LabelPackerTwoUpTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('BGCOURIERS_PATH')) { define('BGCOURIERS_PATH', dirname(__DIR__, 2) . '/'); }
        if (!BGCouriers_Label_Packer::available()) { $this->markTestSkipped('bundled FPDI unavailable'); }
    }

    /** One landscape-A4 page with the form in the left half (5.6..97.5 mm), like Speedy's plain-A4 print. */
    private function half_sheet(string $mark): string {
        $f = new \BGCouriers_FPDF('L', 'mm', 'A4');
        $f->AddPage();
        $f->SetFont('Helvetica', 'B', 12);
        $f->Rect(5.6, 8, 91.9, 192);
        $f->Text(10, 15, $mark);
        return $f->Output('S');
    }

    /** A sticker-size single-page label PDF, like Pigeon's 100x90. FPDF normalizes the size array
     *  to portrait, so pick the orientation that yields the exact requested w x h. */
    private function sticker(float $w = 100, float $h = 90): string {
        $f = new \BGCouriers_FPDF($w >= $h ? 'L' : 'P', 'mm', [min($w, $h), max($w, $h)]);
        $f->AddPage();
        $f->Rect(1, 1, $w - 2, $h - 2);
        return $f->Output('S');
    }

    private function sizes(string $pdf): array {
        $r = new \BGCouriers\Fpdi\Fpdi();
        $n = $r->setSourceFile(\BGCouriers\Fpdi\PdfParser\StreamReader::createByString($pdf));
        $out = [];
        for ($p = 1; $p <= $n; $p++) {
            $s = $r->getTemplateSize($r->importPage($p));
            $out[] = [round($s['width']), round($s['height'])];
        }
        return $out;
    }

    public function test_two_half_sheets_become_one_landscape_a4(): void {
        $res = BGCouriers_Label_Packer::compose_a4([$this->half_sheet('L1'), $this->half_sheet('L2')]);
        $this->assertSame([], $res['leftover']);
        $this->assertSame([[297.0, 210.0]], $this->sizes($res['pdf']));
    }

    public function test_odd_half_column_is_filled_with_stickers(): void {
        // 3 forms + 2 stickers: sheet 2's empty right half takes BOTH stickers (90+90+gap fits 210).
        $res = BGCouriers_Label_Packer::compose_a4(
            [$this->half_sheet('1'), $this->half_sheet('2'), $this->half_sheet('3')],
            [$this->sticker(), $this->sticker()]
        );
        $this->assertSame([], $res['leftover']);
        $this->assertSame([[297.0, 210.0], [297.0, 210.0]], $this->sizes($res['pdf']));
    }

    public function test_smalls_without_a_free_half_column_come_back_as_leftover(): void {
        // 2 forms pair up - no free column, so the sticker must go back to the caller for pack().
        $sticker = $this->sticker();
        $res = BGCouriers_Label_Packer::compose_a4([$this->half_sheet('A'), $this->half_sheet('B')], [$sticker]);
        $this->assertSame([[297.0, 210.0]], $this->sizes($res['pdf']));
        $this->assertSame([$sticker], $res['leftover']);
    }

    public function test_oversized_small_is_never_squeezed_into_a_column(): void {
        // 160mm wide does not fit the 148.5mm half column -> leftover, never scaled or clipped.
        $big = $this->sticker(160, 200);
        $res = BGCouriers_Label_Packer::compose_a4([$this->half_sheet('A')], [$big]);
        $this->assertSame([[297.0, 210.0]], $this->sizes($res['pdf']));
        $this->assertSame([$big], $res['leftover']);
    }

    public function test_non_half_sheet_pages_keep_their_own_native_page(): void {
        $res = BGCouriers_Label_Packer::compose_a4([$this->half_sheet('A'), $this->sticker(100, 150), $this->half_sheet('B')]);
        // A and B pair on one sheet; the odd page passes through at its own native size.
        $this->assertSame([[297.0, 210.0], [100.0, 150.0]], $this->sizes($res['pdf']));
    }

    /**
     * The whole point of the owner's complaint: Econt hands over the SAME landscape-A4 form as Speedy,
     * but it used to arrive through the "small labels" path and every one of them wasted a full sheet
     * with a blank right half. Half-sheets are now recognised by geometry alone, whoever sent them.
     */
    public function test_another_couriers_half_sheet_pairs_up_instead_of_wasting_a_sheet(): void {
        // Both arrive as "small" labels - i.e. stored per-label PDFs, not a native batch.
        $res = BGCouriers_Label_Packer::compose_a4([], [$this->half_sheet('ECONT-1'), $this->half_sheet('ECONT-2')]);
        $this->assertSame([], $res['leftover'], 'neither may fall through to the leftovers');
        $sizes = $this->sizes($res['pdf']);
        $this->assertCount(1, $sizes, 'two half-sheet forms must share ONE sheet');
        $this->assertEqualsWithDelta(297.0, $sizes[0][0], 1.0, 'landscape A4 sheet');
        $this->assertEqualsWithDelta(210.0, $sizes[0][1], 1.0);
    }

    /** Mixed couriers pair with each other, not only with their own kind. */
    public function test_a_speedy_half_and_another_couriers_half_share_one_sheet(): void {
        $res = BGCouriers_Label_Packer::compose_a4([$this->half_sheet('SPEEDY')], [$this->half_sheet('ECONT')]);
        $this->assertSame([], $res['leftover']);
        $this->assertCount(1, $this->sizes($res['pdf']), 'one Speedy + one Econt form = one sheet');
    }

    /** Three half-sheets: two paired, the third alone with its free column open to stickers. */
    public function test_an_odd_half_sheet_still_takes_sticker_fillers(): void {
        $res = BGCouriers_Label_Packer::compose_a4(
            [$this->half_sheet('A')],
            [$this->half_sheet('B'), $this->half_sheet('C'), $this->sticker(100, 90)]
        );
        $this->assertSame([], $res['leftover'], 'the sticker must fit the odd form\'s empty half');
        $this->assertCount(2, $this->sizes($res['pdf']), '3 forms = 2 sheets');
    }
}
