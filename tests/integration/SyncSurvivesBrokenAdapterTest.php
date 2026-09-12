<?php
/**
 * The weekly sync runs every enabled courier in turn, and one courier whose adapter is broken - an
 * Error, not an Exception - used to end it for the couriers behind it. Every catch in the sync said
 * \Exception; an adapter that hits a TypeError on an answer it did not expect throws an Error, and that
 * walked out of the loop. The offices of one courier are not a reason for another's to go stale.
 *
 * @group core
 */
final class SyncSurvivesBrokenAdapterTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); }

    private function courier(bool $broken) {
        return new class($broken) extends BGCouriers_Abstract_Courier {
            public function __construct(private bool $broken) {}
            public function id(): string { return 'synprobe'; }
            public function label(): string { return 'Probe'; }
            public function capabilities(): array { return ['office', 'address']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array {
                if ($this->broken) { throw new \TypeError('array_column(): Argument #1 ($array) must be of type array, null given'); }
                return [['city_id' => 1, 'name' => 'Sofia', 'post_code' => '1000', 'region' => '', 'name_lat' => 'Sofia']];
            }
            public function fetch_offices(int $c, string $country = ''): array {
                return [['office_id' => 1, 'city_id' => 1, 'type' => 'office', 'name' => 'A', 'address' => '', 'lat' => 0, 'lng' => 0]];
            }
            public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('no'); }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $f = ''): string { return ''; }
            public function cancel_label(string $w): bool { return false; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    /** The cities fetch throws an Error; the run must still finish, and still sync what it could. */
    public function test_a_broken_city_fetch_does_not_end_the_run(): void {
        $r = BGCouriers_Sync::run($this->courier(true));

        $this->assertIsArray($r, 'the run finished and reported');
        $this->assertSame(0, $r['cities'], 'no cities, since that call threw');
        $this->assertSame(1, $r['offices'], 'the offices were still synced - that call was fine');
    }
}
