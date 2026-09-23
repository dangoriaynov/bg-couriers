<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-zones.php';

/**
 * A Bulgarian courier quotes two prices for one parcel - inside Sofia, and out of it - and the checkout
 * used to advertise a single reference for both, quoted against whichever village sorted first in the
 * courier's town list. The number therefore changed the moment a customer named their town, up or down
 * depending on the village. Zones split the cache in two, and everything downstream of that depends on
 * one question: is this town Sofia?
 *
 * Only the name test is unit-testable (the rest of the class reads the nomenclature), and it is the part
 * that must not be clever: the whole name, never a substring, or the villages below get the capital's
 * cheaper tariff.
 *
 * @group core
 */
final class PriceZoneTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Every spelling the real nomenclature holds, measured across all seven couriers on 2026-09-23. */
    public function test_the_capital_is_recognised_however_a_courier_spells_it(): void {
        $this->assertTrue(BGCouriers_Zones::is_sofia_name('СОФИЯ', 'SOFIA'), 'Speedy, Evropat');
        $this->assertTrue(BGCouriers_Zones::is_sofia_name('София', 'Sofia'), 'Econt, Pigeon');
        $this->assertTrue(BGCouriers_Zones::is_sofia_name('София', ''), 'Sameday - no Latin name at all');
        $this->assertTrue(BGCouriers_Zones::is_sofia_name('СОФИЯ', ''), 'Express One - upper case, no Latin name');
        // The Latin name alone is enough: a courier that fills in only that one still has a capital.
        $this->assertTrue(BGCouriers_Zones::is_sofia_name('', 'Sofia'));
        // And the prefix a courier may put in front of a place name.
        $this->assertTrue(BGCouriers_Zones::is_sofia_name('гр. София', ''));
        $this->assertTrue(BGCouriers_Zones::is_sofia_name('гр.СОФИЯ', ''));
    }

    /**
     * The towns that must NOT be priced as Sofia. Софийци and Софрониево are villages; "Sofia" inside a
     * longer name is somebody else's town. A substring test would hand all of them the capital's price,
     * which is the cheaper one - so the shop, not the customer, would pay the difference.
     */
    public function test_towns_that_merely_look_like_it_are_not_it(): void {
        $this->assertFalse(BGCouriers_Zones::is_sofia_name('Софийци', ''));
        $this->assertFalse(BGCouriers_Zones::is_sofia_name('Софрониево', ''));
        $this->assertFalse(BGCouriers_Zones::is_sofia_name('Нова София', ''));
        $this->assertFalse(BGCouriers_Zones::is_sofia_name('Sofia Nova', 'Sofia Nova'));
        $this->assertFalse(BGCouriers_Zones::is_sofia_name('Варна', 'Varna'));
        $this->assertFalse(BGCouriers_Zones::is_sofia_name('', ''));
    }

    /** A zone that came from a stored row or a filter is one of ours, or it is the default. */
    public function test_an_unknown_zone_reads_back_as_the_default(): void {
        $this->assertSame(BGCouriers_Zones::SOFIA, BGCouriers_Zones::sanitize('sofia'));
        $this->assertSame(BGCouriers_Zones::COUNTRY, BGCouriers_Zones::sanitize('country'));
        $this->assertSame(BGCouriers_Zones::DEFAULT_ZONE, BGCouriers_Zones::sanitize(''));
        $this->assertSame(BGCouriers_Zones::DEFAULT_ZONE, BGCouriers_Zones::sanitize('plovdiv'));
    }

    /**
     * Outside Sofia is the default, and that is a decision, not an accident: most orders are not Sofia
     * ones, so it is the figure most customers see confirmed rather than replaced - and where it IS
     * replaced, the price moves down.
     */
    public function test_the_default_zone_is_the_country_one(): void {
        $this->assertSame(BGCouriers_Zones::COUNTRY, BGCouriers_Zones::DEFAULT_ZONE);
    }
}
