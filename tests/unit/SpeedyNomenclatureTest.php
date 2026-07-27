<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-speedy.php';

/**
 * @group speedy
 */
final class SpeedyNomenclatureTest extends TestCase {
    public function test_parse_sites_normalizes(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/find_site.json'), true);
        $rows = BGCouriers_Speedy::parse_sites($resp);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('city_id', $rows[0]);
        $this->assertArrayHasKey('post_code', $rows[0]);
        $this->assertSame('Dobrich', $rows[0]['name']);
    }
    public function test_parse_offices_normalizes(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/find_office.json'), true);
        $rows = BGCouriers_Speedy::parse_offices($resp);
        $this->assertNotEmpty($rows);
        $this->assertContains($rows[0]['type'], ['office', 'automat']);
        $this->assertArrayHasKey('city_id', $rows[0]);
    }
}
