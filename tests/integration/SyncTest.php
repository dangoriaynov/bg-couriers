<?php
/**
 * @group core
 */
final class SyncTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); }

    public function test_run_populates_and_reconciles(): void {
        $courier = new class implements BGCouriers_Courier_Interface {
            public array $cities = [
                ['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia'],
                ['city_id'=>2,'name'=>'Varna','name_lat'=>'Varna','post_code'=>'9000','region'=>'Varna'],
            ];
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function available_methods(): array { return $this->capabilities(); }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return $this->cities; }
            public function fetch_offices(int $c): array {
                return [
                    ['office_id'=>10,'city_id'=>1,'code'=>'O1','type'=>'office','name'=>'O1','address'=>'A1'],
                    ['office_id'=>11,'city_id'=>2,'code'=>'O2','type'=>'office','name'=>'O2','address'=>'A2'],
                    ['office_id'=>12,'city_id'=>1,'code'=>'AP1','type'=>'automat','name'=>'AP1','address'=>'AA1'],
                ];
            }
            public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(5.0, 1.0, 'BGN', 'live'); }
            public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
            public function label_formats(): array { return []; }
            public function get_label_pdf(string $w, string $format = ''): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
        $r1 = BGCouriers_Sync::run($courier);
        $this->assertSame(2, $r1['cities']);
        $this->assertSame(3, $r1['offices']); // 2 offices + 1 automat, one bulk call
        $this->assertSame(3, $r1['rates']); // address + office + automat, each from the first city (Sofia) + its representative office
        $this->assertEqualsWithDelta(6.0, BGCouriers_Rates::get('speedy','office'), 0.001);
        $this->assertEqualsWithDelta(6.0, BGCouriers_Rates::get('speedy','automat'), 0.001);

        // Second run: Varna gone -> pruned.
        $courier->cities = [['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia']];
        $r2 = BGCouriers_Sync::run($courier);
        $this->assertSame(1, BGCouriers_Nomenclature::count('speedy'));
        $this->assertGreaterThan(0, $r2['pruned']);

        // Empty fetch -> NO prune (guard).
        $courier->cities = [];
        $r3 = BGCouriers_Sync::run($courier);
        $this->assertSame(1, BGCouriers_Nomenclature::count('speedy'));
        $this->assertSame(0, $r3['pruned']);
    }
}
