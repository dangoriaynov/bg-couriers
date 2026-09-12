<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-pickup.php';

/**
 * The "Request a courier" screen offers a day: today while the courier will still come, otherwise
 * tomorrow. The cut-offs it judges that by are moments the courier names in its own zone
 * ("2026-09-14T17:00:00+0300"), so "still today" is a comparison of two real moments. WordPress's
 * current_time('timestamp') is not one: it is the epoch shifted by the site's UTC offset, and set against
 * a real cut-off it runs the offset fast - three hours in a Bulgarian summer - so from 14:00 the screen
 * was offering tomorrow while the courier still came today until 17:00.
 *
 * @group core
 */
final class PickupDefaultDayTest extends TestCase {
    private int $offset = 3; // Sofia in summer

    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
        if (!defined('DAY_IN_SECONDS'))  { define('DAY_IN_SECONDS', 86400); }
        Functions\when('__')->returnArg(1);
        Functions\when('add_action')->justReturn(null);
        Functions\when('get_option')->alias(function ($n, $d = false) { return $n === 'gmt_offset' ? $this->offset : $d; });
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private int $real = 0;

    /** A Sofia wall-clock moment on 14 September 2026, as the real epoch it is. */
    private function sofia(string $hm, int $offset = 3): int {
        $this->offset = $offset;
        $this->real   = strtotime('2026-09-14T' . $hm . ':00' . sprintf('%+03d00', $offset));
        return $this->real;
    }

    private const CUTOFFS = ['2026-09-14T17:00:00+0300', '2026-09-15T17:00:00+0300'];

    public function test_three_hours_before_the_cut_off_is_still_today(): void {
        $this->assertSame('2026-09-14', BGCouriers_Pickup::default_date(self::CUTOFFS, $this->sofia('14:00')));
        $this->assertSame('2026-09-14', BGCouriers_Pickup::default_date(self::CUTOFFS, $this->sofia('15:00')));
        $this->assertSame('2026-09-14', BGCouriers_Pickup::default_date(self::CUTOFFS, $this->sofia('16:59')));
    }

    public function test_after_the_cut_off_it_is_tomorrow(): void {
        $this->assertSame('2026-09-15', BGCouriers_Pickup::default_date(self::CUTOFFS, $this->sofia('17:01')));
    }

    public function test_the_same_in_winter(): void {
        // Two hours of offset instead of three: the window in which the old comparison went wrong was a
        // different width, not absent.
        $cut = ['2026-09-14T17:00:00+0200', '2026-09-15T17:00:00+0200'];
        $this->assertSame('2026-09-14', BGCouriers_Pickup::default_date($cut, $this->sofia('16:30', 2)));
        $this->assertSame('2026-09-15', BGCouriers_Pickup::default_date($cut, $this->sofia('17:30', 2)));
    }

    public function test_with_no_cut_offs_tomorrow_is_the_local_tomorrow(): void {
        // 01:00 in Sofia is still yesterday in UTC. Tomorrow is the day after the LOCAL date.
        $this->assertSame('2026-09-15', BGCouriers_Pickup::default_date([], $this->sofia('01:00')));
        $this->assertSame('2026-09-15', BGCouriers_Pickup::default_date([], $this->sofia('23:30')));
    }

    public function test_the_default_clock_is_the_real_one(): void {
        // Called the way the screen calls it - no moment given - the answer must be consistent with the
        // real clock, not with the shifted one: a cut-off one hour from now is still today.
        $this->sofia('12:00');
        $cut = [gmdate('Y-m-d\TH:i:s', time() + 3600) . '+0000'];
        $this->assertSame(gmdate('Y-m-d', time() + 3600 + $this->offset * 3600), BGCouriers_Pickup::default_date($cut));
    }

    public function test_what_the_screen_prints_is_what_is_still_ahead(): void {
        // At 18:00 the 17:00 cut-off is gone from the screen, and the next one is the first line.
        $this->assertSame(['2026-09-15T17:00:00+0300'], BGCouriers_Pickup::upcoming(self::CUTOFFS, $this->sofia('18:00')));
        $this->assertSame(self::CUTOFFS, BGCouriers_Pickup::upcoming(self::CUTOFFS, $this->sofia('12:00')));
        $this->assertSame([], BGCouriers_Pickup::upcoming(['not a moment', ''], $this->sofia('12:00')));
    }

    public function test_cut_offs_are_ordered_as_moments_not_as_text(): void {
        // "2026-09-14T15:00:00Z" is 18:00 in Sofia - later than "2026-09-14T17:00:00+0300", though it
        // sorts before it as a string. The soonest moment comes first whatever zone it was written in.
        $mixed = ['2026-09-14T15:00:00Z', '2026-09-14T17:00:00+0300'];
        $this->assertSame(['2026-09-14T17:00:00+0300', '2026-09-14T15:00:00Z'],
            BGCouriers_Pickup::upcoming($mixed, $this->sofia('12:00')));
    }
}
