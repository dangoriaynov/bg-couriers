<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * Once a courier has physically collected a parcel, cancelling or re-issuing its waybill changes only OUR
 * copy: the courier keeps delivering against the document travelling with the box, and the shop ends up
 * believing a shipment was voided when it is still on its way. So those actions stop at that point.
 *
 * The line is HANDOVER, not "has a waybill" and not "is moving": a freshly created label sits at the
 * courier as data only, and every courier reports that as its own first tracking event - re-issuing then
 * is perfectly safe and is exactly what the merchant does after fixing an address.
 *
 * @group core
 */
final class WaybillLockTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when('__')->returnArg(1); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    /** @param array<string,string> $meta */
    private function order(array $meta): WC_Order {
        $o = new WC_Order();
        $o->meta = $meta + ['_bgcouriers_courier' => 'speedy', '_bgcouriers_waybill' => '63689161156'];
        return $o;
    }

    /** Nothing heard from the courier yet - a label that was only just created stays fully editable. */
    public function test_a_fresh_waybill_is_not_locked(): void {
        $this->assertFalse(BGCouriers_Labels::is_locked($this->order([])));
    }

    /**
     * The parcel is registered and moving through the courier's own first event, but nobody has collected
     * anything yet. Locking here would block the ordinary "fix the address and re-issue" flow.
     */
    public function test_registered_but_not_collected_is_not_locked(): void {
        $o = $this->order(['_bgcouriers_track_stage' => 'transit',
                           '_bgcouriers_track_text'  => 'Получена информация за пратка']);
        $this->assertFalse(BGCouriers_Labels::is_locked($o));
    }

    /** The courier has it: this is the point of no return. */
    public function test_collected_is_locked(): void {
        $o = $this->order(['_bgcouriers_handover' => 'yes', '_bgcouriers_track_stage' => 'transit']);
        $this->assertTrue(BGCouriers_Labels::is_locked($o));
    }

    /** Delivered, and a parcel travelling BACK, are both out of the merchant's hands. */
    public function test_shipments_in_the_couriers_hands_are_locked(): void {
        foreach (['delivered', 'returning'] as $stage) {
            $this->assertTrue(BGCouriers_Labels::is_locked($this->order(['_bgcouriers_track_stage' => $stage])), $stage);
        }
    }

    /**
     * A parcel that has come all the way back is on the merchant's own counter: that waybill is spent and
     * a second attempt needs a fresh one, so it must NOT be locked. Locking it left the shop holding a box
     * it could not re-send.
     */
    public function test_a_parcel_back_on_the_counter_is_not_locked(): void {
        $this->assertFalse(BGCouriers_Labels::is_locked($this->order(['_bgcouriers_track_stage' => 'returned'])));
    }

    /**
     * A cancelled shipment is deliberately NOT locked - it is void, and re-issuing is the way out of it.
     * Locking that would leave the order with no usable waybill and no way to make one.
     */
    public function test_a_cancelled_shipment_stays_editable(): void {
        $o = $this->order(['_bgcouriers_track_stage' => 'cancelled', '_bgcouriers_track_text' => 'Анулиране']);
        $this->assertFalse(BGCouriers_Labels::is_locked($o));
    }

    // ── Reopening a locked order ────────────────────────────────────────────

    /**
     * The way out, and the merchant declares it: putting the order back to Processing or Pending payment
     * means "I am reworking this one", and everything opens again - cancel the waybill, change the
     * address, change the courier.
     *
     * It keys off the TRANSITION, not off the status itself, and that distinction is the whole point:
     * Processing is where an ordinary paid order already sits. A shop that leaves the automatic "Shipped"
     * status switched off keeps its orders in Processing the entire time the courier is carrying them, so
     * reading the status alone would mean the lock never engaged for that shop at all.
     */
    public function test_a_locked_order_put_back_to_processing_is_reopened(): void {
        $o = $this->order(['_bgcouriers_handover' => 'yes', '_bgcouriers_track_stage' => 'transit']);
        $this->assertTrue(BGCouriers_Labels::is_locked($o), 'locked to begin with');
        BGCouriers_Labels::maybe_reopen($o, 'bgc-shipped', 'processing');
        $this->assertSame('yes', $o->meta['_bgcouriers_reopened'] ?? '');
        $this->assertFalse(BGCouriers_Labels::is_locked($o), 'and now everything is available again');
    }

    public function test_pending_payment_reopens_it_too(): void {
        $o = $this->order(['_bgcouriers_handover' => 'yes']);
        BGCouriers_Labels::maybe_reopen($o, 'bgc-shipped', 'pending');
        $this->assertFalse(BGCouriers_Labels::is_locked($o));
    }

    /** Any other status is not a reopening - Completed is where a delivered order is supposed to end. */
    public function test_other_statuses_do_not_reopen(): void {
        foreach (['completed', 'cancelled', 'on-hold', 'refunded'] as $to) {
            $o = $this->order(['_bgcouriers_handover' => 'yes']);
            BGCouriers_Labels::maybe_reopen($o, 'bgc-shipped', $to);
            $this->assertTrue(BGCouriers_Labels::is_locked($o), $to);
        }
    }

    /**
     * The ordinary payment -> Processing transition, on an order with nothing collected, must NOT leave a
     * reopen flag lying around: it would sit there until the parcel WAS collected and then unlock the one
     * order it should have held.
     */
    public function test_becoming_processing_before_any_handover_leaves_no_flag(): void {
        $o = $this->order([]);
        BGCouriers_Labels::maybe_reopen($o, 'pending', 'processing');
        $this->assertArrayNotHasKey('_bgcouriers_reopened', $o->meta);
        $o->meta['_bgcouriers_handover'] = 'yes'; // the courier collects it later
        $this->assertTrue(BGCouriers_Labels::is_locked($o), 'the old transition must not unlock this');
    }

    /** A fresh waybill starts a fresh shipment: the previous reopening is spent. */
    public function test_issuing_a_new_waybill_clears_the_reopening(): void {
        $o = $this->order(['_bgcouriers_handover' => 'yes', '_bgcouriers_reopened' => 'yes']);
        $this->assertFalse(BGCouriers_Labels::is_locked($o));
        BGCouriers_Labels::reset_shipment_state($o);
        $this->assertArrayNotHasKey('_bgcouriers_reopened', $o->meta);
    }

    /**
     * ...and it has to forget the OLD parcel as well, or the whole way out closes behind the merchant:
     * reopen, cancel, issue a new waybill - and the handover flag left over from the parcel that is long
     * gone locks the order again on the spot. That flag was never cleared anywhere.
     */
    public function test_a_new_waybill_forgets_the_previous_parcel(): void {
        $o = $this->order([
            '_bgcouriers_handover'     => 'yes',
            '_bgcouriers_reopened'     => 'yes',
            '_bgcouriers_track_done'   => 'yes',
            '_bgcouriers_track_first'  => '148',
            '_bgcouriers_track_status' => 'Взета от получателя',
            '_bgcouriers_track_text'   => 'Взета от получателя',
        ]);
        BGCouriers_Labels::reset_shipment_state($o);
        foreach (['_bgcouriers_handover', '_bgcouriers_track_done', '_bgcouriers_track_first',
                  '_bgcouriers_track_status', '_bgcouriers_track_text'] as $k) {
            $this->assertArrayNotHasKey($k, $o->meta, $k);
        }
        $this->assertFalse(BGCouriers_Labels::is_locked($o), 'the new waybill starts unlocked');
    }

    /** The refusal has to say why, and name the way out - it is the merchant's only cue. */
    public function test_the_message_explains_and_points_somewhere(): void {
        $m = BGCouriers_Labels::locked_message();
        $this->assertNotSame('', $m);
        $this->assertStringContainsString('courier', $m);
        $this->assertStringContainsString('Processing', $m, 'it must name the way back');
    }

    /**
     * The blocked controls are marked with attributes the panel prints through wp_kses, and kses drops
     * anything not on the allowlist WITHOUT a word. That is not hypothetical here: the same omission is
     * why the stage colours in the Orders list were invisible for as long as they existed. If a control
     * is going to look disabled, the attribute that says so has to survive being printed.
     */
    public function test_the_disabled_state_attributes_survive_kses(): void {
        require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-metabox.php';
        $button = BGCouriers_Order_Metabox::PANEL_TAGS['button'];
        foreach (['aria-disabled', 'tabindex', 'class', 'data-tip'] as $attr) {
            $this->assertArrayHasKey($attr, $button, "the panel would strip {$attr} from a button");
        }
        // The editor locks by putting `disabled` on the controls themselves, so all three tags need it -
        // a missing entry there is invisible: kses drops the attribute and the field stays editable.
        foreach (['select', 'input', 'button'] as $tag) {
            $this->assertArrayHasKey('disabled', BGCouriers_Order_Metabox::EDITOR_TAGS[$tag],
                "the editor would strip disabled from a {$tag} and leave it live");
        }
    }

    /**
     * Every control, not the ones that happened to be remembered. The lock was a stylesheet before this,
     * and it reached the two plain selects while city, street and office - the three selectWoo replaces
     * with a span of its own - stayed fully editable inside a form whose Save was greyed out. That is the
     * shape of the bug this pins: one rule over the finished markup, so a field added later is covered too.
     */
    public function test_locking_the_editor_reaches_every_control(): void {
        require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-metabox.php';
        // The real editor's shapes: selectWoo-backed selects, plain inputs, a hidden one, the map button.
        $form = '<div class="bgc-ed-form bgc-ed-locked">'
            . '<p><label>Courier</label><select class="bgc-ed-courier" style="min-width:240px;"><option value="speedy" selected>Speedy</option></select></p>'
            . '<p><select class="bgc-ed-city"><option></option></select><input type="hidden" class="bgc-ed-postcode" value="1137"></p>'
            . '<p><select class="bgc-ed-office"></select><button type="button" class="button bgc-ed-map"><span class="dashicons"></span></button></p>'
            . '<div><select class="bgc-ed-street"></select><input class="bgc-ed-streetno" value="230"></div>'
            . '<input class="bgc-ed-block" value=""><input class="bgc-ed-apartment" value="">'
            . '<p><button type="button" class="button button-primary bgc-ed-save">Save delivery</button></p></div>';

        $out = BGCouriers_Order_Metabox::disable_controls($form);

        $controls = preg_match_all('/<(?:select|input|button)\b/', $out);
        $disabled = preg_match_all('/<(?:select|input|button) disabled\b/', $out);
        $this->assertSame(10, $controls, 'the sample has to hold every control shape the editor uses');
        $this->assertSame($controls, $disabled, 'a control was left operable while the courier holds the parcel');
        // Only controls: an <option> or a <label> carrying `disabled` is markup nobody asked for.
        $this->assertStringNotContainsString('<option disabled', $out);
        $this->assertStringNotContainsString('<label disabled', $out);
        $this->assertStringContainsString('<span class="dashicons">', $out);
    }
}
