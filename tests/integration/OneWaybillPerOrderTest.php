<?php
/**
 * An order gets ONE waybill. A waybill is a real shipment on a live courier account: it costs money, it
 * can book a pickup, and it carries the customer's address. Two of them for one order is a parcel that
 * travels twice, or one that nobody ever voids.
 *
 * generate() is the one place every label is made, and it had two ways of making a second one:
 *
 *  1. The waybill was written to the order only at the very END, after two more calls to the courier
 *     for the label PDF. When one of those failed - a /print that answered 503 seconds after /create
 *     had answered 200, which is what a flaky API looks like - the exception left generate() with the
 *     shipment created at the courier and NOT on the order. The automatic retry five minutes later
 *     found no waybill and created another. The first was never heard of again.
 *
 *  2. Its only guard against being run twice was a read of the order's meta BEFORE a multi-second
 *     API call. Two requests inside that window - the payment webhook's auto-label and the merchant
 *     clicking "generate" on the order they have just seen appear - both passed the read and both
 *     booked a shipment; the second overwrote the first on the order.
 *
 * @group core
 */
final class OneWaybillPerOrderTest extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        BGCouriers_Schema::create();
        BGCouriers_Waybill_Probe::$created = [];
        BGCouriers_Waybill_Probe::$pdf_fails = false;
        BGCouriers_Waybill_Probe::$reenter = null;
        BGCouriers_Couriers::register('wbprobe', 'Probe', static function () { return new BGCouriers_Waybill_Probe(); });
        update_option('bgcouriers_wbprobe_enabled', 'yes');
    }
    public function tear_down() {
        foreach (['defs', 'built'] as $prop) {
            $r = new ReflectionProperty('BGCouriers_Couriers', $prop);
            $r->setAccessible(true);
            $v = $r->getValue(); unset($v['wbprobe']); $r->setValue(null, $v);
        }
        parent::tear_down();
    }

    private function order(): WC_Order {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'wbprobe');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->save();
        return $o;
    }

    /**
     * (1) The courier created the shipment and then would not hand over the PDF. The waybill must be
     * on the order regardless - the shipment EXISTS - so that the next attempt, automatic or by hand,
     * finds it there and does not create another.
     */
    public function test_a_waybill_the_courier_issued_is_kept_even_when_the_pdf_is_not(): void {
        $o = $this->order();
        BGCouriers_Waybill_Probe::$pdf_fails = true;

        try { BGCouriers_Labels::generate($o->get_id()); } catch (\Exception $e) { /* the old code threw here */ }
        $fresh = wc_get_order($o->get_id());
        $this->assertSame('WB-1', (string) $fresh->get_meta('_bgcouriers_waybill'),
            'the shipment the courier created is recorded on the order, PDF or no PDF');

        BGCouriers_Labels::generate($o->get_id());   // the retry
        $this->assertSame(['WB-1'], BGCouriers_Waybill_Probe::$created,
            'and the retry found it, rather than creating a second shipment');
    }

    /**
     * (2) A second request arrives while the first is at the courier. Modelled by the courier itself
     * calling generate() for the same order from inside create_label(), which is where the first
     * request is when the second one comes in.
     */
    public function test_a_second_request_during_the_first_does_not_book_a_second_shipment(): void {
        $o  = $this->order();
        $id = $o->get_id();
        $inner = null;
        BGCouriers_Waybill_Probe::$reenter = static function () use ($id, &$inner) {
            try { BGCouriers_Labels::generate($id); $inner = 'issued'; }
            catch (\Exception $e) { $inner = 'refused: ' . $e->getMessage(); }
        };

        BGCouriers_Labels::generate($id);

        $this->assertCount(1, BGCouriers_Waybill_Probe::$created, 'one shipment for one order, however many asked');
        $this->assertStringStartsWith('refused', (string) $inner, 'the second request was told to wait, not given a shipment');
        $this->assertSame('WB-1', (string) wc_get_order($id)->get_meta('_bgcouriers_waybill'));
    }

    /**
     * The claim that keeps (2) honest across REQUESTS, not only inside one: it is held in the database,
     * so a second PHP process on its own connection cannot take it while the first has it. Checked from
     * a second connection, which is what a second request is.
     */
    public function test_the_claim_is_exclusive_across_database_connections(): void {
        global $wpdb;
        $name  = 'bgcouriers_label_' . 424242;
        $this->assertSame('1', (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)), 'taken on this connection');

        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->assertSame('0', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)),
            'and not available to another connection while it is held');

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)), 'free again once released');
        $other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * A voided shipment leaves nothing behind. The stage is written the moment a waybill is issued and
     * it stayed when the waybill was cancelled, so the orders list showed "registered" for an order with
     * nothing registered - seen on dev after voiding a real booking.
     */
    public function test_a_cancelled_waybill_takes_its_stage_with_it(): void {
        $o = $this->order();
        BGCouriers_Labels::generate($o->get_id());
        $this->assertSame('registered', (string) wc_get_order($o->get_id())->get_meta('_bgcouriers_track_stage'));

        BGCouriers_Labels::cancel($o->get_id());
        $fresh = wc_get_order($o->get_id());
        $this->assertSame('', (string) $fresh->get_meta('_bgcouriers_waybill'));
        $this->assertSame('', (string) $fresh->get_meta('_bgcouriers_track_stage'), 'no shipment, no stage');
        $this->assertSame('', (string) $fresh->get_meta('_bgcouriers_track_updated'));
    }

    /** And the ordinary case is untouched: the second call for an already-labelled order just answers. */
    public function test_an_order_that_has_a_waybill_is_answered_without_the_courier(): void {
        $o = $this->order();
        BGCouriers_Labels::generate($o->get_id());
        $again = BGCouriers_Labels::generate($o->get_id());
        $this->assertSame('WB-1', $again->waybill);
        $this->assertCount(1, BGCouriers_Waybill_Probe::$created);
    }
}

/** Issues WB-1, WB-2, ... and can be told to fail the PDF, or to re-enter generate() mid-create. */
final class BGCouriers_Waybill_Probe extends BGCouriers_Abstract_Courier {
    /** @var string[] */
    public static array $created = [];
    public static bool $pdf_fails = false;
    /** @var callable|null */
    public static $reenter = null;
    public function id(): string { return 'wbprobe'; }
    public function label(): string { return 'Probe'; }
    public function capabilities(): array { return ['office', 'address']; }
    public function check_credentials(): bool { return true; }
    public function fetch_cities(): array { return []; }
    public function fetch_offices(int $c, string $country = ''): array { return []; }
    public function quote(array $s): BGCouriers_Quote { return new BGCouriers_Quote(1.0, 0.0, 'EUR', 'fixed'); }
    public function create_label(\WC_Order $o): BGCouriers_Label {
        $wb = 'WB-' . (count(self::$created) + 1);
        self::$created[] = $wb;
        if (self::$reenter) { $f = self::$reenter; self::$reenter = null; $f(); }
        return new BGCouriers_Label($wb, '');
    }
    public function label_formats(): array { return []; }
    public function get_label_pdf(string $w, string $f = ''): string {
        if (self::$pdf_fails) { throw new BGCouriers_Api_Exception('Probe could not print the label.'); }
        return '%PDF-1.4 fake';
    }
    public function cancel_label(string $w): bool { return true; }
    public function track(string $w): BGCouriers_Tracking { return new BGCouriers_Tracking($w, '', []); }
    public function tracking_url(string $w): string { return ''; }
}
