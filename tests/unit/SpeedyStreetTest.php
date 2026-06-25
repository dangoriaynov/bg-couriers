<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-speedy.php';

final class SpeedyStreetTest extends TestCase {
    public function test_parse_streets_builds_name_and_label_and_skips_blank(): void {
        $resp = ['streets' => [
            ['id' => 1310, 'siteId' => 68134, 'type' => 'ул.',  'name' => 'ВИТА'],
            ['id' => 1312, 'siteId' => 68134, 'type' => 'бул.', 'name' => 'ВИТОША'],
            ['id' => 0, 'name' => ''], // skipped (no name)
        ]];
        $rows = BGC_Speedy::parse_streets($resp);
        $this->assertCount(2, $rows);
        $this->assertSame('ВИТА', $rows[0]['name']);
        $this->assertSame('ул. ВИТА', $rows[0]['label']);
        $this->assertSame('бул. ВИТОША', $rows[1]['label']);
    }
}
