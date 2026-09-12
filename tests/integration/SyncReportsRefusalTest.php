<?php
/**
 * A sync that fetched nothing says so. The run caught every fetch failure into a debug log and
 * returned zeros, and the settings screen painted "0 cities, 0 offices, 0 rates" green - with
 * credentials the courier had just refused. A courier that answers with no towns (BOX NOW has none) is
 * not a failure; a courier that refuses is, and the difference is whether the fetch threw.
 *
 * @group core
 */
final class SyncReportsRefusalTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); }

    private function courier(?string $cities_fail, ?string $offices_fail, bool $has_cities = true, string $quote_fail = 'no') {
        return new class($cities_fail, $offices_fail, $has_cities, $quote_fail) extends BGCouriers_Abstract_Courier {
            public function __construct(private ?string $cf, private ?string $of, private bool $hc, private string $qf) {}
            public function id(): string { return 'refprobe'; }
            public function label(): string { return 'Probe'; }
            public function capabilities(): array { return ['office', 'address']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array {
                if ($this->cf !== null) { throw new BGCouriers_Api_Exception($this->cf); }
                return $this->hc ? [['city_id' => 1, 'name' => 'Sofia', 'post_code' => '1000', 'region' => '', 'name_lat' => 'Sofia']] : [];
            }
            public function fetch_offices(int $c, string $country = ''): array {
                if ($this->of !== null) { throw new BGCouriers_Api_Exception($this->of); }
                return [['office_id' => 1, 'city_id' => 1, 'type' => 'office', 'name' => 'A', 'address' => '', 'lat' => 0, 'lng' => 0]];
            }
            public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception($this->qf); }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $f = ''): string { return ''; }
            public function cancel_label(string $w): bool { return false; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
            public function tracking_url(string $w): string { return ''; }
        };
    }

    public function test_a_refused_login_is_reported_as_the_failure_it_is(): void {
        $r = BGCouriers_Sync::run($this->courier('Speedy: Invalid username or password', 'Speedy: Invalid username or password'));
        $this->assertSame(0, $r['cities']);
        $this->assertSame(0, $r['offices']);
        $this->assertSame('Speedy: Invalid username or password', $r['error'] ?? null, 'the courier own words, for the screen');
    }

    public function test_a_table_that_could_not_be_refreshed_is_named_beside_the_one_that_was(): void {
        $r = BGCouriers_Sync::run($this->courier('Speedy: timed out', null));
        $this->assertSame(0, $r['cities']);
        $this->assertSame(1, $r['offices'], 'the offices were synced');
        $this->assertArrayNotHasKey('error', $r, 'not a failed run - one table came back');
        $this->assertSame('Speedy: timed out', $r['warning'] ?? null);
    }

    /** BOX NOW has no towns at all; an empty answer that did not throw is not a warning. */
    public function test_a_courier_with_no_towns_is_not_warned_about(): void {
        $r = BGCouriers_Sync::run($this->courier(null, null, false));
        $this->assertSame(0, $r['cities']);
        $this->assertSame(1, $r['offices']);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertArrayNotHasKey('warning', $r);
    }

    /** Towns and offices synced, every quote refused: that is "0 rates", with the refusal beside it. */
    public function test_rates_a_courier_refused_are_named_beside_the_towns_it_gave(): void {
        $r = BGCouriers_Sync::run($this->courier(null, null, true, 'Econt: Невалидно потребителско име и/или парола.'));
        $this->assertSame(1, $r['cities']);
        $this->assertSame(1, $r['offices']);
        $this->assertSame(0, $r['rates']);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame('Econt: Невалидно потребителско име и/или парола.', $r['warning'] ?? null);
    }
}
