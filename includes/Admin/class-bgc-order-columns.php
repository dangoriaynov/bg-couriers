<?php
defined('ABSPATH') || exit;

class BGC_Order_Columns {
    public function __construct() {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'col']);
        add_filter('manage_edit-shop_order_columns', [$this, 'col']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy'], 10, 2);
        add_action('admin_footer', [$this, 'copy_script']);
    }
    public function col($cols) { $cols['bgc_shipping'] = __('Waybill', 'bg-couriers'); return $cols; }

    public static function cell_html(string $waybill, string $print_url, string $track_url, string $generate_url, int $order_id = 0, string $cancel_nonce = '', string $generate_nonce = '', string $courier_label = '', string $courier_logo = ''): string {
        // Courier logo tile with a data-tip hover hint, SAME as the order-screen shipment panel header.
        $logo_tile = $courier_logo !== ''
            ? '<span class="bgc-ltile" data-tip="' . esc_attr($courier_label) . '"><img class="bgc-clogo" src="' . esc_url($courier_logo) . '" alt="' . esc_attr($courier_label) . '"></span>'
            : '';
        // The pencil shows in BOTH states (with or without a waybill). #bgc-edit makes the order
        // screen auto-open the delivery-details editor (bgc-order-admin.js), no second click needed.
        $edit_ico = '';
        if ($order_id && function_exists('wc_get_order')) {
            $o = wc_get_order($order_id);
            if ($o) {
                $edit_ico = '<a class="bgc-ico bgc-edit-lnk" href="' . esc_url($o->get_edit_order_url() . '#bgc-edit') . '" data-tip="' . esc_attr__('Edit delivery details', 'bg-couriers') . '" aria-label="' . esc_attr__('Edit delivery details', 'bg-couriers') . '"><span class="dashicons dashicons-edit"></span></a>';
            }
        }
        // Two fixed rows: row 1 = courier logo + pencil (always), row 2 = Generate OR the
        // waybill actions (copy / print / track / cancel). JS cancel-swap only touches row 2.
        $row1 = '<span class="bgc-row">' . $logo_tile . $edit_ico . '</span>';
        if ($waybill === '') {
            return '<span class="bgc-cell">' . $row1
                . '<span class="bgc-row"><a class="button button-small bgc-gen" href="' . esc_url($generate_url) . '">' . esc_html__('Generate', 'bg-couriers') . '</a></span></span>';
        }
        // Same tile look and order as the order-screen shipment panel: copy (stands in for the
        // waybill number, which is the panel's copy control) then Print (primary) / Track / Cancel.
        // The cancel link carries the order id + a cancel nonce + a generate nonce; JS voids the
        // waybill over AJAX and swaps row 2 back to Generate.
        /* translators: %s: waybill number */
        $copy_label = sprintf(__('Copy waybill %s', 'bg-couriers'), $waybill);
        return '<span class="bgc-cell">' . $row1 . '<span class="bgc-row">'
            . '<button type="button" class="bgc-ico bgc-copy" data-wb="' . esc_attr($waybill) . '" data-tip="' . esc_attr($waybill) . '" aria-label="' . esc_attr($copy_label) . '"><span class="dashicons dashicons-admin-page"></span></button>'
            . '<a class="bgc-ico bgc-primary" target="_blank" href="' . esc_url($print_url) . '" data-tip="' . esc_attr__('Print label', 'bg-couriers') . '" aria-label="' . esc_attr__('Print label', 'bg-couriers') . '"><span class="dashicons dashicons-printer"></span></a>'
            . '<a class="bgc-ico" target="_blank" href="' . esc_url($track_url) . '" data-tip="' . esc_attr__('Track shipment', 'bg-couriers') . '" aria-label="' . esc_attr__('Track shipment', 'bg-couriers') . '"><span class="dashicons dashicons-location"></span></a>'
            . '<a href="#" class="bgc-ico bgc-danger bgc-wb-cancel" data-id="' . (int) $order_id . '" data-nonce="' . esc_attr($cancel_nonce) . '" data-gennonce="' . esc_attr($generate_nonce) . '" data-tip="' . esc_attr__('Cancel waybill', 'bg-couriers') . '" aria-label="' . esc_attr__('Cancel waybill', 'bg-couriers') . '"><span class="dashicons dashicons-no-alt"></span></a>'
            . '</span></span>';
    }

    /** Orders-list waybill actions: copy to clipboard + AJAX cancel (custom confirm, no page reload). */
    public function copy_script(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'edit-shop_order'], true)) { return; }
        $msgs = [
            'confirmTitle' => __('Cancel this waybill?', 'bg-couriers'),
            'confirmBody'  => __('This voids the shipment label with the courier. This cannot be undone.', 'bg-couriers'),
            'yes'          => __('Yes, cancel it', 'bg-couriers'),
            'no'           => __('Keep it', 'bg-couriers'),
            'cancelled'    => __('Waybill cancelled', 'bg-couriers'),
            'copied'       => __('Copied to clipboard', 'bg-couriers'),
            'gen'          => __('Generate', 'bg-couriers'),
            'err'          => __('Could not cancel.', 'bg-couriers'),
        ];
        ?>
