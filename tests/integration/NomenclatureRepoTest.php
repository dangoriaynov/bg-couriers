<?php
/**
 * @group core
 */
final class NomenclatureRepoTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); BGCouriers_Schema::create(); }

    public function test_upsert_search_and_prune(): void {
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia'],
            ['city_id'=>2,'name'=>'Dobrich','name_lat'=>'Dobrich','post_code'=>'9300','region'=>'Dobrich'],
        ], 'run1');
        $this->assertSame(2, BGCouriers_Nomenclature::count('speedy'));
        $this->assertSame('Sofia', BGCouriers_Nomenclature::city_by_postcode('speedy','1000')['name']);
        $this->assertCount(1, BGCouriers_Nomenclature::search_cities('speedy','Dob'));

        // Re-run with only city 1 present -> prune should remove city 2.
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia'],
        ], 'run2');
        $this->assertSame(1, BGCouriers_Nomenclature::prune('speedy','run2'));
        $this->assertSame(1, BGCouriers_Nomenclature::count('speedy'));
    }

    public function test_offices_persist_code(): void {
        BGCouriers_Nomenclature::upsert_offices('econt', [
            ['office_id'=>34024,'code'=>'8015','city_id'=>2,'type'=>'automat','name'=>'Еконтомат','address'=>'ул. Марица 2','lat'=>43.849,'lng'=>25.954],
            ['office_id'=>1053,'code'=>'7538','city_id'=>2,'type'=>'office','name'=>'Айдемир','address'=>'ул. Тест 1'],
        ], 'run1');
        $this->assertSame('8015', BGCouriers_Nomenclature::office_by_id('econt', 34024)['code']);
        // coordinates round-trip through storage (they feed the map picker)
        $this->assertEqualsWithDelta(43.849, (float) BGCouriers_Nomenclature::office_by_id('econt', 34024)['lat'], 0.001);
        $this->assertEqualsWithDelta(25.954, (float) BGCouriers_Nomenclature::office_by_id('econt', 34024)['lng'], 0.001);
        $codes = array_column(BGCouriers_Nomenclature::offices('econt', 2), 'code');
        $this->assertContains('8015', $codes);
        $this->assertContains('7538', $codes);
    }

    /**
     * Two countries in one table. The post code is the sharp edge: 1000 is Sofia in Bulgaria and a
     * Bucharest sector in Romania, so an unfiltered lookup answers with whichever row came first.
     */
    public function test_a_second_country_lives_beside_the_first(): void {
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id'=>68134,'name'=>'София','name_lat'=>'Sofia','post_code'=>'1000','region'=>'София'],
            ['city_id'=>6420001,'country'=>'RO','name'=>'Bucuresti','name_lat'=>'Bucuresti','post_code'=>'1000','region'=>'Bucuresti'],
        ], 'run1');
        BGCouriers_Nomenclature::upsert_offices('speedy', [
            ['office_id'=>2,'code'=>'2','city_id'=>68134,'type'=>'office','name'=>'Офис София','address'=>'ул. 1'],
            ['office_id'=>926,'country'=>'RO','code'=>'926','city_id'=>6420001,'type'=>'office','name'=>'BUCHAREST - MOGOSOAIA','address'=>'str. BUIACULUI 2'],
        ], 'run1');

        $this->assertSame('BG', BGCouriers_Nomenclature::city_by_id('speedy', 68134)['country'], 'no country given = Bulgarian');
        $this->assertSame('RO', BGCouriers_Nomenclature::city_by_id('speedy', 6420001)['country']);
        $this->assertSame('София',    BGCouriers_Nomenclature::city_by_postcode('speedy','1000','BG')['name']);
        $this->assertSame('Bucuresti', BGCouriers_Nomenclature::city_by_postcode('speedy','1000','RO')['name']);
        $this->assertCount(1, BGCouriers_Nomenclature::search_cities('speedy','Bucu'));
        $this->assertCount(0, BGCouriers_Nomenclature::search_cities('speedy','Bucu', 20, 'BG'));
        $this->assertSame(926, (int) BGCouriers_Nomenclature::first_office('speedy','office','RO')['office_id'],
            'a Romanian reference office, not the first Bulgarian one');
        $this->assertSame(2, (int) BGCouriers_Nomenclature::first_office('speedy','office','BG')['office_id']);

        // Romania times out on the next run: Bulgaria refreshes and prunes, Romania stays put.
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id'=>68134,'name'=>'София','name_lat'=>'Sofia','post_code'=>'1000','region'=>'София'],
        ], 'run2');
        BGCouriers_Nomenclature::prune('speedy', 'run2', true, false, ['BG']);
        $this->assertNotNull(BGCouriers_Nomenclature::city_by_id('speedy', 6420001), 'Romania was never refreshed');
        $this->assertSame(2, BGCouriers_Nomenclature::count('speedy'));
    }
}
