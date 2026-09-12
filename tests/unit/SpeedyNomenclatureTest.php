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
    protected function setUp(): void {
        parent::setUp(); Brain\Monkey\setUp();
        Brain\Monkey\Functions\when('__')->returnArg(1);
        Brain\Monkey\Functions\when('esc_html')->returnArg(1);
    }
    protected function tearDown(): void { Brain\Monkey\tearDown(); parent::tearDown(); }

    public function test_parse_sites_normalizes(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/find_site.json'), true);
        $rows = BGCouriers_Speedy::parse_sites($resp);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('city_id', $rows[0]);
        $this->assertArrayHasKey('post_code', $rows[0]);
        $this->assertSame('Dobrich', $rows[0]['name']);
    }
    /**
     * Speedy refuses a login with HTTP 200 and an `error` node. Read as "no offices", that painted the
     * settings screen green over "0 cities, 0 offices, 0 rates" with credentials it had just refused
     * (measured 2026-09-12). A refusal is an exception, with Speedy's own words.
     */
    public function test_an_error_node_is_a_refusal_not_an_empty_list(): void {
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->expectExceptionMessage('Speedy: bg.error.interconnector.param.login.is_empty');
        BGCouriers_Speedy::parse_offices(['error' => ['id' => 'x', 'message' => 'bg.error.interconnector.param.login.is_empty']]);
    }

    /** The same for the towns, which come as CSV - a refusal is the one thing that comes back as JSON. */
    public function test_a_json_error_in_place_of_the_csv_is_a_refusal(): void {
        $this->expectException(BGCouriers_Api_Exception::class);
        $this->expectExceptionMessage('Speedy: Invalid username or password');
        BGCouriers_Speedy::parse_sites_csv('{"error":{"id":"x","message":"Invalid username or password"}}');
    }

    public function test_parse_offices_normalizes(): void {
        $resp = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/speedy/find_office.json'), true);
        $rows = BGCouriers_Speedy::parse_offices($resp);
        $this->assertNotEmpty($rows);
        $this->assertContains($rows[0]['type'], ['office', 'automat']);
        $this->assertArrayHasKey('city_id', $rows[0]);
    }
}
