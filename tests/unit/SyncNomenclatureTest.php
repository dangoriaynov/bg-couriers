<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * A recording stand-in for $wpdb, so the REAL nomenclature class runs its real SQL and this test sees
 * exactly which tables a sync would have written to and deleted from. Stubbing the nomenclature class
 * itself would shadow it for every other test in the suite.
 */
if (!class_exists('BGCouriers_Fake_Wpdb')) {
    class BGCouriers_Fake_Wpdb {
        public $prefix = 'wp_';
        /** @var array<int,string> */
        public $queries = [];
        public function prepare($sql, ...$args) { return $sql . ' /* ' . implode('|', array_map('strval', $args)) . ' */'; }
        public function query($sql) { $this->queries[] = $sql; return 0; }
        public function get_results($sql, $mode = null) { return []; }
        public function get_row($sql, $mode = null) { return null; }
        public function get_var($sql) { return 0; }
        public function esc_like($s) { return $s; }
    }
}
if (!class_exists('BGCouriers_Logger')) {
    class BGCouriers_Logger { public static function debug($msg, $ctx = []): void {} }
}
if (!class_exists('BGCouriers_Rates')) {
    class BGCouriers_Rates { public static function set($a, $b, $c, $d): void {} }
}

require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-nomenclature.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-sync.php';

/** A courier whose two nomenclature endpoints can be made to succeed, come back empty, or throw. */
final class SyncFakeCourier implements BGCouriers_Courier_Interface {
    public $cities = [];
    public $offices = [];
    public $cities_throw = false;
    public $offices_throw = false;
    public $offices_called = false;

    public function id(): string { return 'fake'; }
    public function label(): string { return 'Fake'; }
    public function capabilities(): array { return []; }
    public function fetch_cities(): array {
        if ($this->cities_throw) { throw new BGCouriers_Api_Exception('city endpoint down'); }
        return $this->cities;
    }
    public function fetch_offices(int $city_id = 0): array {
        $this->offices_called = true;
        if ($this->offices_throw) { throw new BGCouriers_Api_Exception('office endpoint down'); }
        return $this->offices;
    }
    public function quote(array $shipment): BGCouriers_Quote { throw new BGCouriers_Api_Exception('no quote'); }
    public function create_label(\WC_Order $order): BGCouriers_Label { throw new BGCouriers_Api_Exception('n/a'); }
    public function cancel_label(string $waybill): bool { return false; }
    public function track(string $waybill): BGCouriers_Tracking { return new BGCouriers_Tracking($waybill, ''); }
    public function check_credentials(): bool { return true; }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
    public function tracking_url(string $waybill): string { return ''; }
}

/**
 * @group core
 */
final class SyncNomenclatureTest extends TestCase {

