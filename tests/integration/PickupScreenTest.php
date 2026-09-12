<?php
/**
 * The "Request a courier" screen names the courier's next cut-off and offers the day of that cut-off.
 *
 * It used to print the first cut-off as given and judge the day against a clock WordPress hands back
 * shifted by the site's zone, so from 14:00 the line said "accepts requests up to 17:00" next to a date
 * field already saying tomorrow. Both read from the one list of cut-offs still ahead now. The arithmetic
 * is held by tests/unit/PickupDefaultDayTest.php; this holds the wiring of the screen itself.
 *
 * @group core
 */
final class PickupScreenTest extends WP_UnitTestCase {
    /** A courier of this test's own - other tests in the suite empty the registry. */
    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::register('pickprobe', 'Probe', static function () { return new BGCouriers_Pick_Probe(); });
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('gmt_offset', 3);
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['pickprobe']); $r->setValue(null, $v);
        }
        unset($_REQUEST['orders'], $_REQUEST['_wpnonce'], $_POST['bgcouriers_confirm']);
        parent::tear_down();
    }

    private function screen(int $order_id): string {
        $_REQUEST['orders']   = (string) $order_id;
        $_REQUEST['_wpnonce'] = wp_create_nonce(BGCouriers_Pickup::ACTION);
        ob_start();
        (new BGCouriers_Pickup())->render();
        return (string) ob_get_clean();
    }

    public function test_the_line_and_the_date_field_name_the_same_cut_off(): void {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'pickprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000002');
        $o->save();

        $html = $this->screen($o->get_id());
        $this->assertStringContainsString('63740000002', $html, 'the screen rendered the order at all');

        // The cut-off an hour ago is not on the screen; the one an hour ahead is, and it is the first.
        $this->assertStringNotContainsString(BGCouriers_Pick_Probe::$past, $html);
        $this->assertStringContainsString('up to ' . BGCouriers_Pick_Probe::$next . '.', $html);

        // And the date field carries that cut-off's day in the site's zone, not the one after.
        $day = gmdate('Y-m-d', strtotime(BGCouriers_Pick_Probe::$next) + 3 * HOUR_IN_SECONDS);
        $this->assertMatchesRegularExpression('/name="bgcouriers_date" value="' . preg_quote($day, '/') . '"/', $html);
    }
}

final class BGCouriers_Pick_Probe extends BGCouriers_Abstract_Courier {
    public static string $past = '';
    public static string $next = '';
    public function id(): string { return 'pickprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address', 'pickup']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
    /** One cut-off an hour gone, one an hour ahead, one a day ahead - written the way Speedy writes them. */
    public function pickup_terms(string $date): array {
        $fmt = static function (int $ts): string { return gmdate('Y-m-d\TH:i:s', $ts + 3 * 3600) . '+0300'; };
        self::$past = $fmt(time() - 3600);
        self::$next = $fmt(time() + 3600);
        return [self::$past, self::$next, $fmt(time() + 86400 + 3600)];
    }
    public function request_pickup(array $waybills, array $args): string { return 'never called here'; }
}
