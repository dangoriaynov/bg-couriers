<?php
/**
 * Switching the plugin off takes its events off WP-Cron - all of them, whatever arguments they carry.
 *
 * Measured on 2026-09-13 (wp-env): after deactivation the weekly sync, the daily rates and the tracking
 * poll were still scheduled, and WordPress keeps re-scheduling a recurring event from the interval it
 * stores in the event. And uninstall.php cleared the hooks with wp_clear_scheduled_hook($hook), which
 * without arguments clears only the events scheduled WITHOUT arguments - every label retry and every
 * dispatch-day appointment carries its order id, so all of them stayed behind.
 *
 * @group core
 */
final class CronClearedOnDeactivationTest extends WP_UnitTestCase {
    private function bgcouriers_events(): array {
        $out = [];
        foreach ((array) _get_cron_array() as $ts => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                if (strpos($hook, 'bgcouriers_') === 0) { foreach ($events as $e) { $out[] = $hook . json_encode($e['args'] ?? []); } }
            }
        }
        sort($out);
        return $out;
    }

    private function seed(): void {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'bgcouriers_weekly_sync'); // far enough from the one-off below: WP drops a same-hook event within ten minutes of another
        wp_schedule_event(time() + 120, 'daily', 'bgcouriers_daily_rates');
        wp_schedule_event(time() + 300, 'twicedaily', 'bgcouriers_poll_tracking');
        wp_schedule_single_event(time() + 3600, 'bgcouriers_retry_autolabel', [4242]);   // a retry, with its order id
        wp_schedule_single_event(time() + 86400, 'bgcouriers_retry_autolabel', [4243]);  // a dispatch-day appointment
        wp_schedule_single_event(time() + 30, 'bgcouriers_weekly_sync');                 // the one-off sync an update asks for
    }

    public function set_up() { parent::set_up(); _set_cron_array([]); }
    public function tear_down() { _set_cron_array([]); parent::tear_down(); }

    public function test_the_old_call_left_the_events_with_arguments_behind(): void {
        $this->seed();
        $this->assertCount(6, $this->bgcouriers_events(), 'control: six events seeded');
        foreach (BGCouriers_Plugin::CRON_HOOKS as $hook) { wp_clear_scheduled_hook($hook); }
        $left = $this->bgcouriers_events();
        $this->assertSame(['bgcouriers_retry_autolabel[4242]', 'bgcouriers_retry_autolabel[4243]'], $left,
            'what uninstall.php used to leave: every event that carries an order id');
    }

    public function test_deactivation_clears_every_event_of_every_hook(): void {
        $this->seed();
        do_action('deactivate_' . plugin_basename(BGCOURIERS_FILE));
        $this->assertSame([], $this->bgcouriers_events());
        foreach (BGCouriers_Plugin::CRON_HOOKS as $hook) { $this->assertFalse(wp_next_scheduled($hook), $hook); }
    }

    public function test_clear_cron_is_what_uninstall_calls(): void {
        $u = (string) file_get_contents(BGCOURIERS_PATH . 'uninstall.php');
        $this->assertStringContainsString('BGCouriers_Plugin::clear_cron()', $u);
        $code = preg_replace('~^\s*(//|\*|/\*).*$~m', '', $u); // the comments may name the old call; the code may not
        $this->assertStringNotContainsString('wp_clear_scheduled_hook', $code, 'the call that misses events with arguments');
        $this->seed();
        BGCouriers_Plugin::clear_cron();
        $this->assertSame([], $this->bgcouriers_events());
    }

    /** The plugin puts its recurring runs back on the next init after re-activation - nothing is lost for good. */
    public function test_the_schedules_come_back(): void {
        BGCouriers_Plugin::clear_cron();
        BGCouriers_Sync::schedule();
        BGCouriers_Tracking_Poller::schedule();
        foreach (['bgcouriers_weekly_sync', 'bgcouriers_daily_rates', 'bgcouriers_poll_tracking'] as $hook) {
            $this->assertNotFalse(wp_next_scheduled($hook), $hook);
        }
    }
}
