<?php
defined('ABSPATH') || exit;

/**
 * The waybill and the tracking link, for the person waiting for the parcel.
 *
 * Everything this plugin knew about a shipment was admin-only: the merchant saw the waybill and a track
 * link on the order screen, and the customer - the one actually waiting - was told nothing at all. There
 * was not a single WC_Email hook in the plugin, and nothing on the customer's own order page. So the
 * question "where is my parcel" could only be answered by writing to the shop and asking.
 *
 * That gap is why standalone tracking plugins are the biggest adjacent category on WordPress.org:
 * Advanced Shipment Tracking is on 70,000 installs, AfterShip carries 633 ratings, ParcelPanel 533. For
 * a plugin that already creates the label and knows the courier's own tracking URL, this is one block
 * and one email section - not a product.
 *
 * Rendered in two places from one builder, so the two can never disagree:
 *   - the customer's order page (My Account → order, and the order-received page)
 *   - the customer's order emails
 * Both appear only once a label exists. Before that there is nothing true to say.
 */
class BGCouriers_Customer_Tracking {
    public function __construct() {
        // Fires on BOTH the order-received page and My Account → order details, which is exactly the
        // pair a customer goes looking on.
        add_action('woocommerce_order_details_after_order_table', [$this, 'on_order_page'], 20);
        // ...and in the emails. Customer ones only - the merchant's copies already carry the shipment
        // panel on the order screen, and a second copy there is noise.
        add_action('woocommerce_email_after_order_table', [$this, 'in_email'], 20, 4);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    /**
     * Its own small stylesheet, on the pages that can show it.
     *
     * Not part of bgc-checkout.css: that one loads on the cart and the checkout, and this block appears
     * on neither - it lives on the order-received page and in My Account, where that stylesheet has no
     * business being loaded at all.
     */
    public function assets(): void {
        if (!function_exists('is_order_received_page')) { return; }
        if (!is_order_received_page() && !is_view_order_page() && !is_account_page()) { return; }
        $css = BGCOURIERS_PATH . 'assets/css/bgc-track.css';
        wp_enqueue_style('bgc-track', BGCOURIERS_URL . 'assets/css/bgc-track.css', [],
            is_file($css) ? (string) filemtime($css) : BGCOURIERS_VERSION);
    }

    /** @param \WC_Order $order */
    public function on_order_page($order): void {
        if (!$order instanceof \WC_Order) { return; }
        $html = self::panel($order, false);
        if ($html === '') { return; }
        echo wp_kses_post($html);
    }

    /**
     * @param \WC_Order $order
     * @param bool      $sent_to_admin  the merchant's copy - it has the order screen for this
     * @param bool      $plain_text     WooCommerce's plain-text template; markup would be printed raw
     */
    public function in_email($order, $sent_to_admin = false, $plain_text = false, $email = null): void {
        if (!$order instanceof \WC_Order || $sent_to_admin) { return; }
        if ($plain_text) {
            $line = self::plain($order);
            if ($line !== '') { echo "\n" . esc_html($line) . "\n"; }
            return;
        }
        $html = self::panel($order, true);
        if ($html === '') { return; }
        echo wp_kses_post($html);
    }

    /** The courier object for this order, or null when the order predates the plugin or the id is gone. */
    private static function courier_of(\WC_Order $order) {
        $cid = (string) $order->get_meta('_bgcouriers_courier');
        return $cid !== '' ? BGCouriers_Couriers::get($cid) : null;
    }

    /**
     * The block itself.
     *
     * @param bool $for_email inline styles instead of classes - an email has no stylesheet of ours, and
     *                        several clients strip <style> blocks outright.
     */
    private static function panel(\WC_Order $order, bool $for_email): string {
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        if ($waybill === '') { return ''; }
        $courier = self::courier_of($order);
        $name    = $courier ? $courier->label() : (string) $order->get_meta('_bgcouriers_courier');
        $url     = $courier ? $courier->tracking_url($waybill) : '';

        $box  = $for_email
            ? 'style="margin:16px 0;padding:14px 16px;border:1px solid #e0e4ea;border-radius:6px;background:#f7f9fb;"'
            : 'class="bgc-track-box"';
        $ttl  = $for_email ? 'style="font-weight:700;margin:0 0 6px;"' : 'class="bgc-track-title"';
        $row  = $for_email ? 'style="margin:0 0 4px;color:#3c434a;"' : 'class="bgc-track-row"';

        $html  = '<div ' . $box . '>';
        $html .= '<p ' . $ttl . '>' . esc_html__('Your parcel', 'bg-couriers') . '</p>';
        /* translators: %s: courier name, e.g. Speedy */
        $html .= '<p ' . $row . '>' . esc_html(sprintf(__('Courier: %s', 'bg-couriers'), $name)) . '</p>';
        /* translators: %s: the courier's waybill (tracking) number */
        $html .= '<p ' . $row . '>' . esc_html(sprintf(__('Waybill: %s', 'bg-couriers'), $waybill)) . '</p>';
        if ($url !== '') {
            $link = $for_email
                ? 'style="color:#2271b1;font-weight:700;"'
                : 'class="bgc-track-link"';
            $html .= '<p ' . $row . '><a ' . $link . ' href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('Track this parcel', 'bg-couriers') . '</a></p>';
        }
        $html .= '</div>';
        return $html;
    }

    /** The same facts for WooCommerce's plain-text emails, where any markup would be printed literally. */
    private static function plain(\WC_Order $order): string {
        $waybill = (string) $order->get_meta('_bgcouriers_waybill');
        if ($waybill === '') { return ''; }
        $courier = self::courier_of($order);
        $name    = $courier ? $courier->label() : (string) $order->get_meta('_bgcouriers_courier');
        $url     = $courier ? $courier->tracking_url($waybill) : '';
        /* translators: 1: courier name, 2: waybill number */
        $out = sprintf(__('Your parcel - courier: %1$s, waybill: %2$s', 'bg-couriers'), $name, $waybill);
        if ($url !== '') { $out .= ' - ' . $url; }
        return $out;
    }
}
