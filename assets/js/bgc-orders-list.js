/* Orders-list waybill actions: copy, AJAX cancel, re-issue and bulk confirmations. */
(function () {
  var C = window.BGCOURIERS_LIST || {};
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
  window.bgcConfirmDialog = window.bgcConfirmDialog || confirmDlg;
  window.bgcToast = window.bgcToast || toast;
  function doCancel(x) {
    var id = x.getAttribute('data-id'), nonce = x.getAttribute('data-nonce'), gn = x.getAttribute('data-gennonce');
    var cell = x.closest('.bgc-cell') || x.parentNode;
    x.style.pointerEvents = 'none';
    var body = 'action=bgcouriers_ajax_cancel_label&order_id=' + encodeURIComponent(id) + '&nonce=' + encodeURIComponent(nonce);
    fetch(ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.success) {
          var g = AP + '?action=bgcouriers_generate_label&order_id=' + encodeURIComponent(id) + '&_wpnonce=' + encodeURIComponent(gn);
          var gen = '<a class="button button-small bgc-gen" href="' + g + '">' + esc(M.gen) + '</a>';
          var rows = cell.querySelectorAll ? cell.querySelectorAll('.bgc-row') : [];
          if (rows.length > 1) { rows[rows.length - 1].innerHTML = gen; }
          else { cell.innerHTML = gen; }
          var rg = cell.querySelector ? cell.querySelector('.bgc-regen') : null;
          if (rg) { rg.remove(); }
          toast(M.cancelled);
        } else { x.style.pointerEvents = ''; toast((j && j.data && j.data.msg) || M.err); }
      })
      .catch(function () { x.style.pointerEvents = ''; toast(M.err); });
  }
  // The list never reloads after a print, so the tile turns plain here; the server clears the flag itself.
  function printed(tile) {
    if (!tile) { return; }
    tile.classList.remove('bgc-unprinted');
    if (M.print) { tile.setAttribute('data-tip', M.print); tile.setAttribute('aria-label', M.print); }
  }
  function tileOf(orderId) {
    return document.querySelector('.bgc-primary.bgc-unprinted[href*="order_id=' + orderId + '&"]');
  }
  document.addEventListener('click', function (e) {
    var p = e.target.closest('.bgc-primary.bgc-unprinted');
    if (p) { printed(p); return; }
    var b = e.target.closest('a[data-ids]');
    if (b) { (b.getAttribute('data-ids') || '').split(',').forEach(function (id) { printed(tileOf(id)); }); return; }
    var c = e.target.closest('.bgc-copy');
    if (c) {
      e.preventDefault(); e.stopPropagation();
      var wb = c.getAttribute('data-wb') || '';
      if (wb && navigator.clipboard) { navigator.clipboard.writeText(wb).then(function () { toast(M.copied); }); }
      return;
    }
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

  // WP builds the bulk <select> flat, so the optgroup is assembled client-side; option values stay the same.
  (function () {
    var G = C.group || {}, vals = G.actions || [];
    if (!vals.length || !G.label) { return; }
    function group() {
      var sels = document.querySelectorAll('.bulkactions select');
      for (var i = 0; i < sels.length; i++) {
        var sel = sels[i];
        if (sel.querySelector('optgroup.bgc-optgroup')) { continue; }
        // Snapshot before moving: the option collection is live.
        var mine = Array.prototype.filter.call(sel.querySelectorAll('option'), function (o) {
          return vals.indexOf(o.value) !== -1;
        });
        if (!mine.length) { continue; }
        var og = document.createElement('optgroup');
        og.className = 'bgc-optgroup';
        og.setAttribute('label', G.label);
        for (var k = 0; k < mine.length; k++) { og.appendChild(mine[k]); }
        sel.appendChild(og);
      }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', group); }
    else { group(); }
  })();

  // Bulk actions listed in confirmBulk submit only after the merchant confirms.
  (function () {
    var MAP = C.confirmBulk || {}, going = false, printing = false;
    window.addEventListener('pageshow', function () { printing = false; });
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('#doaction, #doaction2');
      if (!btn || going) { return; }
      var sel = document.getElementById(btn.id === 'doaction' ? 'bulk-action-selector-top' : 'bulk-action-selector-bottom');
      // Bulk print streams into this tab; on Back the cached list must already show the tiles plain.
      if (sel && (C.printActions || []).indexOf(sel.value) !== -1) {
        // A second submit while the first is still issuing waybills only replaces the tab's answer.
        if (printing) { e.preventDefault(); e.stopPropagation(); return; }
        printing = true;
        document.querySelectorAll('tbody .check-column input:checked').forEach(function (cb) {
          var row = cb.closest('tr');
          if (row) { printed(row.querySelector('.bgc-primary.bgc-unprinted')); }
        });
        return;
      }
      var d = sel ? MAP[sel.value] : null;
      if (!d) { return; }
      e.preventDefault(); e.stopPropagation();
      var run = function () { going = true; btn.click(); };
      confirmDlg({ title: d.title, body: d.body, yes: d.yes, no: d.no, onYes: run });
    }, true);
  })();
})();
