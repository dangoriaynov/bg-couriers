<?php
/**
 * Switching a courier on asks for its towns and offices now, not next week.
 *
 * The weekly sync picks up whatever is enabled WHEN IT COMES ROUND. A courier switched on the day after
 * one ran therefore stood on the checkout for six more days with an empty town box and no price, which
 * reads as a broken plugin rather than as data that has not arrived. Reported by the shop owner after
 * switching Express One and Европът on, 2026-09-25.
 *
 * @group core
 */
final class SyncOnEnableTest extends WP_UnitTestCase {

    private function clear(): void {
        $next = wp_next_scheduled(BGCouriers_Sync::HOOK);
        while ($next) { wp_unschedule_event($next, BGCouriers_Sync::HOOK); $next = wp_next_scheduled(BGCouriers_Sync::HOOK); }
    }

    public function test_turning_a_courier_on_schedules_a_sync(): void {
        $this->clear();
        $this->assertFalse(wp_next_scheduled(BGCouriers_Sync::HOOK), 'нічого не заплановано до зміни');

        BGCouriers_Sync::on_courier_enabled('no', 'yes');

        $next = wp_next_scheduled(BGCouriers_Sync::HOOK);
        $this->assertNotFalse($next, 'після ввімкнення має з’явитися разова синхронізація');
        $this->assertLessThanOrEqual(time() + 5 * MINUTE_IN_SECONDS, $next, 'і вона має бути скоро, а не через тиждень');
    }

    /** Кожен збережений «вимкнено» не має нічого планувати - інакше будь-яке збереження форми тягне синхронізацію. */
    public function test_turning_one_off_schedules_nothing(): void {
        $this->clear();
        BGCouriers_Sync::on_courier_enabled('yes', 'no');
        $this->assertFalse(wp_next_scheduled(BGCouriers_Sync::HOOK));
    }

    /** Збереження форми, де курʼєр і так був увімкнений: стан не змінився, синхронізувати нема чого. */
    public function test_saving_an_already_enabled_courier_schedules_nothing(): void {
        $this->clear();
        BGCouriers_Sync::on_courier_enabled('yes', 'yes');
        $this->assertFalse(wp_next_scheduled(BGCouriers_Sync::HOOK));
    }

    /**
     * Перше збереження опції, якої ніколи не існувало, приходить через add_option_X: там перший
     * аргумент - ім'я опції, а не старе значення. Саме цей випадок і є «курʼєра вмикають уперше».
     */
    public function test_the_first_ever_save_also_schedules(): void {
        $this->clear();
        BGCouriers_Sync::on_courier_enabled('bgcouriers_evropat_enabled', 'yes');
        $this->assertNotFalse(wp_next_scheduled(BGCouriers_Sync::HOOK));
    }

    /** І через справжню опцію, з хуками як у бойовому коді. */
    public function test_through_the_real_option(): void {
        $this->clear();
        update_option('bgcouriers_speedy_enabled', 'no');
        $this->clear();
        update_option('bgcouriers_speedy_enabled', 'yes');
        $this->assertNotFalse(wp_next_scheduled(BGCouriers_Sync::HOOK), 'хук на опції має бути підключений');
    }
}
