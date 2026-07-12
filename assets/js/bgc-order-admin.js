/* On-order delivery editor (WooCommerce order edit screen). Reuses the checkout nomenclature AJAX. */
(function ($) {
  // Copy-the-waybill button (bound first so it works even where the editor isn't rendered).
  $(document).on('click', '.bgc-wb-copy', function (e) {
    e.preventDefault();
    var $b = $(this), wb = String($b.data('wb') || '');
    var ok = function () { $b.addClass('done'); $b.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
      setTimeout(function () { $b.removeClass('done'); $b.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard'); }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(wb).then(ok, function () {}); }
    else { var $t = $('<input>').val(wb).appendTo('body'); $t[0].select(); try { document.execCommand('copy'); ok(); } catch (x) {} $t.remove(); }
  });

  var C = window.BGC_ED || {};
  var $panel = $('.bgc-ed');
  if (!$panel.length) { return; }
  var $form = $panel.find('.bgc-ed-form');
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

  sel2($city, { width: '100%', allowClear: true, placeholder: C.i18n.city, minimumInputLength: 0,
    ajax: { url: C.ajax, dataType: 'json', delay: 250,
      data: function (params) { return { action: 'bgc_search_cities', courier: courier(), term: params.term || '' }; },
      processResults: function (rows) { return { results: (rows || []).map(function (r) {
        return { id: r.city_id, text: r.name + (r.post_code ? ' (' + r.post_code + ')' : ''), post_code: r.post_code }; }) }; }
    }
  });
  $city.on('select2:select', function (e) { var d = e.params.data; if (d && d.post_code) { $panel.find('.bgc-ed-postcode').val(d.post_code); } });

  // Office/APS: preload the city's offices into the <select> (robust - works even if select2 isn't
  // enhancing, and doesn't depend on select2 firing an AJAX on open). select2 then just adds local search.
  sel2($office, { width: '100%', allowClear: true, placeholder: C.i18n.office });
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

  sel2($street, { width: '100%', allowClear: true, tags: true, placeholder: C.i18n.street, minimumInputLength: 0,
    ajax: { url: C.ajax, dataType: 'json', delay: 250,
      data: function (params) { return { action: 'bgc_streets', courier: courier(), city_id: $city.val() || 0, term: params.term || '' }; },
      processResults: function (rows) { return { results: (rows || []).map(function (s) { return { id: s.name, text: s.name }; }) }; }
    }
  });

  $panel.on('click', '.bgc-ed-toggle', function (e) { e.preventDefault(); $form.toggle(); });
  $city.on('change', function () { resetOffice(); resetStreet(); loadOffices(); });
  $courier.on('change', function () { fillMethods(); resetOffice(); resetStreet(); loadOffices(); });
  $method.on('change', function () { updateMode(); resetOffice(); loadOffices(); });
  fillMethods();
  loadOffices(); // populate the office/APS list for the order's current city + method

  $panel.on('click', '.bgc-ed-save', function (e) {
    e.preventDefault();
    var $b = $(this), $msg = $panel.find('.bgc-ed-msg');
    $b.prop('disabled', true); $msg.text('').css('color', '#50575e').text(C.i18n.saving);
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
      else { $msg.css('color', '#b32d2e').text((r && r.data && r.data.msg) || C.i18n.err); $b.prop('disabled', false); }
    }).fail(function () { $msg.css('color', '#b32d2e').text(C.i18n.err); $b.prop('disabled', false); });
  });
})(jQuery);
