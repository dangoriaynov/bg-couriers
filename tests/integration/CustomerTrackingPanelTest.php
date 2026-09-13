<?php
/**
 * What the customer is shown about their parcel - on the order page and in the e-mails.
 *
 * The block carries the courier's public tracking link, and two of those were dead for weeks without a
 * test that would have read the link back (BOX NOW's host did not resolve, Sameday's page was a 404).
 * This reads the block the way the page and the e-mail template do.
 *
 * @group core
 */
final class CustomerTrackingPanelTest extends WP_UnitTestCase {
    /** The couriers this test names, registered here: a test file before this one may have reset the registry. */
    public function set_up() {
        parent::set_up();
        BGCouriers_Couriers::reset();
        BGCouriers_Couriers::register('speedy', 'Speedy', static function () { return new BGCouriers_Speedy([]); });
        BGCouriers_Couriers::register('sameday', 'Sameday', static function () { return new BGCouriers_Sameday([]); });
        BGCouriers_Couriers::register('boxnow', 'BOX NOW', static function () { return new BGCouriers_Boxnow([]); });
    }
    public function tear_down() { BGCouriers_Couriers::reset(); parent::tear_down(); }

    private function order(string $courier, string $waybill): WC_Order {
        $order = wc_create_order();
        $order->update_meta_data('_bgcouriers_courier', $courier);
        $order->update_meta_data('_bgcouriers_method', 'automat');
        if ($waybill !== '') { $order->update_meta_data('_bgcouriers_waybill', $waybill); }
        $order->save();
        return $order;
    }

    public function test_the_order_page_names_the_courier_the_waybill_and_a_link_to_the_couriers_page(): void {
        $t = new BGCouriers_Customer_Tracking();
        ob_start(); $t->on_order_page($this->order('boxnow', '0960382208')); $html = ob_get_clean();
        $this->assertStringContainsString('0960382208', $html);
        $this->assertStringContainsString('BOX NOW', $html);
        $this->assertStringContainsString('href="https://boxnow.bg/track?track=0960382208"', $html, 'the link is the courier\'s tracking page with the number in it');
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);

        ob_start(); $t->on_order_page($this->order('sameday', '1CJALN20234067')); $html = ob_get_clean();
        $this->assertStringContainsString('href="https://sameday.bg/status-na-pratkata/?awb=1CJALN20234067"', $html);
    }

    public function test_the_email_carries_the_same_link_and_the_plain_text_one_carries_the_url(): void {
        $t = new BGCouriers_Customer_Tracking();
        $order = $this->order('speedy', '63740770880');
        ob_start(); $t->in_email($order, false, false); $html = ob_get_clean();
        $this->assertStringContainsString('href="https://www.speedy.bg/en/track-shipment?shipmentNumber=63740770880"', $html);
        $this->assertStringContainsString('style=', $html, 'inline styles - an e-mail has no stylesheet of ours');
        ob_start(); $t->in_email($order, false, true); $plain = ob_get_clean();
        $this->assertStringContainsString('63740770880', $plain);
        $this->assertStringContainsString('https://www.speedy.bg/en/track-shipment?shipmentNumber=63740770880', $plain);
        $this->assertStringNotContainsString('<', $plain, 'no markup in a plain-text e-mail');
    }

    public function test_nothing_is_said_before_a_waybill_exists_or_to_the_merchant(): void {
        $t = new BGCouriers_Customer_Tracking();
        ob_start(); $t->on_order_page($this->order('speedy', '')); $this->assertSame('', ob_get_clean());
        ob_start(); $t->in_email($this->order('speedy', '63740770880'), true, false); $this->assertSame('', ob_get_clean(), 'the merchant has the order screen');
    }
}
