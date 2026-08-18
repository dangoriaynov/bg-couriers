<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-quote.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-label.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-api-exception.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/interface-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/abstract-bgcouriers-courier.php';
require_once dirname(__DIR__, 2) . '/includes/Couriers/class-bgcouriers-pigeon.php';

/**
 * Pigeon takes either end of the journey - the merchant drops parcels at one of its offices, or a courier
 * comes to the merchant's premises. This plugin only ever said 'office', so a shop on an
 * address-collection contract could not use Pigeon at all. Their own plugin offers both, and the shape
 * below is the one it sends.
 *
 * @group pigeon
 */
final class PigeonPickupEndTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function settings(array $map): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($map) { return $map[$n] ?? $d; });
    }

    private function shipment(): array {
        return ['method' => 'office', 'site_id' => 759, 'office_id' => 627, 'weight_kg' => 1.0, 'currency' => 'EUR'];
    }

    public function test_by_default_the_parcels_are_dropped_at_an_office(): void {
        $this->settings([]);
        $p = BGCouriers_Pigeon::default_pickup();
        $this->assertSame('office', $p['pickup_type']);
        $b = BGCouriers_Pigeon::build_calculate_body($this->shipment(), 627, BGCouriers_Pigeon::default_box(), $p);
        $this->assertSame('office', $b['pickup_type']);
        $this->assertSame(627, $b['pickup_office_id']);
        $this->assertArrayNotHasKey('pickup_address', $b);
    }

    public function test_a_courier_can_be_sent_to_the_merchants_address(): void {
        $this->settings([
            'bgcouriers_pigeon_pickup_from_address' => 'yes',
            'bgcouriers_pigeon_pickup_city_id'      => 759,
            'bgcouriers_pigeon_pickup_address'      => 'бул. Витоша 1, ет. 2',
        ]);
        $b = BGCouriers_Pigeon::build_calculate_body($this->shipment(), 627, BGCouriers_Pigeon::default_box(), BGCouriers_Pigeon::default_pickup());
        $this->assertSame('address', $b['pickup_type']);
        $this->assertSame(759, $b['pickup_address']['city_id']);
        $this->assertSame('бул. Витоша 1, ет. 2', $b['pickup_address']['additional_info']);
        $this->assertArrayNotHasKey('pickup_office_id', $b, 'a collection from an address has no pickup office');
    }

    /** With only a street line there is no town to send anyone to, so it stays the office drop it was. */
    public function test_an_address_without_a_town_falls_back_rather_than_half_instructing(): void {
        $this->settings([
            'bgcouriers_pigeon_pickup_from_address' => 'yes',
            'bgcouriers_pigeon_pickup_address'      => 'бул. Витоша 1',
        ]);
        $this->assertSame('office', BGCouriers_Pigeon::default_pickup()['pickup_type']);
    }

    /** additional_info is never empty in their own plugin - a dash stands in for "nothing to add". */
    public function test_a_town_with_no_street_line_still_sends_something(): void {
        $this->settings([
            'bgcouriers_pigeon_pickup_from_address' => 'yes',
            'bgcouriers_pigeon_pickup_city_id'      => 759,
        ]);
        $this->assertSame('-', BGCouriers_Pigeon::default_pickup()['pickup_address']['additional_info']);
    }

    public function test_a_known_street_id_is_carried_when_there_is_one(): void {
        $this->settings([
            'bgcouriers_pigeon_pickup_from_address' => 'yes',
            'bgcouriers_pigeon_pickup_city_id'      => 759,
            'bgcouriers_pigeon_pickup_street_id'    => 4242,
        ]);
        $this->assertSame(4242, BGCouriers_Pigeon::default_pickup()['pickup_address']['street_id']);
    }
}
