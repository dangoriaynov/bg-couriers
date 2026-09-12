<?php
/**
 * A cancel the courier refused tells the merchant what the courier said. The adapters answered a bare
 * false and Labels::cancel() wrote "The courier did not cancel the waybill" over every reason - "already
 * picked up", "handed to the driver", a login the courier turned down - so the merchant could only try
 * again. The reason travels as an exception now and lands in the message and the order note; a
 * shipment the courier has already killed itself is still simply cleared, as before.
 *
 * @group core
 */
final class CancelSaysWhyTest extends WP_UnitTestCase {
    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::register('cancelprobe', 'Probe', static function () { return new BGCouriers_Cancel_Probe(); });
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['cancelprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    private function order(): WC_Order {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'cancelprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000005');
        $o->save();
        return $o;
    }

    public function test_the_courier_reason_reaches_the_merchant(): void {
        BGCouriers_Cancel_Probe::$refuse = 'Probe: the parcel was already collected';
        BGCouriers_Cancel_Probe::$gone   = false;
        $o = $this->order();
        try {
            BGCouriers_Labels::cancel($o->get_id());
            $this->fail('a refused cancel throws');
        } catch (BGCouriers_Api_Exception $e) {
            $this->assertStringContainsString('did not cancel the waybill', $e->getMessage());
            $this->assertStringContainsString('Probe: the parcel was already collected', $e->getMessage(), 'the courier own words are in the message');
        }
        $this->assertSame('63740000005', wc_get_order($o->get_id())->get_meta('_bgcouriers_waybill'), 'a live shipment is never dropped');
    }

    public function test_a_shipment_the_courier_already_killed_is_still_cleared(): void {
        BGCouriers_Cancel_Probe::$refuse = 'Probe: shipment is already cancelled';
        BGCouriers_Cancel_Probe::$gone   = true;
        $o = $this->order();
        BGCouriers_Labels::cancel($o->get_id());
        $this->assertSame('', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_waybill'));
    }

    public function test_a_refusal_without_a_word_keeps_the_plain_message(): void {
        BGCouriers_Cancel_Probe::$refuse = '';
        BGCouriers_Cancel_Probe::$gone   = false;
        $o = $this->order();
        try { BGCouriers_Labels::cancel($o->get_id()); $this->fail('a refused cancel throws'); }
        catch (BGCouriers_Api_Exception $e) { $this->assertSame('The courier did not cancel the waybill.', $e->getMessage()); }
    }
}

final class BGCouriers_Cancel_Probe extends BGCouriers_Abstract_Courier {
    public static string $refuse = '';
    public static bool $gone = false;
    public function id(): string { return 'cancelprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label { return new BGCouriers_Label(''); }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string { return ''; }
    public function cancel_label(string $w): bool {
        if (self::$refuse !== '') { throw new BGCouriers_Api_Exception(self::$refuse); }
        return false;
    }
    public function is_cancelled(string $w): bool { return self::$gone; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
