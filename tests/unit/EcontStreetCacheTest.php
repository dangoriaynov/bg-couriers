<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Econt's getStreets answers with a town's whole list and takes no term - Sofia's is 4047 rows, 411 ms
 * (measured 2026-09-13) - and it was fetched again on every keystroke in the street box, on every
 * address label and on every map pick. Kept for a day now, like Express One's.
 *
 * @group econt
 */
final class EcontStreetCacheTest extends TestCase {
    private array $store = [];
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        $store = &$this->store;
        Functions\when('get_transient')->alias(static function ($k) use (&$store) { return $store[$k] ?? false; });
        Functions\when('set_transient')->alias(static function ($k, $v, $ttl = 0) use (&$store) { $store[$k] = $v; return true; });
        Functions\when('get_option')->alias(static function ($k, $d = '') { return $d; });
        if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function econt(): BGCouriers_Econt {
        return new class([]) extends BGCouriers_Econt {
            public int $asked = 0;
            protected function post_json(string $url, array $body): array {
                $this->asked++;
                return ['streets' => [['id' => 196, 'name' => 'бул. Витоша'], ['id' => 197, 'name' => 'ул. Шипка'], ['id' => 0, 'name' => '']]];
            }
        };
    }

    public function test_a_town_is_asked_for_once_a_day_however_many_keystrokes(): void {
        $e = $this->econt();
        $this->assertSame([196], array_column($e->search_streets(41, 'Витоша'), 'id'));
        $this->assertSame([197], array_column($e->search_streets(41, 'шип'), 'id'));
        $this->assertCount(2, $e->search_streets(41, ''));
        $this->assertSame(1, $e->asked, 'three lookups, one request');
        $this->assertCount(1, $this->store);
        $this->assertStringStartsWith('bgcouriers_econt_streets_41_', array_keys($this->store)[0]);
    }

    /** "Sync now" writes a new nomenclature generation, and that retires every town's list at once. */
    public function test_a_sync_retires_the_list(): void {
        $gen = 'a';
        Functions\when('get_option')->alias(static function ($k, $d = '') use (&$gen) { return $k === 'bgcouriers_nomgen_econt' ? $gen : $d; });
        $e = $this->econt();
        $e->search_streets(41, 'Витоша');
        $e->search_streets(41, 'Витоша');
        $this->assertSame(1, $e->asked);
        $gen = 'b';
        $e->search_streets(41, 'Витоша');
        $this->assertSame(2, $e->asked, 'a new generation is a new list');
    }

    public function test_another_town_is_another_list(): void {
        $e = $this->econt();
        $e->search_streets(41, 'Витоша');
        $e->search_streets(42, 'Витоша');
        $this->assertSame(2, $e->asked);
    }

    /** An empty answer is not remembered - a courier that was down for a moment is asked again. */
    public function test_nothing_is_not_kept(): void {
        $e = new class([]) extends BGCouriers_Econt {
            public int $asked = 0;
            protected function post_json(string $url, array $body): array { $this->asked++; return ['streets' => []]; }
        };
        $e->search_streets(41, 'x'); $e->search_streets(41, 'x');
        $this->assertSame(2, $e->asked);
        $this->assertSame([], $this->store);
    }
}
