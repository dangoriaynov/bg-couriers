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
    private function panel(WC_Order $o): string {
        ob_start();
        (new BGCouriers_Order_Metabox())->render($o);
        return (string) ob_get_clean();
    }

    public function test_the_warning_the_label_recorded_is_on_the_order_screen(): void {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'speedy');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000000');
        $o->update_meta_data('_bgcouriers_label_warning', 'cash on delivery was not applied');
        $o->save();

        $html = $this->panel($o);
        $this->assertStringContainsString('cash on delivery was not applied', $html, 'the courier own words, on the screen');
        $this->assertStringContainsString('bgc-warn', $html);
    }

    /** And nothing is shown when there is nothing to warn about - an empty flag is not a warning. */
    public function test_no_warning_no_box(): void {
        $o = new WC_Order();
        $o->update_meta_data('_bgcouriers_courier', 'speedy');
        $o->update_meta_data('_bgcouriers_method', 'office');
        $o->update_meta_data('_bgcouriers_waybill', '63740000001');
        $o->save();

        $this->assertStringNotContainsString('bgc-warn', $this->panel($o));
    }
}
