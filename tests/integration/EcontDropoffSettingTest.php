<?php
/**
 * The Econt drop-off office takes an office from the synced list, keeps the old choice for anything else, and its code reaches the quote.
 *
 * @group econt
 */
final class EcontDropoffSettingTest extends WP_UnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        delete_option('bgcouriers_econt_dropoff_office');
        BGCouriers_Nomenclature::upsert_cities('econt', [
            ['city_id' => 41, 'name' => 'София', 'post_code' => '1000', 'country' => 'BG'],
        ], 'test-run');
        BGCouriers_Nomenclature::upsert_offices('econt', [
            ['office_id' => 163, 'code' => '1009', 'city_id' => 41, 'type' => 'office',  'name' => 'София Студентски град', 'address' => '', 'lat' => 0, 'lng' => 0, 'country' => 'BG'],
            ['office_id' => 900, 'code' => '1999', 'city_id' => 41, 'type' => 'automat', 'name' => 'София Еконтомат', 'address' => '', 'lat' => 0, 'lng' => 0, 'country' => 'BG'],
        ], 'test-run');
    }

    /** WooCommerce keeps settings errors in a static for the request: a refusal here must not reach the next test. */
    protected function tearDown(): void {
        $p = new ReflectionProperty('WC_Admin_Settings', 'errors');
        $p->setAccessible(true);
        $p->setValue(null, []);
        parent::tearDown();
    }

    private function save(string $raw): string {
        return (string) (new BGCouriers_Settings_Admin())->sanitize_econt_dropoff($raw, ['id' => 'bgcouriers_econt_dropoff_office'], $raw);
    }

    private function errors(): string {
        ob_start(); WC_Admin_Settings::show_messages(); return (string) ob_get_clean();
    }

    public function test_an_office_from_the_list_is_saved_and_resolves_to_its_code(): void {
        $this->assertSame('163', $this->save('163'));
        update_option('bgcouriers_econt_dropoff_office', '163');
        $this->assertSame('1009', BGCouriers_Econt::dropoff_office_code());
    }

    public function test_a_blank_means_the_courier_collects(): void {
        update_option('bgcouriers_econt_dropoff_office', '163');
        $this->assertSame('', $this->save(''));
        delete_option('bgcouriers_econt_dropoff_office');
        $this->assertSame('', BGCouriers_Econt::dropoff_office_code());
    }

    /** @dataProvider refused */
    public function test_anything_but_a_listed_office_is_refused_and_the_old_office_stays(string $raw): void {
        update_option('bgcouriers_econt_dropoff_office', '163');
        $this->assertSame('163', $this->save($raw));
        $this->assertStringContainsString('not an Econt office', $this->errors());
    }

    public static function refused(): array {
        return ['an Econtomat' => ['900'], 'an id the list has never seen' => ['424242']];
    }
}
