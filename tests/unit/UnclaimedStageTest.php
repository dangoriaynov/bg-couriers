<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-tracking.php';
require_once dirname(__DIR__, 2) . '/includes/Cache/class-bgcouriers-tracking-poller.php';
require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-columns.php';
require_once dirname(__DIR__, 2) . '/includes/Support/class-bgcouriers-icons.php';

/**
 * A parcel nobody came for is not a parcel waiting to be collected, and until this stage existed the
 * orders list showed the two the same way: the amber "Ready for collection" it had shown since the day
 * the parcel arrived. Pigeon freezes such a shipment on "Непотърсена" FOR GOOD (the journey home travels
 * under a new number), Evropat has its own status 82 for it, and the other five keep repeating "ready
 * for collection" for as long as the box stands on the shelf - so the stage comes from the courier where
 * there is a code and from the clock where there is not.
 *
 * @group core
 */
final class UnclaimedStageTest extends TestCase {
    protected function setUp(): void {
        parent::setUp(); Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('_x')->returnArg(1);   // the short stage labels carry a context
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('sanitize_html_class')->returnArg(1);
        if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** Days setting + nothing else: derive_unclaimed() reads only these two options. */
    private function opts(array $o): void {
        Functions\when('get_option')->alias(static function ($n, $d = false) use ($o) { return $o[$n] ?? $d; });
    }

    /** The codes the two couriers that SAY it publish for it. */
    public function test_the_couriers_own_words_for_it_land_on_the_stage(): void {
        foreach (['shipment_untracked', 'shipment_locker_time_expired', 'shipment_storage_expired',
                  'evropat_82'] as $code) {
            $this->assertSame('unclaimed', BGCouriers_Tracking::phase_stage($code), $code);
        }
    }

    /** ...and the wordings, for the couriers that publish no code at all. */
    public function test_the_wording_is_read_where_there_is_no_code(): void {
        $this->assertSame('unclaimed', BGCouriers_Tracking::classify('Непотърсена пратка'));
        $this->assertSame('unclaimed', BGCouriers_Tracking::classify('Изтекъл срок на съхранение'));
        // ...but a parcel that has merely ARRIVED is still only waiting, which is the whole distinction.
        $this->assertSame('ready', BGCouriers_Tracking::classify('Известие за пратка в офис'));
        // And nothing terminal is swallowed by the new rule.
        $this->assertSame('delivered', BGCouriers_Tracking::classify('Взета от получателя'));
        $this->assertSame('returned', BGCouriers_Tracking::classify('Предаване обратно на подател'));
    }

    /** A parcel that has stood there longer than the merchant's patience. */
    public function test_the_clock_turns_a_long_wait_into_not_collected(): void {
        $this->opts(['bgcouriers_unclaimed_days' => '7']);
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_times'] = ['ready' => time() - 8 * 86400];
        $this->assertSame('unclaimed', BGCouriers_Tracking_Poller::derive_unclaimed($o, 'ready'));
    }

    /** A parcel that arrived yesterday is exactly what "Ready for collection" is for. */
    public function test_a_fresh_arrival_is_left_alone(): void {
        $this->opts(['bgcouriers_unclaimed_days' => '7']);
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_times'] = ['ready' => time() - 86400];
        $this->assertSame('ready', BGCouriers_Tracking_Poller::derive_unclaimed($o, 'ready'));
    }

    /** 0 days switches the derived rule off; the couriers' own codes keep working. */
    public function test_zero_days_switches_the_clock_off(): void {
        $this->opts(['bgcouriers_unclaimed_days' => '0']);
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_times'] = ['ready' => time() - 60 * 86400];
        $this->assertSame('ready', BGCouriers_Tracking_Poller::derive_unclaimed($o, 'ready'));
        $this->assertSame(0, BGCouriers_Tracking_Poller::unclaimed_days());
    }

    /** The deadline can never outlive the 45 days after which a shipment stops being polled. */
    public function test_the_deadline_is_capped_at_the_polling_window(): void {
        $this->opts(['bgcouriers_unclaimed_days' => '900']);
        $this->assertSame(45, BGCouriers_Tracking_Poller::unclaimed_days());
    }

    /**
     * Every other stage is the courier's to report. Promoting 'transit' or 'delivered' on a clock would
     * flag parcels that are moving perfectly well, and a shipment polled every six hours spends days in
     * 'transit' without anything being wrong.
     */
    public function test_only_a_waiting_parcel_is_ever_promoted(): void {
        $this->opts(['bgcouriers_unclaimed_days' => '1']);
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_times'] = ['transit' => time() - 30 * 86400,
            'delivered' => time() - 30 * 86400, 'returning' => time() - 30 * 86400];
        $o->meta['_bgcouriers_track_updated'] = time() - 30 * 86400;
        foreach (['registered', 'transit', 'delivered', 'returning', 'returned', 'cancelled'] as $stage) {
            $this->assertSame($stage, BGCouriers_Tracking_Poller::derive_unclaimed($o, $stage), $stage);
        }
    }

    /**
     * Orders that were already waiting when this was released have no stamp for 'ready' - the stamps are
     * only written on a change of stage from now on. The last time the courier's answer CHANGED is the
     * same moment for a parcel that has not moved since it arrived.
     */
    public function test_an_order_from_before_the_stamps_falls_back_on_the_last_change(): void {
        $this->opts(['bgcouriers_unclaimed_days' => '7']);
        $o = new WC_Order();
        $o->meta['_bgcouriers_track_updated'] = time() - 10 * 86400;
        $this->assertSame('unclaimed', BGCouriers_Tracking_Poller::derive_unclaimed($o, 'ready'));

        // And with nothing at all to go on, the courier's verdict stands - never a guessed one.
        $this->assertSame('ready', BGCouriers_Tracking_Poller::derive_unclaimed(new WC_Order(), 'ready'));
    }

    /** The whole point of the suggestion: it must not look like the stage it was hiding inside. */
    public function test_it_is_red_and_does_not_look_like_waiting(): void {
        $c = BGCouriers_Order_Columns::STAGE_COLORS;
        $this->assertArrayHasKey('unclaimed', $c);
        $this->assertNotSame($c['ready'], $c['unclaimed'], 'waiting and not collected must differ');
        $this->assertNotSame($c['delivered'], $c['unclaimed']);
        $this->assertStringContainsString('.bgc-stage-unclaimed{color:' . $c['unclaimed'],
            BGCouriers_Order_Columns::stage_color_css());
        // A glyph of its own, too: colour alone is not a signal for everyone looking at the list.
        $this->assertNotSame('', BGCouriers_Icons::stage('unclaimed'));
        $this->assertNotSame(BGCouriers_Icons::stage('ready'), BGCouriers_Icons::stage('unclaimed'));
    }

    /** It reads as an outcome, and it has a place on the order's timeline. */
    public function test_it_has_a_label_and_a_place_in_the_journey(): void {
        $this->assertSame('Not collected', BGCouriers_Tracking::stage_label('unclaimed'));
        $this->assertNotSame(BGCouriers_Tracking::stage_label('ready'), BGCouriers_Tracking::stage_label('unclaimed'));
        $order = BGCouriers_Tracking::STAGE_ORDER;
        $this->assertContains('unclaimed', $order);
        $this->assertGreaterThan(array_search('ready', $order, true), array_search('unclaimed', $order, true),
            'a parcel waits before nobody comes for it');
    }
}
