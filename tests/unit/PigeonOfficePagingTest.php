<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';

/**
 * /v1/offices is paginated at 100 per page. Fetching one page kept only the first 100 of Bulgaria's 180
 * Pigeon offices, so the rest never reached the nomenclature cache and could not be picked anywhere.
 *
 * @group pigeon
 */
final class PigeonOfficePagingTest extends TestCase {
    public function test_every_page_is_accumulated(): void {
        $c = new BGCouriers_Pigeon_Paging_Spy([
            // type=office: 2 pages, 100 + 80, mirroring the live BG account
            'office' => [
                ['data' => self::rows(1, 100),   'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 180, 'last_page' => 2]],
                ['data' => self::rows(101, 180), 'meta' => ['current_page' => 2, 'per_page' => 100, 'total' => 180, 'last_page' => 2]],
            ],
            'locker' => [
                ['data' => self::rows(900, 900), 'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 1, 'last_page' => 1]],
            ],
        ]);
        $rows = $c->fetch_offices(0);

        $this->assertCount(181, $rows, '180 offices + 1 locker');
        $ids = array_column($rows, 'office_id');
        $this->assertContains(180, $ids, 'the last office on page 2 must survive');
        $this->assertContains(101, $ids, 'the first office on page 2 must survive');
        // Exactly 2 requests: page 2 reports current==last, so there is no wasted third round-trip.
        $this->assertSame(2, $c->calls['office']);
        $this->assertSame(1, $c->calls['locker'], 'locker: a single page is not re-requested');
    }

    /** A response with no meta must be treated as the only page, not looped forever. */
    public function test_missing_meta_stops_after_one_page(): void {
        $c = new BGCouriers_Pigeon_Paging_Spy([
            'office' => [['data' => self::rows(1, 3)]],
            'locker' => [['data' => []]],
        ]);
        $this->assertCount(3, $c->fetch_offices(0));
        $this->assertSame(1, $c->calls['office']);
    }

    /** The locker type is mapped to our 'automat', whichever page it arrives on. */
    public function test_locker_type_maps_to_automat(): void {
        $c = new BGCouriers_Pigeon_Paging_Spy([
            'office' => [['data' => []]],
            'locker' => [['data' => [['id' => 7, 'name' => 'APS', 'type' => 'locker', 'city' => ['id' => 759]]]]],
        ]);
        $rows = $c->fetch_offices(0);
        $this->assertSame('automat', $rows[0]['type']);
    }

    /** @return array[] office payload rows with ids $from..$to */
    private static function rows(int $from, int $to): array {
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            $out[] = ['id' => $i, 'name' => 'Office ' . $i, 'type' => 'office',
                      'city' => ['id' => 759], 'address' => 'Addr ' . $i, 'latitude' => 0, 'longitude' => 0];
        }
        return $out;
    }
}

/** Serves canned /v1/offices pages per type and counts the requests. */
final class BGCouriers_Pigeon_Paging_Spy extends BGCouriers_Pigeon {
    /** @var array<string,array[]> */
    private array $pages;
    /** @var array<string,int> */
    public array $calls = ['office' => 0, 'locker' => 0];

    public function __construct(array $pages) {
        parent::__construct([]);
        $this->pages = $pages;
    }
    protected function get_json(string $path, array $query = []): array {
        $type = (string) ($query['type'] ?? 'office');
        $this->calls[$type]++;
        $i = max(1, (int) ($query['page'] ?? 1)) - 1;
        // Past the last canned page, echo the final page back - a correct loop must never ask for it.
        return $this->pages[$type][$i] ?? end($this->pages[$type]);
    }
}
