<?php
defined('ABSPATH') || exit;

/** Renders the shipment panel (waybill + generate/print/track) at the TOP of a BG Couriers order. */
class BGC_Order_Metabox {
    public function __construct() {
        // The order-data panel (after the shipping address) - visible at the top, both HPOS + legacy.
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'render'], 10, 1);
    }

    public function render($order): void {
        $courier = BGC_Labels::order_courier($order); // any BG Couriers order, not just Speedy
        if (!$courier) { return; }
        $id      = $order->get_id();
        $waybill = (string) $order->get_meta('_bgc_waybill');
        $method  = (string) $order->get_meta('_bgc_method');
        $base    = admin_url('admin-post.php');

        echo '<div class="bgc-order-panel" style="margin-top:12px;padding:12px 14px;border:1px solid #e2e6ea;border-radius:8px;background:#fff;">';
        echo '<p style="margin:0 0 8px;"><strong>' . esc_html($courier->label()) . '</strong> - ' . esc_html(ucfirst($method ?: 'office')) . '</p>';

        // Surface the last generation error (handle_generate stores it in a transient, then redirects here),
        // so a failing create_label no longer looks like "nothing happened".
        $err = get_transient('bgc_admin_error_' . $id);
        if ($err) {
            delete_transient('bgc_admin_error_' . $id);
            /* translators: %s: error message from the courier */
            echo '<div style="margin:0 0 8px;padding:8px 10px;border-radius:6px;background:#fcf0f1;border:1px solid #e6a2a5;color:#8a1f2b;">'
                . esc_html(sprintf(__('Label generation failed: %s', 'bg-couriers'), $err)) . '</div>';
        }

        $nonce_url = static function (string $action, string $nonce) use ($base, $id): string {
            return esc_url(wp_nonce_url($base . '?action=' . $action . '&order_id=' . $id, $nonce . $id));
        };
        $cancel_order = $nonce_url('bgc_cancel_order', 'bgc_cancel_order_');
        $confirm      = static function (string $msg): string { return ' onclick="return confirm(\'' . esc_js($msg) . '\')"'; };

        if ($waybill === '') {
            $gen = $nonce_url('bgc_generate_label', 'bgc_generate_label_');
            echo '<a class="button button-primary" href="' . $gen . '">' . esc_html__('Generate label', 'bg-couriers') . '</a> ';
        } else {
            $print  = esc_url(wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id, 'bgc_print_batch'));
            $track  = $nonce_url('bgc_track', 'bgc_track_');
            $cancel = $nonce_url('bgc_cancel_label', 'bgc_cancel_label_');
            $regen  = $nonce_url('bgc_regenerate', 'bgc_regenerate_');
            echo '<p style="margin:0 0 8px;"><strong>' . esc_html__('Waybill', 'bg-couriers') . ':</strong> <code>' . esc_html($waybill) . '</code></p>';
            echo '<a class="button button-primary" target="_blank" href="' . $print . '">' . esc_html__('Print label', 'bg-couriers') . '</a> ';
            echo '<a class="button" target="_blank" href="' . $track . '">' . esc_html__('Track', 'bg-couriers') . '</a> ';
            echo '<a class="button" href="' . $regen . '"' . $confirm(__('Cancel the current waybill and generate a new one from this order\'s delivery details?', 'bg-couriers')) . '>' . esc_html__('Re-generate', 'bg-couriers') . '</a> ';
            echo '<a class="button" href="' . $cancel . '"' . $confirm(__('Cancel (void) this shipment label?', 'bg-couriers')) . '>' . esc_html__('Cancel label', 'bg-couriers') . '</a> ';
        }
        if ($order->get_status() !== 'cancelled') {
            echo '<a class="button" style="color:#b32d2e;" href="' . $cancel_order . '"' . $confirm(__('Cancel this order (its label will be voided too)?', 'bg-couriers')) . '>' . esc_html__('Cancel order', 'bg-couriers') . '</a>';
        }
        echo '</div>';
    }
}
