<?php
/**
 * A waybill the courier accepted but did not fully apply is flagged on the order screen.
 *
 * Speedy's cash-on-delivery carries ignoreIfNotApplicable by design: the shipment is created, the
 * waybill prints, and nothing is collected at the door. generate() records that on the order in a note
 * and, in its own words, "keeps a flag the admin screens can show" - and no screen ever read the flag.
 * The note scrolls away under the next one; the parcel is on the desk about to be handed over. The
 * order panel now shows it, until the waybill is re-issued.
 *
 * @group core
 */
final class LabelWarningIsShownTest extends WP_UnitTestCase {
    /**
     * A courier of this test's own. The panel renders nothing for an order whose courier is not
     * registered, and other tests in this suite empty the registry - so an order on "speedy" rendered
     * a full panel with this test run alone and an empty string with the suite around it.
     */
    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::register('warnprobe', 'Probe', static function () { return new BGCouriers_Warn_Probe(); });
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['warnprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    private function panel(WC_Order $o): string {
        ob_start();
        (new BGCouriers_Order_Metabox())->render($o);
        return (string) ob_get_clean();
    }

    public function test_the_warning_the_label_recorded_is_on_the_order_screen(): void {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'warnprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000000');
        $o->update_meta_data('_bgcouriers_label_warning', 'cash on delivery was not applied');
        $o->save();

        $html = $this->panel($o);
        $this->assertStringContainsString('bgc-order-panel', $html, 'the panel rendered at all - an empty string would pass a "not contains" below');
        $this->assertStringContainsString('cash on delivery was not applied', $html, 'the courier own words, on the screen');
        $this->assertStringContainsString('bgc-warn', $html);
    }

    /** And nothing is shown when there is nothing to warn about - an empty flag is not a warning. */
    public function test_no_warning_no_box(): void {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'warnprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000001');
        $o->save();

        $html = $this->panel($o);
        $this->assertStringContainsString('bgc-order-panel', $html);
        $this->assertStringNotContainsString('bgc-warn', $html);
    }
}

final class BGCouriers_Warn_Probe extends BGCouriers_Abstract_Courier {
    public function id(): string { return 'warnprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
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
}
