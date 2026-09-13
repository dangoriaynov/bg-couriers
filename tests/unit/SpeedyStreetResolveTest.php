<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Which street of that name, settled at label time from Speedy's own list.
 *
 * An order that names a street without saying which one (placed before the checkout recorded the id,
 * typed, or taken off the map) gets one lookup: one street of that name gives its id, none leaves the
 * name alone, two with no type to choose by is refused with both spelled out - by the plugin, at the
 * order screen, rather than by Speedy with a sentence about nomenclature.
 *
 * @group speedy
 */
final class SpeedyStreetResolveTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** A Speedy that answers the street lookup from a canned list and counts how often it was asked. */
    private function speedy(array $rows): BGCouriers_Speedy {
        return new class($rows) extends BGCouriers_Speedy {
            public int $asked = 0;
            public array $last = [];
            private array $rows;
            public function __construct(array $rows) { parent::__construct([]); $this->rows = $rows; }
            public function search_streets(int $site_id, string $term, string $country = ''): array {
                $this->asked++; $this->last = [$site_id, $term, $country];
                return $this->rows;
            }
        };
    }
    private const VITOSHA = [
        ['id' => 26,   'name' => 'ВИТОША', 'type' => 'бул.', 'label' => 'бул. ВИТОША'],
        ['id' => 1314, 'name' => 'ВИТОША', 'type' => 'ул.',  'label' => 'ул. ВИТОША'],
        ['id' => 9001, 'name' => 'ВИТОШКА', 'type' => 'ул.', 'label' => 'ул. ВИТОШКА'], // a prefix hit the lookup returns, not this street
    ];

    public function test_one_street_of_that_name_gives_its_id(): void {
        $s = $this->speedy([['id' => 2443, 'name' => 'ОБОРИЩЕ', 'type' => 'ул.', 'label' => 'ул. ОБОРИЩЕ']]);
        $f = $s->resolve_street(68134, ['street' => 'Оборище', 'street_no' => '5']);
        $this->assertSame(2443, $f['street_id']);
        $this->assertSame([68134, 'Оборище', ''], $s->last, 'asked for that town and that name');
    }

    public function test_the_label_spelling_matches_too(): void {
        $s = $this->speedy(self::VITOSHA);
        $f = $s->resolve_street(68134, ['street' => 'ул. Витоша']);
        $this->assertSame(1314, $f['street_id'], '"ул. Витоша" written out names the one street');
    }

    public function test_two_of_that_name_and_a_type_picks_by_type(): void {
        $s = $this->speedy(self::VITOSHA);
        $f = $s->resolve_street(68134, ['street' => 'ВИТОША', 'street_type' => 'бул.']);
        $this->assertSame(26, $f['street_id']);
    }

    public function test_two_of_that_name_and_no_type_is_refused_with_both_named(): void {
        $s = $this->speedy(self::VITOSHA);
        try {
            $s->resolve_street(68134, ['street' => 'ВИТОША']);
            $this->fail('expected a refusal');
        } catch (BGCouriers_Api_Exception $e) {
            $this->assertStringContainsString('"ВИТОША"', $e->getMessage());
            $this->assertStringContainsString('бул. ВИТОША, ул. ВИТОША', $e->getMessage());
            $this->assertStringNotContainsString('ВИТОШКА', $e->getMessage(), 'a prefix hit is not a street of that name');
        }
    }

    public function test_a_type_that_matches_neither_still_refuses(): void {
        $s = $this->speedy(self::VITOSHA);
        $this->expectException(BGCouriers_Api_Exception::class);
        $s->resolve_street(68134, ['street' => 'ВИТОША', 'street_type' => 'пл.']);
    }

    public function test_none_of_that_name_leaves_the_name_as_it_is(): void {
        $s = $this->speedy([['id' => 9001, 'name' => 'ВИТОШКА', 'type' => 'ул.', 'label' => 'ул. ВИТОШКА']]);
        $f = $s->resolve_street(68134, ['street' => 'Витоша', 'street_no' => '10']);
        $this->assertArrayNotHasKey('street_id', $f);
        $this->assertSame('Витоша', $f['street']);
    }

    public function test_an_order_that_carries_the_id_is_not_looked_up(): void {
        $s = $this->speedy(self::VITOSHA);
        $f = $s->resolve_street(68134, ['street' => 'ВИТОША', 'street_id' => 1314]);
        $this->assertSame(0, $s->asked);
        $this->assertSame(1314, $f['street_id']);
    }

    public function test_no_street_or_no_town_is_not_looked_up(): void {
        $s = $this->speedy(self::VITOSHA);
        $s->resolve_street(68134, ['complex' => 'Младост 1', 'block' => '12']);
        $s->resolve_street(0, ['street' => 'ВИТОША']);
        $this->assertSame(0, $s->asked);
    }

    /**
     * The address map hands over "бул. Княз Александър Дондуков"; Speedy's search answers that with
     * nothing and the bare name with the street (measured 2026-09-13). The search goes by the bare name
     * and the type in the text picks among several of that name.
     */
    public function test_a_name_with_its_type_in_front_is_searched_bare_and_picked_by_the_type(): void {
        $s = $this->speedy([['id' => 64, 'name' => 'КНЯЗ АЛЕКСАНДЪР ДОНДУКОВ', 'type' => 'бул.', 'label' => 'бул. КНЯЗ АЛЕКСАНДЪР ДОНДУКОВ']]);
        $f = $s->resolve_street(68134, ['street' => 'бул. Княз Александър Дондуков', 'street_no' => '5']);
        $this->assertSame([68134, 'Княз Александър Дондуков', ''], $s->last, 'asked with the name alone');
        $this->assertSame(64, $f['street_id']);

        $s = $this->speedy(self::VITOSHA);
        $this->assertSame(1314, $s->resolve_street(68134, ['street' => 'улица Витоша'])['street_id'], '"улица" picks the ул. one');
        $this->assertSame(26, $s->resolve_street(68134, ['street' => 'булевард „Витоша“'])['street_id'], 'in the geocoder\'s spelling, quotes and all');
        $this->assertSame('Витоша', $s->last[1]);
    }

    /** The lookup failing is Speedy's problem to report, not a reason to refuse the label here. */
    public function test_a_failed_lookup_sends_the_name_as_before(): void {
        $s = new class extends BGCouriers_Speedy {
            public function __construct() { parent::__construct([]); }
            public function search_streets(int $site_id, string $term, string $country = ''): array {
                throw new BGCouriers_Api_Exception('down');
            }
        };
        $f = $s->resolve_street(68134, ['street' => 'ВИТОША', 'street_no' => '10']);
        $this->assertSame(['street' => 'ВИТОША', 'street_no' => '10'], $f);
    }
}
