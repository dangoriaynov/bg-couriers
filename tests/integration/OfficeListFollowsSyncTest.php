<?php
/**
 * A sync retires every town's cached locker list.
 *
 * The checkout caches a town's office list for six hours (BGCouriers_Ajax::city_offices). Nothing
 * could clear those: transients cannot be deleted by prefix, and on a shop with an object cache they
 * are not in the database at all. Measured on dev on 2026-09-13: BOX NOW's Бургас kept answering with
 * the 930-locker list a previous build had cached for it, through two deploys and two syncs. The key
 * carries the courier's nomenclature generation now, which every sync renews.
 *
 * @group core
 */
final class OfficeListFollowsSyncTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        BGCouriers_Gen_Probe::$live = [['office_id' => 1, 'city_id' => 7, 'type' => 'office', 'name' => 'Old office', 'address' => '', 'lat' => 0, 'lng' => 0]];
        BGCouriers_Couriers::register('genprobe', 'Probe', static function () { return new BGCouriers_Gen_Probe(); });
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['genprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    private function names(): array {
        return array_column(BGCouriers_Ajax::city_offices('genprobe', 7, 'office', '', 100000, 'BG'), 'name');
    }

    public function test_a_sync_retires_the_cached_list_and_a_plain_hour_does_not(): void {
        $this->assertSame(['Old office'], $this->names(), 'the live list, cached now');
        BGCouriers_Gen_Probe::$live = [['office_id' => 2, 'city_id' => 7, 'type' => 'office', 'name' => 'New office', 'address' => '', 'lat' => 0, 'lng' => 0]];
        $this->assertSame(['Old office'], $this->names(), 'control: within the six hours the cache answers, as it is meant to');
        BGCouriers_Sync::run(BGCouriers_Couriers::get('genprobe'));
        $this->assertSame(['New office'], $this->names(), 'after a sync, the list is fetched afresh');
        $this->assertNotSame('', BGCouriers_Ajax::nomenclature_generation('genprobe'), 'the sync wrote a generation');
    }
}

final class BGCouriers_Gen_Probe extends BGCouriers_Abstract_Courier {
    public static array $live = [];
    public function id(): string { return 'genprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return [['city_id' => 7, 'name' => 'Town', 'post_code' => '7000', 'region' => '', 'name_lat' => 'Town']]; }
    public function fetch_offices(int $c, string $country = ''): array { return self::$live; }
    public function quote(array $s): BGCouriers_Quote { throw new BGCouriers_Api_Exception('no'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
