<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * /v1/cities/{id}/streets is paginated at 100 a page, like /v1/cities and /v1/offices, and it takes a
 * `name` filter. The street search used to read the first page unfiltered and match against that - so
 * for Sofia, whose first page is a hundred numbered streets out of 4657 on 47 pages (measured
 * 2026-09-13), no customer could find Витоша, Шипка or any street with a name. Now the API is asked by
 * name and the pages it answers with are read, up to a cap.
 *
 * @group pigeon
 */
final class PigeonStreetPagingTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Numbered streets that carry the term in brackets, the way Sofia's first pages look. */
    private static function rows(int $from, int $to, string $name = 'ВИТОША'): array {
        $out = [];
        for ($i = $from; $i <= $to; $i++) { $out[] = ['id' => $i, 'name' => $i . '-ТА (' . $name . ')', 'type' => 'улица', 'city_id' => 759]; }
        return $out;
    }

    public function test_the_term_is_sent_as_the_name_filter_and_every_page_is_read(): void {
        $c = new BGCouriers_Pigeon_Street_Paging_Spy([
            ['data' => self::rows(1, 100),   'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 215, 'last_page' => 3]],
            ['data' => self::rows(101, 200), 'meta' => ['current_page' => 2, 'per_page' => 100, 'total' => 215, 'last_page' => 3]],
            ['data' => array_merge(self::rows(201, 214), [['id' => 215, 'name' => 'Витоша', 'type' => 'улица', 'city_id' => 759]]),
             'meta' => ['current_page' => 3, 'per_page' => 100, 'total' => 215, 'last_page' => 3]],
        ]);
        $rows = $c->search_streets(759, 'ви');
        $this->assertSame(3, $c->calls, 'three pages, three requests');
        $this->assertSame('ви', $c->queries[0]['name'], 'the API does the matching');
        $this->assertSame([1, 2, 3], array_column($c->queries, 'page'));
        $this->assertCount(215, $rows);
        $this->assertSame('Витоша', end($rows)['name'], 'the last row of the last page is there');
    }

    public function test_one_page_is_one_request(): void {
        $c = new BGCouriers_Pigeon_Street_Paging_Spy([
            ['data' => self::rows(1, 45), 'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 45, 'last_page' => 1]],
        ]);
        $this->assertCount(45, $c->search_streets(759, 'Витоша'));
        $this->assertSame(1, $c->calls);
    }

    /** A two-letter term can match hundreds; the dropdown shows twenty. Three pages is the ceiling. */
    public function test_the_pages_stop_at_the_cap(): void {
        $pages = [];
        for ($p = 1; $p <= 6; $p++) { $pages[] = ['data' => self::rows(($p - 1) * 100 + 1, $p * 100), 'meta' => ['current_page' => $p, 'per_page' => 100, 'total' => 600, 'last_page' => 6]]; }
        $c = new BGCouriers_Pigeon_Street_Paging_Spy($pages);
        $this->assertCount(300, $c->search_streets(759, '-та'));
        $this->assertSame(3, $c->calls);
    }

    /** What the API hands back is still checked against the term: its filter is its word, not ours. */
    public function test_a_row_without_the_term_is_dropped(): void {
        $c = new BGCouriers_Pigeon_Street_Paging_Spy([
            ['data' => [['id' => 1, 'name' => 'Витоша', 'type' => 'улица'], ['id' => 2, 'name' => 'Шипка', 'type' => 'улица']],
             'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 2, 'last_page' => 1]],
        ]);
        $this->assertSame([1], array_column($c->search_streets(759, 'витоша'), 'id'));
    }

    public function test_no_term_asks_without_a_filter(): void {
        $c = new BGCouriers_Pigeon_Street_Paging_Spy([
            ['data' => self::rows(1, 3), 'meta' => ['current_page' => 1, 'per_page' => 100, 'total' => 3, 'last_page' => 1]],
        ]);
        $this->assertCount(3, $c->search_streets(759, ''));
        $this->assertArrayNotHasKey('name', $c->queries[0]);
    }
}

/** Serves canned /v1/cities/{id}/streets pages and records every query. */
final class BGCouriers_Pigeon_Street_Paging_Spy extends BGCouriers_Pigeon {
    private array $pages;
    public int $calls = 0;
    public array $queries = [];
    public function __construct(array $pages) { parent::__construct([]); $this->pages = $pages; }
    protected function get_json(string $path, array $query = []): array {
        $this->calls++;
        $this->queries[] = $query;
        if (strpos($path, '/v1/cities/759/streets') !== 0) { throw new RuntimeException('unexpected path ' . $path); }
        $i = max(1, (int) ($query['page'] ?? 1)) - 1;
        return $this->pages[$i] ?? end($this->pages);
    }
}
