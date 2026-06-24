<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-speedy.php';

final class SpeedyCitiesCsvTest extends TestCase {
    public function test_parse_sites_csv_by_header(): void {
        // Column order intentionally differs from production to prove header-based parsing.
        $csv = "id,countryId,type,name,nameEn,region,regionEn,postCode\n"
             . "68134,100,GRAD,София,Sofia,София,Sofia,1000\n"
             . "41624,100,GRAD,Добрич,Dobrich,Добрич,Dobrich,9300\n";
        $rows = BGC_Speedy::parse_sites_csv($csv);
        $this->assertCount(2, $rows);
        $this->assertSame(68134, $rows[0]['city_id']);
        $this->assertSame('София', $rows[0]['name']);
        $this->assertSame('Sofia', $rows[0]['name_lat']);
        $this->assertSame('1000', $rows[0]['post_code']);
        $this->assertSame('София', $rows[0]['region']);
    }

    public function test_parse_sites_csv_skips_blank_and_headerless(): void {
        $this->assertSame([], BGC_Speedy::parse_sites_csv(''));
        $this->assertSame([], BGC_Speedy::parse_sites_csv("id,name,nameEn,postCode\n")); // header only
    }
}
