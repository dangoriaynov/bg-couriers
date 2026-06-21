<?php
final class SyncTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGC_Schema::create(); }

    public function test_run_populates_and_reconciles(): void {
        $courier = new class implements BGC_Courier_Interface {
            public array $cities = [
                ['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia'],
                ['city_id'=>2,'name'=>'Varna','name_lat'=>'Varna','post_code'=>'9000','region'=>'Varna'],
            ];
            public function id(): string { return 'speedy'; }
            public function label(): string { return 'Speedy'; }
            public function capabilities(): array { return ['address','office','automat','live_quote']; }
            public function check_credentials(): bool { return true; }
            public function fetch_cities(): array { return $this->cities; }
            public function fetch_offices(int $c): array { return [['office_id'=>10,'city_id'=>$c,'type'=>'office','name'=>'O','address'=>'A']]; }
            public function quote(array $s): BGC_Quote { return new BGC_Quote(5.0, 1.0, 'BGN', 'live'); }
            public function create_label(\WC_Order $o): BGC_Label { return new BGC_Label(''); }
            public function get_label_pdf(string $w): string { return ''; }
            public function cancel_label(string $w): bool { return true; }
            public function track(string $w): BGC_Tracking { return new BGC_Tracking('','',[]); }
            public function tracking_url(string $w): string { return ''; }
        };
        $r1 = BGC_Sync::run($courier);
        $this->assertSame(2, $r1['cities']);
        $this->assertSame(3, $r1['rates']); // address/office/automat
        $this->assertEqualsWithDelta(6.0, BGC_Rates::get('speedy','office'), 0.001);

        // Second run: Varna gone -> pruned.
        $courier->cities = [['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia']];
        $r2 = BGC_Sync::run($courier);
        $this->assertSame(1, BGC_Nomenclature::count('speedy'));

        // Empty fetch -> NO prune (guard).
        $courier->cities = [];
        $r3 = BGC_Sync::run($courier);
        $this->assertSame(1, BGC_Nomenclature::count('speedy'));
        $this->assertSame(0, $r3['pruned']);
    }
}
