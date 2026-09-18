<?php
/**
 * The Speedy drop-off office setting takes what it can stand behind, and keeps what it had otherwise.
 *
 * The sanitiser is the one place in the feature that can quietly do the wrong thing: a refused value
 * goes back to the stored one, and the merchant has to be TOLD, or the form shows their new choice over
 * an option that never changed. So every branch is driven here - an office from the list, an automat,
 * an id the list has never seen, a blank, and an office Speedy itself will not take parcels at.
 *
 * @group speedy
 */
final class SpeedyDropoffSettingTest extends WP_UnitTestCase {
    /** What Speedy answers about an office, for the pre_http_request seam; null = the office lookup is not intercepted. */
    private ?array $office_answer = null;
    private int $lookups = 0;

    protected function setUp(): void {
        parent::setUp();
        // Other tests reset the courier registry in their tear_down and leave it EMPTY, so in a full run
        // Couriers::get('speedy') answers null and the sanitiser - by design - takes the office unasked.
        // These two branches exist to prove Speedy IS asked, so Speedy has to be there to ask.
        if (!BGCouriers_Couriers::get('speedy')) {
            BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });
        }
        delete_option('bgcouriers_speedy_dropoff_office');
        BGCouriers_Nomenclature::upsert_cities('speedy', [
            ['city_id' => 68134, 'name' => 'СОФИЯ', 'post_code' => '1000', 'country' => 'BG'],
        ], 'test-run');
        BGCouriers_Nomenclature::upsert_offices('speedy', [
            ['office_id' => 307,  'code' => '307',  'city_id' => 68134, 'type' => 'office',  'name' => 'СОФИЯ - АНТОН П. ЧЕХОВ (УЛ.)', 'address' => '', 'lat' => 0, 'lng' => 0, 'country' => 'BG'],
            ['office_id' => 285,  'code' => '285',  'city_id' => 68134, 'type' => 'office',  'name' => 'СОФИЯ - АСЕН РАЗЦВЕТНИКОВ (УЛ.)', 'address' => '', 'lat' => 0, 'lng' => 0, 'country' => 'BG'],
            ['office_id' => 9480, 'code' => '9480', 'city_id' => 68134, 'type' => 'automat', 'name' => 'СОФИЯ - АВТОМАТ', 'address' => '', 'lat' => 0, 'lng' => 0, 'country' => 'BG'],
        ], 'test-run');
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if ($this->office_answer === null || strpos($url, '/location/office/') === false) { return $pre; }
            $this->lookups++;
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['office' => $this->office_answer])];
        }, 10, 3);
    }

    private function save(string $raw): string {
        return (string) (new BGCouriers_Settings_Admin())->sanitize_speedy_dropoff($raw, ['id' => 'bgcouriers_speedy_dropoff_office'], $raw);
    }

    /** What WooCommerce would show the merchant on a full-page save. */
    private function errors(): string {
        ob_start(); WC_Admin_Settings::show_messages(); return (string) ob_get_clean();
    }

    public function test_an_office_from_the_list_is_taken_without_asking_speedy_when_there_are_no_credentials(): void {
        delete_option('bgcouriers_speedy_username');
        $this->office_answer = ['id' => 307, 'dropOffAllowed' => false]; // would refuse - must not be asked
        $this->assertSame('307', $this->save('307'));
        $this->assertSame(0, $this->lookups);
        $this->assertStringNotContainsString('not changed', $this->errors());
    }

    public function test_a_blank_means_the_courier_collects(): void {
        update_option('bgcouriers_speedy_dropoff_office', '307');
        $this->assertSame('', $this->save(''));
    }

    public function test_an_automat_is_refused_and_the_old_office_stays(): void {
        update_option('bgcouriers_speedy_dropoff_office', '307');
        $this->assertSame('307', $this->save('9480'));
        $this->assertStringContainsString('not a Speedy office', $this->errors());
    }

    public function test_an_id_the_list_has_never_seen_is_refused_and_the_old_office_stays(): void {
        update_option('bgcouriers_speedy_dropoff_office', '307');
        $this->assertSame('307', $this->save('424242'));
        $this->assertStringContainsString('not a Speedy office', $this->errors());
    }

    public function test_an_office_speedy_will_not_take_parcels_at_is_refused_by_name(): void {
        bgcouriers_test_set_up_courier('speedy');
        update_option('bgcouriers_speedy_dropoff_office', '307');
        $this->office_answer = ['id' => 285, 'name' => 'СОФИЯ - АСЕН РАЗЦВЕТНИКОВ (УЛ.)', 'dropOffAllowed' => false];
        $this->assertSame('307', $this->save('285'));
        $this->assertSame(1, $this->lookups);
        $this->assertStringContainsString('АСЕН РАЗЦВЕТНИКОВ', $this->errors());
        $this->assertStringContainsString('not changed', $this->errors());
    }

    public function test_an_office_speedy_takes_parcels_at_is_saved_and_asked_about_once(): void {
        bgcouriers_test_set_up_courier('speedy');
        update_option('bgcouriers_speedy_dropoff_office', '307');
        $this->office_answer = ['id' => 285, 'name' => 'СОФИЯ - АСЕН РАЗЦВЕТНИКОВ (УЛ.)', 'dropOffAllowed' => true];
        $this->assertSame('285', $this->save('285'));
        $this->assertSame(1, $this->lookups);
        // Saved again unchanged: nothing to check, Speedy is not asked.
        update_option('bgcouriers_speedy_dropoff_office', '285');
        $this->assertSame('285', $this->save('285'));
        $this->assertSame(1, $this->lookups);
    }

    /** Speedy unreachable: the choice stands on the list alone rather than blocking the merchant. */
    public function test_speedy_not_answering_does_not_block_the_choice(): void {
        bgcouriers_test_set_up_courier('speedy');
        $this->office_answer = []; // an answer with no office record in it
        $this->assertSame('285', $this->save('285'));
    }
}
