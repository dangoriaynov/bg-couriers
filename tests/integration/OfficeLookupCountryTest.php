<?php
/**
 * An office lookup is a question about a country, and asking it without one gets a wrong answer that
 * looks like a right one.
 *
 * A courier's office endpoint is scoped to a country: Speedy's takes a countryId, and left out it is the
 * courier's home one. So a Romanian town, asked for without a country, came back with an empty list -
 * not an error, not a refusal, an empty list - and the checkout showed the office option greyed out with
 * "no offices in this town". Empty is a legitimate answer for a small place, so nothing downstream could
 * tell the two apart, and the answer was then cached for six hours under a key that did not record which
 * country had been asked about.
 *
 * @group core
 */
final class OfficeLookupCountryTest extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::register('probe', 'Probe', static function () { return new BGCouriers_Office_Country_Stub(); });
        BGCouriers_Office_Country_Stub::$asked = [];
    }
    /**
     * The stub is taken back OUT of the registry rather than the registry being reset. Resetting it drops
     * the five real couriers for every test that runs after this one, and they are registered exactly once,
     * from the plugin's private constructor - nothing here could put them back.
     */
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['probe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    /** The country the caller asked about is the country the courier is asked about. */
    public function test_the_country_reaches_the_courier(): void {
        BGCouriers_Ajax::city_offices('probe', 642279132, 'office', '', 5, 'RO');
        $this->assertSame([[642279132, 'RO']], BGCouriers_Office_Country_Stub::$asked);
    }

    /**
     * And the two countries do not share a cached answer. They did: the transient was keyed on courier +
     * city alone, so whichever country asked first answered for both - and the first answer, made without
     * a country, was the empty one.
     */
    public function test_one_country_does_not_answer_for_another(): void {
        BGCouriers_Ajax::city_offices('probe', 700, 'office', '', 5, 'BG');
        BGCouriers_Ajax::city_offices('probe', 700, 'office', '', 5, 'RO');
        $this->assertSame([[700, 'BG'], [700, 'RO']], BGCouriers_Office_Country_Stub::$asked);
    }

    /** The same country twice IS the cached case - that is what the transient is for. */
    public function test_the_same_country_is_asked_once(): void {
        BGCouriers_Ajax::city_offices('probe', 701, 'office', '', 5, 'BG');
        BGCouriers_Ajax::city_offices('probe', 701, 'office', '', 5, 'BG');
        $this->assertSame([[701, 'BG']], BGCouriers_Office_Country_Stub::$asked);
    }
}

/** Records what it was asked, and answers with one office so the answer is worth caching. */
final class BGCouriers_Office_Country_Stub extends BGCouriers_Abstract_Courier {
    /** @var array<int,array{0:int,1:string}> */
    public static $asked = [];
    public function id(): string { return 'probe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $city_id, string $country = ''): array {
        self::$asked[] = [$city_id, $country];
        return [['office_id' => 1, 'code' => '1', 'city_id' => $city_id, 'type' => 'office', 'name' => 'Office 1', 'address' => 'Str. 1', 'lat' => 0, 'lng' => 0]];
    }
    public function quote(array $shipment): BGCouriers_Quote { throw new \RuntimeException('n/a'); }
    public function create_label(\WC_Order $order): BGCouriers_Label { throw new \RuntimeException('n/a'); }
    public function get_label_pdf(string $waybill, string $format = ''): string { return ''; }
    public function track(string $waybill): BGCouriers_Tracking { throw new \RuntimeException('n/a'); }
    public function tracking_url(string $waybill): string { return ''; }
    public function cancel_label(string $waybill): bool { return false; }
    public function check_credentials(): bool { return true; }
}
