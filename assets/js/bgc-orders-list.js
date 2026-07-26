/* Orders-list waybill actions: copy to clipboard + AJAX cancel (custom confirm, no page reload),
   plus the bulk "Cancel waybils" confirmation. Config/i18n come from BGC_LIST (wp_localize_script). */
(function () {
  var C = window.BGC_LIST || {};
  var M = C.i18n || {};
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
  // Share the dialog + toast so other admin scripts can reuse them.
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
          // Re-issue lives in row 1 and only makes sense with a waybill - drop it now that there is none.
          var rg = cell.querySelector ? cell.querySelector('.bgc-regen') : null;
          if (rg) { rg.remove(); }
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
    // Re-issue: confirm, then follow the admin-post link (voids the waybill and issues a fresh one).
    var r = e.target.closest('.bgc-regen');
    if (r) {
      e.preventDefault(); e.stopPropagation();
      var href = r.getAttribute('href') || '';
      if (!href) { return; }
      confirmDlg({ title: M.regenTitle, body: M.regenBody, yes: M.regenYes, no: M.no, onYes: function () { window.location.href = href; } });
      return;
    }
    var x = e.target.closest('.bgc-wb-cancel');
    if (!x) { return; }
    e.preventDefault(); e.stopPropagation();
    confirmDlg({ title: M.confirmTitle, body: M.confirmBody, yes: M.yes, no: M.no, onYes: function () { doCancel(x); } });
  });

  // Gather our four bulk actions under one labelled <optgroup>, so they read as a section of the
  // dropdown instead of four loose entries among WooCommerce's. WordPress builds that <select> from
  // the bulk_actions-* filter, which is a flat value => label map with no way to express a group, so
  // the optgroup has to be assembled client-side - the same approach Print Invoices/Packing Lists
  // takes. Only the nesting changes: the option values stay identical, so the bulk-cancel confirm
  // below (and WP's own submit handling) keep matching on select.value.
  (function () {
    var G = C.group || {}, vals = G.actions || [];
    if (!vals.length || !G.label) { return; }
    function group() {
      var sels = document.querySelectorAll('.bulkactions select'); // top AND bottom selectors
      for (var i = 0; i < sels.length; i++) {
        var sel = sels[i];
        if (sel.querySelector('optgroup.bgc-optgroup')) { continue; } // already grouped
        // Snapshot before moving anything - sel.options is live. DOM order == registration order.
        var mine = Array.prototype.filter.call(sel.querySelectorAll('option'), function (o) {
          return vals.indexOf(o.value) !== -1;
        });
        if (!mine.length) { continue; }
        var og = document.createElement('optgroup');
        og.className = 'bgc-optgroup';
        og.setAttribute('label', G.label);
        for (var k = 0; k < mine.length; k++) { og.appendChild(mine[k]); } // appendChild MOVES the option
        sel.appendChild(og);
      }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', group); }
    else { group(); }
  })();

  // Bulk actions that void live shipments (Cancel / Re-issue): intercept the Apply click and only
  // submit once the merchant confirms. Driven by BGC_LIST.confirmBulk, keyed by action value - an
  // action that is not listed there submits normally, so adding one here is a PHP-side change.
  (function () {
    var MAP = C.confirmBulk || {}, going = false;
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('#doaction, #doaction2');
      if (!btn || going) { return; }
      var sel = document.getElementById(btn.id === 'doaction' ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom');
      var d = sel ? MAP[sel.value] : null;
      if (!d) { return; }
      e.preventDefault(); e.stopPropagation();
      var run = function () { going = true; btn.click(); };
      confirmDlg({ title: d.title, body: d.body, yes: d.yes, no: d.no, onYes: run });
    }, true);
  })();
})();