<style>
/* Two fixed rows (logo+pencil / actions), each wrapping inside the fixed-width Waybill column. */
.bgc-cell{display:flex;flex-direction:column;align-items:flex-start;gap:4px;max-width:100%;}
.bgc-cell .bgc-row{display:flex;flex-wrap:wrap;align-items:center;gap:4px;max-width:100%;}
/* Same tile look as the order-screen shipment panel (.bgc-act), just compact for the list table.
   Geometry is !important: other plugins' admin CSS styles bare <button> elements (the copy tile is
   the one BUTTON among anchors) and was blowing its size/spacing up on some installs. */
.bgc-ico{display:inline-flex!important;align-items:center;justify-content:center;width:26px!important;height:26px!important;min-width:26px!important;min-height:26px!important;padding:0!important;margin:0!important;border:1px solid #c9ced6!important;border-radius:6px!important;background:#fff;color:#2b3440;cursor:pointer;text-decoration:none;box-shadow:none;transition:all .12s;flex:0 0 auto;box-sizing:border-box!important;line-height:1!important;vertical-align:middle;-webkit-appearance:none;appearance:none;}
.bgc-ico:hover{background:#f4f6f9;border-color:#a2acb8!important;box-shadow:0 1px 2px rgba(0,0,0,.07);color:#2b3440;}
.bgc-ico:focus{outline:none;box-shadow:0 0 0 2px rgba(34,113,177,.35);}
.bgc-ico .dashicons{font-size:14px!important;width:14px!important;height:14px!important;line-height:1!important;margin:0!important;padding:0!important;}
/* Courier logo tile (hover hint carries the courier name), like the shipment-panel header. */
.bgc-ltile{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border:1px solid #c9ced6;border-radius:6px;background:#fff;box-sizing:border-box;cursor:default;flex:0 0 auto;}
.bgc-clogo{max-width:17px;max-height:17px;width:auto;height:auto;object-fit:contain;display:block;}
/* data-tip hover bubbles, same as the order-screen shipment panel. */
.bgc-cell [data-tip]{position:relative;}
.bgc-cell [data-tip]:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);white-space:nowrap;background:#1d2327;color:#fff;font-size:11px;font-weight:500;line-height:1;padding:6px 8px;border-radius:6px;pointer-events:none;z-index:30;box-shadow:0 2px 6px rgba(0,0,0,.2);}
.bgc-cell [data-tip]:hover::before{content:"";position:absolute;bottom:calc(100% + 3px);left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#1d2327;z-index:30;}
.bgc-ico.bgc-primary{background:#2271b1;border-color:#2271b1!important;color:#fff;}
.bgc-ico.bgc-primary:hover{background:#1c5d92;border-color:#1c5d92!important;color:#fff;}
.bgc-ico.bgc-danger{color:#b32d2e;border-color:#e6a2a5!important;}
.bgc-ico.bgc-danger:hover{background:#fcecec;border-color:#cf6a6f!important;color:#8a1f2b;}
.bgc-ltoast{position:fixed;z-index:100001;left:50%;bottom:32px;transform:translateX(-50%) translateY(6px);background:#1d2327;color:#fff;font-size:13px;font-weight:500;padding:9px 14px;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.25);opacity:0;transition:opacity .18s,transform .18s;pointer-events:none;}
.bgc-ltoast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.bgc-lmodal-ov{position:fixed;inset:0;background:rgba(20,24,28,.5);z-index:100000;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s;}
.bgc-lmodal-ov.show{opacity:1;}
.bgc-lmodal{background:#fff;border-radius:14px;max-width:400px;width:calc(100% - 40px);padding:22px;box-shadow:0 12px 40px rgba(0,0,0,.3);transform:translateY(8px) scale(.98);transition:transform .15s;}
.bgc-lmodal-ov.show .bgc-lmodal{transform:none;}
.bgc-lmodal h3{margin:0 0 8px;font-size:16px;color:#1d2327;}
.bgc-lmodal p{margin:0 0 18px;color:#50575e;font-size:13px;line-height:1.5;}
.bgc-lmodal-a{display:flex;justify-content:flex-end;gap:10px;}
.bgc-lmodal .button{border-radius:8px;}
.bgc-lmodal .bgc-lyes{background:#b32d2e!important;border-color:#b32d2e!important;color:#fff!important;box-shadow:none!important;}
.bgc-lmodal .bgc-lyes:hover{background:#8a1f2b!important;border-color:#8a1f2b!important;}
</style>
<script>
(function () {
  var M = <?php echo wp_json_encode($msgs); ?>;
  var AP = (typeof ajaxurl === 'string') ? ajaxurl.replace('admin-ajax.php', 'admin-post.php') : '';
  function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
  var tEl;
  function toast(msg) {
    if (!tEl) { tEl = document.createElement('div'); tEl.className = 'bgc-ltoast'; document.body.appendChild(tEl); }
    tEl.textContent = msg; tEl.classList.add('show');
    clearTimeout(toast._t); toast._t = setTimeout(function () { tEl.classList.remove('show'); }, 1500);
  }
  function confirmDlg(o) {
    var ov = document.createElement('div'); ov.className = 'bgc-lmodal-ov';
    ov.innerHTML = '<div class="bgc-lmodal" role="dialog" aria-modal="true"><h3>' + esc(o.title) + '</h3><p>' + esc(o.body) +
      '</p><div class="bgc-lmodal-a"><button type="button" class="button bgc-lno">' + esc(o.no) +
      '</button><button type="button" class="button bgc-lyes">' + esc(o.yes) + '</button></div></div>';
    document.body.appendChild(ov);
    requestAnimationFrame(function () { ov.classList.add('show'); });
    function close() { ov.classList.remove('show'); setTimeout(function () { ov.remove(); }, 160); }
    ov.querySelector('.bgc-lno').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    ov.querySelector('.bgc-lyes').addEventListener('click', function () { close(); if (o.onYes) { o.onYes(); } });
  }
  // Share the dialog + toast so other admin scripts (e.g. the bulk "Cancel waybils" action) reuse them.
  window.bgcConfirmDialog = window.bgcConfirmDialog || confirmDlg;
  window.bgcToast = window.bgcToast || toast;
  function doCancel(x) {
    var id = x.getAttribute('data-id'), nonce = x.getAttribute('data-nonce'), gn = x.getAttribute('data-gennonce');
    var cell = x.closest('.bgc-cell') || x.parentNode;
    x.style.pointerEvents = 'none';
    var body = 'action=bgc_ajax_cancel_label&order_id=' + encodeURIComponent(id) + '&nonce=' + encodeURIComponent(nonce);
    fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.success) {
          var g = AP + '?action=bgc_generate_label&order_id=' + encodeURIComponent(id) + '&_wpnonce=' + encodeURIComponent(gn);
          var gen = '<a class="button button-small bgc-gen" href="' + g + '">' + esc(M.gen) + '</a>';
          var rows = cell.querySelectorAll ? cell.querySelectorAll('.bgc-row') : [];
          if (rows.length > 1) { rows[rows.length - 1].innerHTML = gen; } // row 1 (logo+pencil) stays
          else { cell.innerHTML = gen; }
          toast(M.cancelled);
        } else { x.style.pointerEvents = ''; toast((j && j.data && j.data.msg) || M.err); }
      })
      .catch(function () { x.style.pointerEvents = ''; toast(M.err); });
  }
  document.addEventListener('click', function (e) {
    var c = e.target.closest('.bgc-copy');
    if (c) {
      e.preventDefault(); e.stopPropagation();
      var wb = c.getAttribute('data-wb') || '';
      if (wb && navigator.clipboard) { navigator.clipboard.writeText(wb).then(function () { toast(M.copied); }); }
      return;
    }
    var x = e.target.closest('.bgc-wb-cancel');
    if (!x) { return; }
    e.preventDefault(); e.stopPropagation();
    confirmDlg({ title: M.confirmTitle, body: M.confirmBody, yes: M.yes, no: M.no, onYes: function () { doCancel(x); } });
  });
})();
</script>
        <?php
    }

    public function render($column, $order): void {
        if ($column !== 'bgc_shipping' || !$order) { return; }
        $courier = BGC_Labels::order_courier($order); // any BGC courier, not just Speedy
        if (!$courier) { echo '-'; return; }
        $id = $order->get_id();
        $base = admin_url('admin-post.php');
        $print = wp_nonce_url($base . '?action=bgc_print_batch&order_id=' . $id, 'bgc_print_batch');
        $track = wp_nonce_url($base . '?action=bgc_track&order_id=' . $id, 'bgc_track_' . $id);
        $gen   = wp_nonce_url($base . '?action=bgc_generate_label&order_id=' . $id, 'bgc_generate_label_' . $id);
        // Echoed directly (not wp_kses_post) so the cancel link keeps its data-* attributes; every dynamic
        // field inside cell_html is individually escaped (esc_html / esc_attr / esc_url).
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- cell_html escapes each field internally
        echo self::cell_html(
            (string) $order->get_meta('_bgc_waybill'), $print, $track, $gen, $id,
            wp_create_nonce('bgc_cancel_label_' . $id), wp_create_nonce('bgc_generate_label_' . $id),
            $courier->label(), BGC_Couriers::logo_url($courier->id())
        );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    public function render_legacy($column, $post_id): void {
        if ($column !== 'bgc_shipping') { return; }
        $this->render('bgc_shipping', wc_get_order($post_id));
    }
}
