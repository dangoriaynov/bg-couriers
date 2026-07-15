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

    public static function cell_html(string $waybill, string $print_url, string $track_url, string $generate_url, int $order_id = 0, string $cancel_nonce = '', string $generate_nonce = ''): string {
        if ($waybill === '') {
            return '<a class="button button-small" href="' . esc_url($generate_url) . '">' . esc_html__('Generate', 'bg-couriers') . '</a>';
        }
        // The copy link reads the waybill from the adjacent .bgc-wb-num. The cancel link carries the order
        // id + a cancel nonce + a generate nonce (NOT the generate URL, so a labelled row still shows no
        // Generate button); JS voids the waybill over AJAX and swaps the cell to a fresh Generate button.
        // Row 1: the waybill number (click it to copy). Row 2: one uniform row of icon actions - copy,
        // print, track, cancel - all the same size, matching the order-edit panel's dashicons.
        return '<span class="bgc-cell">'
            . '<div class="bgc-wb-num bgc-copy" title="' . esc_attr__('Click to copy', 'bg-couriers') . '">' . esc_html($waybill) . '</div>'
            . '<div class="bgc-wb-row">'
            . '<a class="bgc-ico" target="_blank" href="' . esc_url($print_url) . '" title="' . esc_attr__('Print label', 'bg-couriers') . '" aria-label="' . esc_attr__('Print label', 'bg-couriers') . '"><span class="dashicons dashicons-printer"></span></a>'
            . '<a class="bgc-ico" target="_blank" href="' . esc_url($track_url) . '" title="' . esc_attr__('Track shipment', 'bg-couriers') . '" aria-label="' . esc_attr__('Track shipment', 'bg-couriers') . '"><span class="dashicons dashicons-location"></span></a>'
            . '<a href="#" class="bgc-ico bgc-danger bgc-wb-cancel" data-id="' . (int) $order_id . '" data-nonce="' . esc_attr($cancel_nonce) . '" data-gennonce="' . esc_attr($generate_nonce) . '" title="' . esc_attr__('Cancel waybill', 'bg-couriers') . '" aria-label="' . esc_attr__('Cancel waybill', 'bg-couriers') . '"><span class="dashicons dashicons-no-alt"></span></a>'
            . '</div>'
            . '</span>';
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
.bgc-cell .bgc-wb-num{font-weight:700;cursor:pointer;margin-bottom:3px;display:inline-block;}
.bgc-cell .bgc-wb-row{display:flex;align-items:center;gap:9px;}
.bgc-cell .bgc-ico{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;color:#2271b1;text-decoration:none;cursor:pointer;}
.bgc-cell .bgc-ico .dashicons{font-size:18px;width:18px;height:18px;line-height:1;}
.bgc-cell .bgc-ico:hover{color:#135e96;}
.bgc-cell .bgc-ico.bgc-danger{color:#b32d2e;}
.bgc-cell .bgc-ico.bgc-danger:hover{color:#8a1f2b;}
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
          cell.innerHTML = '<a class="button button-small" href="' + g + '">' + esc(M.gen) + '</a>';
          toast(M.cancelled);
        } else { x.style.pointerEvents = ''; toast((j && j.data && j.data.msg) || M.err); }
      })
      .catch(function () { x.style.pointerEvents = ''; toast(M.err); });
  }
  document.addEventListener('click', function (e) {
    var c = e.target.closest('.bgc-copy');
    if (c) {
      e.preventDefault(); e.stopPropagation();
      var cell = c.closest('.bgc-cell') || document;
      var num = cell.querySelector('.bgc-wb-num'); var wb = num ? num.textContent.trim() : '';
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
        if (!BGC_Labels::order_courier($order)) { echo '-'; return; } // any BGC courier, not just Speedy
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
            wp_create_nonce('bgc_cancel_label_' . $id), wp_create_nonce('bgc_generate_label_' . $id)
        );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    public function render_legacy($column, $post_id): void {
        if ($column !== 'bgc_shipping') { return; }
        $this->render('bgc_shipping', wc_get_order($post_id));
    }
}
