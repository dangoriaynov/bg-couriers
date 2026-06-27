<?php
/**
 * @group core
 */
final class NomenclatureRepoTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGC_Schema::create(); }

    public function test_upsert_search_and_prune(): void {
        BGC_Nomenclature::upsert_cities('speedy', [
            ['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia'],
            ['city_id'=>2,'name'=>'Dobrich','name_lat'=>'Dobrich','post_code'=>'9300','region'=>'Dobrich'],
        ], 'run1');
        $this->assertSame(2, BGC_Nomenclature::count('speedy'));
        $this->assertSame('Sofia', BGC_Nomenclature::city_by_postcode('speedy','1000')['name']);
        $this->assertCount(1, BGC_Nomenclature::search_cities('speedy','Dob'));

        // Re-run with only city 1 present -> prune should remove city 2.
        BGC_Nomenclature::upsert_cities('speedy', [
            ['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia'],
        ], 'run2');
        $this->assertSame(1, BGC_Nomenclature::prune('speedy','run2'));
        $this->assertSame(1, BGC_Nomenclature::count('speedy'));
    }
}
