<?php
// tests/unit/EcontNomenclatureTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-econt.php';

/** @group econt */
final class EcontNomenclatureTest extends TestCase {
    private function fx(string $f): array { return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/econt/' . $f), true); }

    public function test_parse_cities(): void {
        $rows = BGC_Econt::parse_cities($this->fx('cities.json'));
        $this->assertNotEmpty($rows);
        $r = $rows[0];
        $this->assertSame(['city_id','name','name_lat','post_code','region'], array_keys($r));
        $this->assertIsInt($r['city_id']);
    }
    public function test_parse_offices_maps_isAPS_to_automat(): void {
        $rows = BGC_Econt::parse_offices($this->fx('offices.json'));
        $this->assertNotEmpty($rows);
        $types = array_column($rows, 'type');
        foreach ($types as $t) { $this->assertContains($t, ['office','automat']); }
        $this->assertContains('automat', $types); // fixture includes >=1 isAPS office
        $this->assertSame(['office_id','code','city_id','type','name','address'], array_keys($rows[0]));
        $this->assertNotSame('', $rows[0]['code']); // Econt label receiverOfficeCode uses the string code
    }
    public function test_parse_streets(): void {
        $rows = BGC_Econt::parse_streets($this->fx('streets-sofia.json'));
        $this->assertNotEmpty($rows);
        $this->assertSame(['id','name','type','label'], array_keys($rows[0]));
    }
}