    /** @var BGCouriers_Fake_Wpdb */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('get_woocommerce_currency')->justReturn('EUR');
        Functions\when('delete_transient')->justReturn(true);
        $GLOBALS['wpdb'] = $this->db = new BGCouriers_Fake_Wpdb();
    }

    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** DELETEs issued against a table this run - i.e. what was pruned. */
    private function pruned(string $table): bool {
        foreach ($this->db->queries as $q) {
            if (strpos($q, 'DELETE FROM wp_bgcouriers_' . $table) !== false) { return true; }
        }
        return false;
    }

    private function inserts(string $table): int {
        $n = 0;
        foreach ($this->db->queries as $q) {
            if (strpos($q, 'INSERT INTO wp_bgcouriers_' . $table) !== false) { $n++; }
        }
        return $n;
    }

    /**
     * The BOX NOW case. A geo-based courier has no cities at all; the old early return meant its
     * offices were never even requested, so every sync answered "0 cities, 0 offices, 0 rates" while
     * the credentials were perfectly valid and 886 lockers were sitting there.
     */
    public function test_a_courier_without_cities_still_syncs_its_offices(): void {
        $c = new SyncFakeCourier();
        $c->cities  = [];
        $c->offices = [['office_id' => 1, 'type' => 'automat', 'name' => 'APM 1', 'address' => 'ul. 1']];

        $out = BGCouriers_Sync::run($c);

        $this->assertTrue($c->offices_called, 'no cities must not stop the office fetch');
        $this->assertSame(1, $out['offices']);
        $this->assertSame(0, $out['cities']);
        $this->assertSame(1, $this->inserts('offices'), 'the locker must actually be written');
        $this->assertFalse($this->pruned('cities'), 'it has no cities - pruning them is meaningless');
    }

    /** Nothing came back from either endpoint - that is a broken API, and pruning would empty the tables. */
    public function test_a_total_failure_prunes_nothing(): void {
        $c = new SyncFakeCourier();
        $c->cities_throw = $c->offices_throw = true;

        $out = BGCouriers_Sync::run($c);

        $this->assertFalse($this->pruned('cities'), 'never prune when the fetch produced nothing');
        $this->assertFalse($this->pruned('offices'));
        $this->assertSame(['cities' => 0, 'offices' => 0, 'pruned' => 0, 'rates' => 0], $out);
    }

    /**
     * The dangerous half-failure: cities answered, offices timed out. Pruning both tables would delete
     * every office the courier has, and the next checkout would offer no pickup points at all.
     */
    public function test_a_failed_office_fetch_does_not_prune_the_offices(): void {
        $c = new SyncFakeCourier();
        $c->cities        = [['city_id' => 1, 'name' => 'София']];
        $c->offices_throw = true;

        BGCouriers_Sync::run($c);

        $this->assertTrue($this->pruned('cities'), 'cities were refreshed, so stale cities may go');
        $this->assertFalse($this->pruned('offices'), 'offices were NOT refreshed - leave them alone');
    }

    /** Mirror image: the office list is fresh, the city endpoint died. Cities must survive. */
    public function test_a_failed_city_fetch_does_not_prune_the_cities(): void {
        $c = new SyncFakeCourier();
        $c->cities_throw = true;
        $c->offices      = [['office_id' => 7, 'type' => 'office', 'name' => 'Офис 7', 'address' => '']];

        BGCouriers_Sync::run($c);

        $this->assertFalse($this->pruned('cities'), 'cities were not refreshed - leave them alone');
        $this->assertTrue($this->pruned('offices'));
    }

    /** The ordinary courier: both endpoints answer, both tables refresh and prune. */
    public function test_a_normal_courier_syncs_and_prunes_both(): void {
        $c = new SyncFakeCourier();
        $c->cities  = [['city_id' => 1, 'name' => 'София'], ['city_id' => 2, 'name' => 'Пловдив']];
        $c->offices = [['office_id' => 7, 'type' => 'office', 'name' => 'Офис 7', 'address' => '']];

        $out = BGCouriers_Sync::run($c);

        $this->assertSame(2, $out['cities']);
        $this->assertSame(1, $out['offices']);
        $this->assertTrue($this->pruned('cities'));
        $this->assertTrue($this->pruned('offices'));
    }

    /**
     * A geo-based courier's office rows carry no city_id at all. The INSERT binds one regardless, so an
     * unguarded read would raise on PHP 8 and the locker would go in against a garbage city.
     */
    public function test_an_office_without_a_city_is_stored_against_city_zero(): void {
        $c = new SyncFakeCourier();
        // Shaped exactly as BGCouriers_Boxnow::parse_destinations() returns them: no city_id key.
        $c->offices = [['office_id' => 42, 'code' => '42', 'type' => 'automat', 'name' => 'APM',
                        'address' => 'ul. 2', 'lat' => 42.7, 'lng' => 23.3, 'post_code' => '1000']];

        BGCouriers_Sync::run($c);

        $this->assertSame(1, $this->inserts('offices'));
        $ins = array_values(array_filter($this->db->queries,
            static fn($q) => strpos($q, 'INSERT INTO wp_bgcouriers_offices') !== false));
        $this->assertStringContainsString('fake|42|42|0|automat', $ins[0], 'city_id bound as 0, not dropped');
    }
}
