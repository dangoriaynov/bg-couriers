<?php
/**
 * @group speedy
 */
final class AjaxSearchTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up(); BGCouriers_Schema::create();
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id'=>1,'name'=>'Sofia','name_lat'=>'Sofia','post_code'=>'1000','region'=>'Sofia'],
        ], 'r');
        new BGCouriers_Ajax();
    }
    public function test_search_cities_returns_match(): void {
        $_GET['courier'] = 'speedy'; $_GET['term'] = 'Sof';
        $out = BGCouriers_Ajax::search_cities_data();
        $this->assertSame('Sofia', $out[0]['name']);
        $this->assertSame('1000', $out[0]['post_code']);
    }
}
