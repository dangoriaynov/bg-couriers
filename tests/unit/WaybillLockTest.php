<?php
require_once dirname(__DIR__) . '/stubs/wc-order.php';

/**
 * After handover, cancelling or re-issuing a waybill changes only our copy, so those actions lock at
 * handover (not at "has a waybill" or "is moving").
 *
 * @group core
 */
final class WaybillLockTest extends BGCouriers_Unit_TestCase {

    /** @param array<string,string> $meta */
    private function order(array $meta): WC_Order {
        $o = new WC_Order();
        $o->meta = $meta + ['_bgcouriers_courier' => 'speedy', '_bgcouriers_waybill' => '63689161156'];
        return $o;
    }

    /** @return array<string,array{array,bool}> order meta => locked? */
    public static function lock_states(): array {
        return [
            'fresh waybill'               => [[], false],
            'registered, not collected'   => [['_bgcouriers_track_stage' => 'transit', '_bgcouriers_track_text' => 'Получена информация за пратка'], false],
            'collected'                   => [['_bgcouriers_handover' => 'yes', '_bgcouriers_track_stage' => 'transit'], true],
            'delivered'                   => [['_bgcouriers_track_stage' => 'delivered'], true],
            'travelling back'             => [['_bgcouriers_track_stage' => 'returning'], true],
            'back on the counter'         => [['_bgcouriers_track_stage' => 'returned'], false],
            'cancelled, re-issue is the way out' => [['_bgcouriers_track_stage' => 'cancelled', '_bgcouriers_track_text' => 'Анулиране'], false],
        ];
    }

    /** @dataProvider lock_states */
    public function test_the_waybill_locks_from_handover_until_the_parcel_is_back(array $meta, bool $locked): void {
        $this->assertSame($locked, BGCouriers_Labels::is_locked($this->order($meta)));
    }

    // ── Reopening a locked order ────────────────────────────────────────────

    /** @return array<string,array{string,bool}> new status => reopened? */
    public static function reopen_transitions(): array {
        return [
            'processing' => ['processing', true],
            'pending'    => ['pending', true],
            'completed'  => ['completed', false],
            'cancelled'  => ['cancelled', false],
            'on-hold'    => ['on-hold', false],
            'refunded'   => ['refunded', false],
        ];
    }

    /**
     * The merchant reopens a locked order by moving it back to Processing/Pending: keyed on the
     * transition, because Processing is also where a shipped order sits when auto-status is off.
     *
     * @dataProvider reopen_transitions
     */
    public function test_moving_a_locked_order_back_to_work_reopens_it(string $to, bool $reopened): void {
        $o = $this->order(['_bgcouriers_handover' => 'yes', '_bgcouriers_track_stage' => 'transit']);
        BGCouriers_Labels::maybe_reopen($o, 'bgc-shipped', $to);
        $this->assertSame($reopened ? 'yes' : '', $o->meta['_bgcouriers_reopened'] ?? '');
        $this->assertSame(!$reopened, BGCouriers_Labels::is_locked($o));
    }

    /** A reopen flag set before handover would later unlock the very order it should hold. */
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

    /** kses silently drops attributes not on the allowlist, so the disabled markers must be on it. */
    public function test_the_disabled_state_attributes_survive_kses(): void {
        require_once dirname(__DIR__, 2) . '/includes/Admin/class-bgcouriers-order-metabox.php';
        $button = BGCouriers_Order_Metabox::PANEL_TAGS['button'];
        foreach (['aria-disabled', 'tabindex', 'class', 'data-tip'] as $attr) {
            $this->assertArrayHasKey($attr, $button, "the panel would strip {$attr} from a button");
        }
        foreach (['select', 'input', 'button'] as $tag) {
            $this->assertArrayHasKey('disabled', BGCouriers_Order_Metabox::EDITOR_TAGS[$tag],
                "the editor would strip disabled from a {$tag} and leave it live");
        }
    }

    /** One rule over the finished markup, so every control shape (including selectWoo-backed selects) is covered. */
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
