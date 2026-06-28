<?php
// tests/unit/PigeonNomenclatureTest.php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-pigeon.php';

/** @group pigeon */
final class PigeonNomenclatureTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/pigeon/' . $f), true);
    }

    public function test_parse_cities_keys_and_first_row(): void {
        $rows = BGC_Pigeon::parse_cities($this->fx('cities.json'));
        $this->assertNotEmpty($rows);
        $r = $rows[0];
        $this->assertSame(['city_id', 'name', 'name_lat', 'post_code', 'region'], array_keys($r));
        $this->assertSame(2459, $r['city_id']);
        $this->assertSame('Ловеч', $r['region']);
    }

    public function test_parse_cities_name_and_post_code(): void {
        $rows = BGC_Pigeon::parse_cities($this->fx('cities.json'));
        $r = $rows[0];
        $this->assertSame('Абланица', $r['name']);
        $this->assertSame('5574', $r['post_code']);
        $this->assertSame('Ablanitsa', $r['name_lat']);
    }

    public function test_parse_offices_keys_and_type_mapping(): void {
        $rows = BGC_Pigeon::parse_offices($this->fx('offices.json'));
        $this->assertCount(2, $rows);
        $this->assertSame(['office_id', 'code', 'city_id', 'type', 'name', 'address'], array_keys($rows[0]));
        // office row
        $office = $rows[0];
        $this->assertSame(120, $office['office_id']);
        $this->assertSame('POP-001', $office['code']);
        $this->assertSame(202, $office['city_id']);
        $this->assertSame('office', $office['type']);
        $this->assertNotSame('', $office['code']);
        // locker row → automat
        $locker = $rows[1];
        $this->assertSame(501, $locker['office_id']);
        $this->assertSame('LCK-501', $locker['code']);
        $this->assertSame(68134, $locker['city_id']);
        $this->assertSame('automat', $locker['type']);
        $this->assertNotSame('', $locker['code']);
    }

    public function test_parse_streets_keys_and_label(): void {
        $rows = BGC_Pigeon::parse_streets($this->fx('streets.json'));
        $this->assertNotEmpty($rows);
        $this->assertSame(['id', 'name', 'type', 'label'], array_keys($rows[0]));
        // Find the boulevard row (type='булевард', name='ВИТОША')
        $bd = null;
        foreach ($rows as $row) {
            if ($row['name'] === 'ВИТОША') {
                $bd = $row;
                break;
            }
        }
        $this->assertNotNull($bd, 'Expected a ВИТОША street in fixture');
        $this->assertSame('булевард ВИТОША', $bd['label']);
    }

    public function test_pigeon_id_label_capabilities(): void {
        $pigeon = new BGC_Pigeon([]);
        $this->assertSame('pigeon', $pigeon->id());
        $this->assertSame('Pigeon Express', $pigeon->label());
        $this->assertSame(['address', 'office', 'automat', 'live_quote'], $pigeon->capabilities());
    }
}
