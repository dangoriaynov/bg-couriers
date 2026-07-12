/* On-order delivery editor + shipment-panel actions (WooCommerce order edit screen). */
(function ($) {
  var C = window.BGC_ED || {};
  var I = C.i18n || {};

  // --- small UI helpers: toast + custom confirm dialog ------------------------------------------
  var $toast;
  function toast(msg) {
    if (!$toast) { $toast = $('<div class="bgc-toast"></div>').appendTo('body'); }
    $toast.stop(true, true).text(msg);
    // position near the last click, falling back to bottom-centre
    var e = toast._e;
    if (e) { $toast.css({ left: Math.min(e.clientX + 12, window.innerWidth - 180) + 'px', top: (e.clientY + 14) + 'px', right: 'auto', bottom: 'auto' }); }
    else { $toast.css({ left: '50%', bottom: '32px', top: 'auto', right: 'auto', transform: 'translateX(-50%)' }); }
    $toast.addClass('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { $toast.removeClass('show'); }, 1400);
  }
  $(document).on('mousedown', '.bgc-copy,.bgc-cancel', function (e) { toast._e = e; });

  function confirmDialog(opts) {
    var $ov = $('<div class="bgc-modal-ov"></div>');
    var $m = $(
      '<div class="bgc-modal" role="dialog" aria-modal="true">' +
      '<h3><span class="dashicons dashicons-warning"></span><span class="bgc-m-title"></span></h3>' +
      '<p class="bgc-m-body"></p>' +
      '<div class="bgc-modal-actions">' +
      '<button type="button" class="button bgc-m-no"></button>' +
      '<button type="button" class="button bgc-btn-danger bgc-m-yes"></button>' +
      '</div></div>'
    );
    $m.find('.bgc-m-title').text(opts.title || 'Are you sure?');
    $m.find('.bgc-m-body').text(opts.body || '');
    $m.find('.bgc-m-yes').text(opts.yes || 'Confirm');
    $m.find('.bgc-m-no').text(opts.no || 'Cancel');
    $ov.append($m).appendTo('body');
    // eslint-disable-next-line no-unused-expressions
    $ov[0].offsetWidth; // force reflow so the transition runs
    $ov.addClass('show');
    function close() { $ov.removeClass('show'); setTimeout(function () { $ov.remove(); }, 180); }
    $m.find('.bgc-m-no').on('click', close);
    $ov.on('click', function (e) { if (e.target === $ov[0]) { close(); } });
    $(document).on('keydown.bgcmodal', function (e) { if (e.key === 'Escape') { close(); $(document).off('keydown.bgcmodal'); } });
    $m.find('.bgc-m-yes').on('click', function () { close(); $(document).off('keydown.bgcmodal'); if (opts.onYes) { opts.onYes(); } });
    $m.find('.bgc-m-yes').focus();
  }

  // --- copy the waybill number ------------------------------------------------------------------
  $(document).on('click', '.bgc-copy', function (e) {
    e.preventDefault();
    var $b = $(this), wb = String($b.data('wb') || '');
    var ok = function () { $b.addClass('done'); toast(I.copied || 'Copied to clipboard'); setTimeout(function () { $b.removeClass('done'); }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(wb).then(ok, function () {}); }
    else { var $t = $('<input>').val(wb).appendTo('body'); $t[0].select(); try { document.execCommand('copy'); ok(); } catch (x) {} $t.remove(); }
  });

  // --- cancel (void) the waybill, behind a custom confirmation ----------------------------------
  $(document).on('click', '.bgc-cancel', function (e) {
    e.preventDefault();
    var url = String($(this).data('cancel-url') || '');
    if (!url) { return; }
    confirmDialog({
      title: I.cancelTitle || 'Cancel this waybill?',
      body: I.cancelBody || 'This voids the shipment label with the courier.',
      yes: I.cancelYes || 'Yes, cancel it',
      no: I.cancelNo || 'Keep it',
      onYes: function () { window.location.href = url; }
    });
  });

  // --- toggle the delivery-details editor (icon lives in the panel, form lives in .bgc-ed) -------
  $(document).on('click', '.bgc-ed-toggle', function (e) {
    e.preventDefault();
    var $form = $('.bgc-ed-form');
    $form.toggle();
    if ($form.is(':visible')) { $form[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  });

  // --- the editor itself ------------------------------------------------------------------------
  var $panel = $('.bgc-ed');
  if (!$panel.length) { return; }
  function sel2($el, opts) { if ($.fn.selectWoo) { $el.selectWoo(opts); } else if ($.fn.select2) { $el.select2(opts); } }

  var $courier = $panel.find('.bgc-ed-courier'),
      $method  = $panel.find('.bgc-ed-method'),
      $city    = $panel.find('.bgc-ed-city'),
      $office  = $panel.find('.bgc-ed-office'),
      $street  = $panel.find('.bgc-ed-street');

  function courier() { return $courier.val(); }
  function resetOffice() { $office.val(null).trigger('change'); }
  function resetStreet() { $street.val(null).trigger('change'); }

  function fillMethods() {
    var c = courier(), cur = $method.val() || $method.data('current'), ms = (C.caps[c] || []);
    $method.empty();
    ms.forEach(function (m) { $method.append('<option value="' + m + '">' + (C.methodLabels[m] || m) + '</option>'); });
    if (ms.indexOf(cur) >= 0) { $method.val(cur); }
    updateMode();
  }
  function updateMode() {
    var isBox = (courier() === 'boxnow'), m = $method.val();
    $panel.find('.bgc-ed-boxnow').toggle(isBox);
    $panel.find('.bgc-ed-city-row').toggle(!isBox);
    $panel.find('.bgc-ed-office-row').toggle(!isBox && m !== 'address');
    $panel.find('.bgc-ed-address').toggle(!isBox && m === 'address');
  }

  sel2($city, { width: '100%', allowClear: true, placeholder: I.city, minimumInputLength: 0,
    ajax: { url: C.ajax, dataType: 'json', delay: 250,
      data: function (params) { return { action: 'bgc_search_cities', courier: courier(), term: params.term || '' }; },
      processResults: function (rows) { return { results: (rows || []).map(function (r) {
        return { id: r.city_id, text: r.name + (r.post_code ? ' (' + r.post_code + ')' : ''), post_code: r.post_code }; }) }; }
    }
  });
  $city.on('select2:select', function (e) { var d = e.params.data; if (d && d.post_code) { $panel.find('.bgc-ed-postcode').val(d.post_code); } });

  // Office/APS: preload the city's offices into the <select> (robust - works even if select2 isn't
  // enhancing, and doesn't depend on select2 firing an AJAX on open). select2 then just adds local search.
  sel2($office, { width: '100%', allowClear: true, placeholder: I.office });
  function loadOffices() {
    var c = courier(), city = $city.val() || 0, m = $method.val();
    if (!city || m === 'address' || c === 'boxnow') { return; }
    $.get(C.ajax, { action: 'bgc_offices', courier: c, city_id: city, type: m, all: 1 }, function (rows) {
      var cur = $office.val();
      $office.empty().append('<option></option>');
      (rows || []).forEach(function (o) {
        $office.append($('<option>').val(o.office_id || o.id).text((o.name || '') + (o.address ? ' - ' + o.address : '')));
      });
      if (cur && $office.find('option[value="' + cur + '"]').length) { $office.val(cur); }
      $office.trigger('change');
    }, 'json');
  }

  sel2($street, { width: '100%', allowClear: true, tags: true, placeholder: I.street, minimumInputLength: 0,
    ajax: { url: C.ajax, dataType: 'json', delay: 250,
      data: function (params) { return { action: 'bgc_streets', courier: courier(), city_id: $city.val() || 0, term: params.term || '' }; },
      processResults: function (rows) { return { results: (rows || []).map(function (s) { return { id: s.name, text: s.name }; }) }; }
    }
  });

  // The selected city's id belongs to the PREVIOUS courier's nomenclature; when the courier changes we
  // re-look-up the same city name in the new courier's list so its offices/APS resolve (otherwise the
  // office select shows "No results found" - e.g. Econt Sofia id 68134 is not Speedy's Sofia).
  function cityName() {
    var t = ($city.find('option:selected').text() || '').trim();
    return t.replace(/\s*\(\d+\)\s*$/, ''); // drop the " (1000)" postcode suffix
  }
  function reResolveCity() {
    var name = cityName();
    if (!name) { loadOffices(); return; }
    $.get(C.ajax, { action: 'bgc_search_cities', courier: courier(), term: name }, function (rows) {
      rows = rows || [];
      var lc = name.toLowerCase();
      var m = null, i;
      for (i = 0; i < rows.length; i++) { if ((rows[i].name || '').toLowerCase() === lc) { m = rows[i]; break; } }
      if (!m) { m = rows[0]; }
      if (m) {
        var text = m.name + (m.post_code ? ' (' + m.post_code + ')' : '');
        $city.empty().append(new Option(text, m.city_id, true, true)).trigger('change.select2');
        if (m.post_code) { $panel.find('.bgc-ed-postcode').val(m.post_code); }
      } else {
        $city.val(null).trigger('change.select2');
      }
      loadOffices();
    }, 'json');
  }

  $city.on('change', function () { resetOffice(); resetStreet(); loadOffices(); });
  $courier.on('change', function () { fillMethods(); resetOffice(); resetStreet(); reResolveCity(); });
  $method.on('change', function () { updateMode(); resetOffice(); loadOffices(); });
  fillMethods();
  loadOffices(); // populate the office/APS list for the order's current city + method

  $panel.on('click', '.bgc-ed-save', function (e) {
    e.preventDefault();
    var $b = $(this), $msg = $panel.find('.bgc-ed-msg');
    $b.prop('disabled', true); $msg.text('').css('color', '#50575e').text(I.saving);
    var data = {
      action: 'bgc_order_save_delivery', nonce: C.nonce, order_id: C.orderId,
      courier: courier(), method: $method.val(),
      site_id: $city.val() || 0, office_id: $office.val() || 0, post_code: $panel.find('.bgc-ed-postcode').val() || '',
      street_name: $street.val() || '', street_no: $panel.find('.bgc-ed-streetno').val() || '',
      complex: $panel.find('.bgc-ed-complex').val() || '', block: $panel.find('.bgc-ed-block').val() || '',
      entrance: $panel.find('.bgc-ed-entrance').val() || '', floor: $panel.find('.bgc-ed-floor').val() || '',
      apartment: $panel.find('.bgc-ed-apartment').val() || '', address_note: $panel.find('.bgc-ed-note').val() || '',
      boxnow_name: $panel.find('.bgc-ed-boxnow-name').val() || '', boxnow_addr: $panel.find('.bgc-ed-boxnow-addr').val() || ''
    };
    if (courier() === 'boxnow') { data.office_id = $panel.find('.bgc-ed-boxnow-id').val() || 0; }
    $.post(C.ajax, data).done(function (r) {
      if (r && r.success) { $msg.css('color', '#1a7f37').text((r.data && r.data.msg) || 'Saved'); setTimeout(function () { location.reload(); }, 800); }
      else { $msg.css('color', '#b32d2e').text((r && r.data && r.data.msg) || I.err); $b.prop('disabled', false); }
    }).fail(function () { $msg.css('color', '#b32d2e').text(I.err); $b.prop('disabled', false); });
  });
})(jQuery);
