<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-nomenclature.php';

/**
 * The combined map shows one PLACE across several couriers, and each courier numbers its own cities.
 * Matching them up is the whole job: get it wrong and a courier silently contributes no points, which
 * on a map reads as "this courier does not serve my town".
 *
 * Name AND post code together, because neither alone is enough: roughly a thousand cities per courier
 * share a post code with another, and names repeat across regions.
 *
 * @group core
 */
final class AllMapCityMatchTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** A tiny $wpdb that answers get_row from a fixed table of rows. */
    private function wpdb(array $rows): object {
        return new class($rows) {
            public $prefix = 'wp_';
            private $rows;
            public function __construct($rows) { $this->rows = $rows; }
            public function prepare($sql, ...$args) { return [$sql, $args]; }
            public function esc_like($t) { return $t; }
            public function get_row($q, $out = null) {
                [$sql, $args] = $q;
                foreach ($this->rows as $r) {
                    if ($r['courier'] !== $args[0]) { continue; }
                    // name+code, name only, or code only - whichever this query asked for
                    if (strpos($sql, 'name=%s AND post_code=%s') !== false) {
                        if ($r['name'] === $args[1] && $r['post_code'] === $args[2]) { return $r; }
                    } elseif (strpos($sql, 'name=%s') !== false) {
                        if ($r['name'] === $args[1]) { return $r; }
                    } elseif (strpos($sql, 'post_code=%s') !== false) {
                        if ($r['post_code'] === $args[1]) { return $r; }
                    }
                }
                return null;
            }
        };
    }

    public function test_the_same_place_resolves_to_each_couriers_own_id(): void {
        global $wpdb;
        $wpdb = $this->wpdb([
            ['courier' => 'speedy', 'city_id' => 68134, 'name' => 'София', 'post_code' => '1000'],
            ['courier' => 'econt',  'city_id' => 41,    'name' => 'София', 'post_code' => '1000'],
        ]);
        $this->assertSame(68134, (int) BGCouriers_Nomenclature::match_city('speedy', 'София', '1000')['city_id']);
        $this->assertSame(41, (int) BGCouriers_Nomenclature::match_city('econt', 'София', '1000')['city_id']);
    }

    /** The post code alone is ambiguous, so a differing code must not beat a matching name. */
    public function test_the_name_carries_when_the_post_code_differs(): void {
        global $wpdb;
        $wpdb = $this->wpdb([['courier' => 'pigeon', 'city_id' => 7, 'name' => 'Драгичево', 'post_code' => '2351']]);
        $row = BGCouriers_Nomenclature::match_city('pigeon', 'Драгичево', '9999');
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row['city_id']);
    }

    /** A courier that simply does not list the place gets no row - and no points on the map. */
    public function test_a_courier_that_does_not_know_the_place_matches_nothing(): void {
        global $wpdb;
        $wpdb = $this->wpdb([['courier' => 'speedy', 'city_id' => 1, 'name' => 'София', 'post_code' => '1000']]);
        $this->assertNull(BGCouriers_Nomenclature::match_city('sameday', 'София', '1000'));
    }
}
