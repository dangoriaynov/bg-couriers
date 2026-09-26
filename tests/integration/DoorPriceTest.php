<?php
/**
 * Колко ще събере куриерът на вратата - число, което поръчката криеше.
 *
 * При изключена „Доставка в цената на поръчката" редът на доставката струва 0, а WooCommerce тогава
 * печата само името на метода (get_shipping_to_display не показва сума при нула). Поръчка, която ще
 * струва на клиента 2,98 EUR при получаване, пишеше „Speedy: До офис" и толкова.
 *
 * Числото се презаписва в момента на издаване на товарителницата - тогава пратката, градът и офисът са
 * решени - и НЕ се пипа при отказ на товарителница: това, което куриерът ще вземе, не се променя от
 * това, че магазинът е анулирал етикет (собственик, 2026-09-26).
 *
 * @group core
 */
final class DoorPriceTest extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::register('doorprobe', 'Probe', static function () { return new BGCouriers_Door_Probe(); });
        update_option('bgcouriers_doorprobe_ship_in_total', 'no');   // клиентът плаща на куриера
        BGCouriers_Door_Probe::$price = 3.06;
        BGCouriers_Door_Probe::$fail  = false;
    }

    public function tear_down() {
        delete_option('bgcouriers_doorprobe_ship_in_total');
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['doorprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    private function order(float $info = 0.0): WC_Order {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'doorprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_site_id', 68134);
        $o->update_meta_data('_bgcouriers_office_id', 2);
        $item = new WC_Order_Item_Shipping();
        $item->set_method_id('bgcouriers_doorprobe');
        $item->set_method_title('Probe');
        $item->set_total(0);
        if ($info > 0) { $item->update_meta_data('_bgcouriers_info_price', $info); }
        $o->add_item($item);
        $o->save();
        return $o;
    }

    private function info_price(WC_Order $o): float {
        $o = wc_get_order($o->get_id());
        foreach ($o->get_items('shipping') as $i) { return (float) $i->get_meta('_bgcouriers_info_price', true); }
        return 0.0;
    }

    public function test_the_waybill_writes_what_the_courier_will_collect(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $o = $this->order();
        BGCouriers_Labels::refresh_door_price($o);
        $this->assertEqualsWithDelta(3.06, $this->info_price($o), 0.01);
    }

    /** Нова цена при преиздаване: сумата се сменя, не се трупа втора. */
    public function test_re_issuing_replaces_the_figure(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $o = $this->order(3.06);
        BGCouriers_Door_Probe::$price = 4.20;
        BGCouriers_Labels::refresh_door_price($o);
        $this->assertEqualsWithDelta(4.20, $this->info_price($o), 0.01);
    }

    /**
     * Куриерът не отговори: старата цена остава. Изчистена цена е клиент, който пита колко да приготви,
     * и магазин, който не може да каже - по-лошо от цена с един ден давност.
     */
    public function test_a_failed_quote_keeps_the_old_figure(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $o = $this->order(3.06);
        BGCouriers_Door_Probe::$fail = true;
        BGCouriers_Labels::refresh_door_price($o);
        $this->assertEqualsWithDelta(3.06, $this->info_price($o), 0.01);
    }

    /** При „доставката е в сумата на поръчката" няма какво да се добавя - WooCommerce вече печата сумата. */
    public function test_nothing_is_written_when_delivery_is_charged_with_the_order(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        update_option('bgcouriers_doorprobe_ship_in_total', 'yes');
        $o = $this->order();
        BGCouriers_Labels::refresh_door_price($o);
        $this->assertSame(0.0, $this->info_price($o));
    }

    /** Редът, който вижда клиентът: сумата стои ПОД името на метода, а не в стойността на реда. */
    public function test_the_customer_row_says_the_amount(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        $o = $this->order(2.98);
        $totals = $o->get_order_item_totals();
        $this->assertArrayHasKey('shipping', $totals);
        $this->assertStringContainsString('2', wp_strip_all_tags((string) $totals['shipping']['value']));
        $this->assertStringContainsString('paid to the courier', wp_strip_all_tags((string) $totals['shipping']['value']));
    }

    public function test_the_customer_row_is_untouched_when_delivery_is_in_the_total(): void {
        if (!function_exists('wc_create_order')) { $this->markTestSkipped('WC not loaded'); }
        update_option('bgcouriers_doorprobe_ship_in_total', 'yes');
        $o = $this->order(2.98);
        $totals = $o->get_order_item_totals();
        $this->assertStringNotContainsString('paid to the courier', wp_strip_all_tags((string) ($totals['shipping']['value'] ?? '')));
    }
}

final class BGCouriers_Door_Probe extends BGCouriers_Abstract_Courier {
    public static float $price = 3.06;
    public static bool $fail = false;
    public function id(): string { return 'doorprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address', 'live_quote']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote {
        if (self::$fail) { throw new BGCouriers_Api_Exception('Probe: no price today'); }
        return new BGCouriers_Quote(self::$price, 0.0, 'EUR', 'live');
    }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label('1'); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
