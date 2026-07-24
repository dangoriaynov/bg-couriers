<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label-packer.php';

/**
 * two_up pairs Speedy's plain-A4 half-sheet waybill pages (label in the LEFT half of a landscape A4)
 * two per sheet - left + right - without scaling. Uses the bundled FPDF/FPDI, no WP needed.
 *
 * @group speedy
 */
final class LabelPackerTwoUpTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('BGC_PATH')) { define('BGC_PATH', dirname(__DIR__, 2) . '/'); }
        if (!BGC_Label_Packer::available()) { $this->markTestSkipped('bundled FPDI unavailable'); }
    }

    /** One landscape-A4 page with a rect in the left half, like Speedy's plain-A4 print. */
    private function half_sheet(string $mark): string {
        $f = new \FPDF('L', 'mm', 'A4');
        $f->AddPage();
        $f->SetFont('Helvetica', 'B', 12);
        $f->Rect(5, 5, 138, 200);
        $f->Text(10, 15, $mark);
        return $f->Output('S');
    }

    private function sizes(string $pdf): array {
        $r = new \setasign\Fpdi\Fpdi();
        $n = $r->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));
        $out = [];
        for ($p = 1; $p <= $n; $p++) {
            $s = $r->getTemplateSize($r->importPage($p));
            $out[] = [round($s['width']), round($s['height'])];
        }
        return $out;
    }

    public function test_two_half_sheets_become_one_landscape_a4(): void {
        $out = BGC_Label_Packer::two_up([$this->half_sheet('L1'), $this->half_sheet('L2')]);
        $this->assertNotSame('', $out);
        $this->assertSame([[297.0, 210.0]], $this->sizes($out));
    }

    public function test_odd_count_leaves_last_label_alone_on_its_sheet(): void {
        $out = BGC_Label_Packer::two_up([$this->half_sheet('1'), $this->half_sheet('2'), $this->half_sheet('3')]);
        $this->assertSame([[297.0, 210.0], [297.0, 210.0]], $this->sizes($out)); // 2+1 across two sheets
    }

    public function test_non_half_sheet_pages_keep_their_own_native_page(): void {
        $f = new \FPDF('P', 'mm', [100, 150]); // sticker-sized page must NOT be paired or scaled
        $f->AddPage();
        $sticker = $f->Output('S');
        $out = BGC_Label_Packer::two_up([$this->half_sheet('A'), $sticker, $this->half_sheet('B')]);
        // A waits, sticker flushes A to its own sheet then passes through, B gets its own sheet.
        $this->assertSame([[297.0, 210.0], [100.0, 150.0], [297.0, 210.0]], $this->sizes($out));
    }
}
