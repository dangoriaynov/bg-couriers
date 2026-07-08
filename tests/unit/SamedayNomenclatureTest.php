<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgc-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgc-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgc-sameday.php';

/**
 * Sameday nomenclature parsers must produce the framework row shape so the shared checkout
 * city/office pickers work. Shapes are SDK-derived and pending sandbox confirmation.
 *
 * @group sameday
 */
final class SamedayNomenclatureTest extends TestCase {
    private function fx(string $f): array {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/sameday/' . $f), true);
    }

    public function test_parse_cities_shape_and_first_row(): void {
        $rows = BGC_Sameday::parse_cities($this->fx('cities.json'));
        $this->assertNotEmpty($rows);
        $this->assertSame(['city_id', 'name', 'post_code', 'region'], array_keys($rows[0]));
        $this->assertSame(161, $rows[0]['city_id']);
        $this->assertSame('София', $rows[0]['name']);
        $this->assertSame('1000', $rows[0]['post_code']);
        $this->assertSame('София', $rows[0]['region']); // county object flattened to its name
    }

    public function test_parse_offices_lockers_are_automat_ooh_are_office(): void {
        $rows = BGC_Sameday::parse_offices($this->fx('lockers.json'), $this->fx('ooh.json'));
        $this->assertCount(3, $rows); // 2 lockers + 1 ooh
        $this->assertSame(['office_id', 'code', 'city_id', 'type', 'name', 'address', 'lat', 'lng'], array_keys($rows[0]));
        // lockers first → automat
        $this->assertSame('automat', $rows[0]['type']);
        $this->assertSame(501, $rows[0]['office_id']);
        $this->assertSame(161, $rows[0]['city_id']);
        // ooh appended after → office
        $this->assertSame('office', $rows[2]['type']);
        $this->assertSame(701, $rows[2]['office_id']);
    }
}
