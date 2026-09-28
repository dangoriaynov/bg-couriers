<?php
/**
 * Блокът за доставка се появява веднъж, там където трябва.
 *
 * WooCommerce го рисува в таблицата с общата сума (вдясно), а скриптът го премества под данните на
 * клиента (вляво). Между едното и другото браузърът вече го е нарисувал вдясно: на бавно първо
 * рисуване блокът се въртеше вдясно и после отскачаше вляво - страницата изглеждаше така, сякаш се
 * разпада, и пречеше да се работи с нея (собственик, със снимка, 2026-09-28).
 *
 * Затова редовете се крият ОТ ПЪРВОТО рисуване - но само ако има браузър, който после ще ги премести.
 * Ако скриптът не се зареди изобщо, скритият ред е поръчка без избор на доставка, тоест по-лошо от
 * подскока. Затова класът и връщането му назад стоят в един и същ вграден скрипт: който крие, той и
 * връща.
 *
 * @group core
 */
final class DeliveryHostHidesUntilMovedTest extends WP_UnitTestCase {

    private function host_html(): string {
        $checkout = new BGCouriers_Checkout();
        ob_start();
        $checkout->render_delivery_host();
        return (string) ob_get_clean();
    }

    public function set_up() {
        parent::set_up();
        update_option('bgcouriers_delivery_position', 'details');
        // Хостът се печата само на страницата за поръчка; тук няма такава страница.
        add_filter('woocommerce_is_checkout', '__return_true');
    }

    public function tear_down() {
        remove_filter('woocommerce_is_checkout', '__return_true');
        delete_option('bgcouriers_delivery_position');
        parent::tear_down();
    }

    public function test_the_host_is_printed_for_the_block_to_land_in(): void {
        $this->assertStringContainsString('id="bgcouriers-delivery-host"', $this->host_html());
    }

    /** Класът идва от скрипт: без скриптове нищо не мести, а скрит ред е чекаут без доставка. */
    public function test_the_rows_are_hidden_by_script_not_by_the_stylesheet(): void {
        $html = $this->host_html();
        $this->assertStringContainsString('bgc-relocating', $html);
        $this->assertStringContainsString('<script', $html);
    }

    /**
     * Връщането назад е в СЪЩИЯ вграден скрипт, а не в bgc-checkout.js: файлът може да не се зареди
     * (оптимизатор, грешка по-нагоре, липсваща jQuery) и тогава таймер вътре в него няма да се изпълни
     * никога. Проверено в браузър: със спрян bgc-checkout.js редът се показва отново след 3 секунди.
     */
    public function test_the_hiding_undoes_itself_if_nothing_moves(): void {
        $html = $this->host_html();
        $this->assertStringContainsString('setTimeout', $html);
        $this->assertStringContainsString('bgcouriers-delivery-host', $html);
        $this->assertMatchesRegularExpression('~replace\(.*bgc-relocating~', $html);
    }

    /** Другата позиция не крие нищо: блокът си остава в таблицата, където WooCommerce го е нарисувал. */
    public function test_nothing_is_printed_when_the_block_stays_in_the_totals(): void {
        update_option('bgcouriers_delivery_position', 'rate');
        $this->assertSame('', $this->host_html());
    }
}
