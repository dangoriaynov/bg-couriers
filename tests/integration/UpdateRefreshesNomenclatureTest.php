<?php
/**
 * A plugin update asks for one nomenclature sync, soon.
 *
 * 0.4.8 gives BOX NOW towns, read off its lockers; an install updated from 0.4.7 holds 900 BOX NOW
 * lockers under no town at all, and until a sync runs the courier is offered with an empty town list.
 * The weekly run can be six days away. So the version-change branch of the bootstrap schedules a
 * single run of the weekly hook a minute out - unless one is already due within that minute.
 *
 * @group core
 */
final class UpdateRefreshesNomenclatureTest extends WP_UnitTestCase {
    public function set_up() { parent::set_up(); wp_clear_scheduled_hook(BGCouriers_Sync::HOOK); }

    public function test_an_update_schedules_one_sync_within_a_minute(): void {
        $this->assertFalse(wp_next_scheduled(BGCouriers_Sync::HOOK));
        BGCouriers_Sync::schedule_once();
        $at = wp_next_scheduled(BGCouriers_Sync::HOOK);
        $this->assertNotFalse($at);
        $this->assertLessThanOrEqual(time() + MINUTE_IN_SECONDS, $at, 'a minute out, not a week');
    }

    public function test_a_run_already_due_is_left_alone(): void {
        wp_schedule_single_event(time() + 30, BGCouriers_Sync::HOOK);
        BGCouriers_Sync::schedule_once();
        $this->assertSame(1, count(array_filter(_get_cron_array(), static function ($hooks) { return isset($hooks[BGCouriers_Sync::HOOK]); })), 'one appointment, not two');
    }

    /** The weekly one, days out, is not "already due": the update still gets its sync now. */
    public function test_a_weekly_run_days_away_does_not_count(): void {
        wp_schedule_event(time() + 3 * DAY_IN_SECONDS, 'weekly', BGCouriers_Sync::HOOK);
        BGCouriers_Sync::schedule_once();
        $this->assertLessThanOrEqual(time() + MINUTE_IN_SECONDS, wp_next_scheduled(BGCouriers_Sync::HOOK));
    }
}
