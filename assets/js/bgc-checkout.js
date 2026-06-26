(function ($) {
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function sel2($el, opts) { return ($.fn.selectWoo ? $el.selectWoo(opts) : $el.select2(opts)); }

  function courier($wrap) { return $wrap.attr('data-courier') || 'speedy'; }
  function caps($wrap) {
    var enabled = ($wrap.attr('data-methods') || 'office,address,automat').split(',');
    var order   = ($wrap.attr('data-order')   || 'office,address,automat').split(',');
    return order.filter(function (t) { return enabled.indexOf(t) !== -1; });
  }
  function method($wrap) { return $wrap.attr('data-method') || caps($wrap)[0] || 'office'; }
  function showLoader($wrap) { $wrap.addClass('bgc-loading'); }
  function hideLoader($wrap) { $wrap.removeClass('bgc-loading'); }
  function officeLabel(m) { return m === 'automat' ? (BGC.i18n.automat_label || 'Automat') : (BGC.i18n.office_label || 'Office'); }

  // Delivery-type tabs ------------------------------------------------------
  function renderTabs($wrap) {
    var types = caps($wrap);
    if (!$wrap.attr('data-method')) { $wrap.attr('data-method', types[0]); }
    var sel = method($wrap);
    if ($wrap.find('.bgc-tab').length) { syncMethodUI($wrap); return; }
    var html = types.map(function (t) {
      return '<button type="button" class="bgc-tab' + (t === sel ? ' active' : '') + '" data-method="' + t + '">' + esc(BGC.i18n[t]) + '</button>';
    }).join('');
    $wrap.find('.bgc-tabs').html(html);
  }

  // Keep the visible panel (tabs / office label / address rows) in sync with the method.
  function syncMethodUI($wrap) {
    var m = method($wrap), isAddr = m === 'address';
    $wrap.find('.bgc-tab').each(function () { $(this).toggleClass('active', $(this).data('method') === m); });
    $wrap.find('.bgc-address-rows').toggle(isAddr);
    $wrap.find('.bgc-office-row').toggle(!isAddr);
    if (!isAddr) { $wrap.find('.bgc-office-label').text(officeLabel(m)); }
  }

  function resetOffice($wrap) {
    var $o = $wrap.find('.bgc-office');
    if ($o.hasClass('select2-hidden-accessible')) { $o.val(null).trigger('change.select2'); }
    $o.empty();
  }

  function setMethod($wrap, m) {
    $wrap.attr('data-method', m);
    syncMethodUI($wrap);
    if (m !== 'address') { resetOffice($wrap); }
    showLoader($wrap);
    pushSelection($wrap); // saves method + recalc; loader cleared on updated_checkout
  }

  // City (searchable, server-limited) --------------------------------------
  function initCity($wrap) {
    var $city = $wrap.find('.bgc-city');
    if ($city.hasClass('select2-hidden-accessible')) { return; }
    sel2($city, {
      width: '100%', allowClear: true, placeholder: (BGC.i18n && BGC.i18n.city_ph) || '', minimumInputLength: 0,
      ajax: {
        url: BGC.ajax, dataType: 'json', delay: 250,
        data: function (params) { return { action: 'bgc_search_cities', courier: courier($wrap), term: params.term || '' }; },
        processResults: function (rows) {
          var counts = {};
          rows.forEach(function (r) { counts[r.name] = (counts[r.name] || 0) + 1; });
          return { results: rows.map(function (r) {
            var text = r.name;
            if (counts[r.name] > 1) { text += ' — ' + (r.region || r.post_code || ''); }
            return { id: r.city_id, text: text, post_code: r.post_code };
          }) };
        }
      }
    });
    $city.on('select2:select', function (e) {
      var d = e.params && e.params.data; if (d && d.post_code) { $wrap.find('.bgc-postcode').val(d.post_code); }
      resetOffice($wrap); showLoader($wrap); pushSelection($wrap);
    });
  }

  // Office / automat (searchable, live per-city, server-limited) ------------
  function initOffice($wrap) {
    var $office = $wrap.find('.bgc-office');
    if ($office.hasClass('select2-hidden-accessible')) { return; }
    sel2($office, {
      width: '100%', minimumInputLength: 0, placeholder: (BGC.i18n && BGC.i18n.office_ph) || '',
      ajax: {
        url: BGC.ajax, dataType: 'json', delay: 250,
        data: function (params) {
          return { action: 'bgc_offices', courier: courier($wrap), city_id: $wrap.find('.bgc-city').val() || 0, type: method($wrap), term: params.term || '' };
        },
        processResults: function (rows) {
          return { results: rows.map(function (o) { return { id: o.office_id, text: o.name + ' — ' + o.address }; }) };
        }
      }
    });
    $office.on('select2:select', function () { pushSelection($wrap); });
  }

  // Street (autocomplete via /location/street; tags:true keeps free-typed streets working).
  function initStreet($wrap) {
    var $street = $wrap.find('.bgc-street');
    if (!$street.length || $street[0].tagName !== 'SELECT' || $street.hasClass('select2-hidden-accessible')) { return; }
    sel2($street, {
      width: '100%', tags: true, minimumInputLength: 2, placeholder: (BGC.i18n && BGC.i18n.street_ph) || '',
      ajax: {
        url: BGC.ajax, dataType: 'json', delay: 250,
        data: function (params) { return { action: 'bgc_streets', courier: courier($wrap), city_id: $wrap.find('.bgc-city').val() || 0, term: params.term || '' }; },
        processResults: function (rows) { return { results: rows.map(function (s) { return { id: s.name, text: s.label || s.name }; }) }; }
      },
      createTag: function (params) { var t = (params.term || '').trim(); return t ? { id: t, text: t } : null; }
    });
    $street.on('select2:select', function () { saveSelection($wrap); });
  }

  // Save the selection ------------------------------------------------------
  function selectionData($wrap) {
    return {
      action: 'bgc_set_selection', nonce: BGC.nonce, courier: courier($wrap), method: method($wrap),
      site_id: $wrap.find('.bgc-city').val() || 0,
      office_id: $wrap.find('.bgc-office').val() || 0,
      post_code: $wrap.find('.bgc-postcode').val() || '',
      street_name: $wrap.find('.bgc-street').val() || '', street_no: $wrap.find('.bgc-street-no').val() || '',
      complex: $wrap.find('.bgc-complex').val() || '', block: $wrap.find('.bgc-block').val() || '',
      entrance: $wrap.find('.bgc-entrance').val() || '', floor: $wrap.find('.bgc-floor').val() || '',
      apartment: $wrap.find('.bgc-apartment').val() || '', address_note: $wrap.find('.bgc-note').val() || ''
    };
  }
  function pushSelection($wrap) { $.post(BGC.ajax, selectionData($wrap), function () { $(document.body).trigger('update_checkout'); }); }
  function saveSelection($wrap) { $.post(BGC.ajax, selectionData($wrap)); } // save without recalc (address details don't change price)

  // Wiring ------------------------------------------------------------------
  $(document.body).on('updated_checkout', function () {
    var $wrap = $('.bgc-fields'); if (!$wrap.length) return;
    renderTabs($wrap); initCity($wrap); initOffice($wrap); initStreet($wrap); syncMethodUI($wrap); hideLoader($wrap);
  });

  $(document.body).on('click', '.bgc-tab', function (e) {
    e.preventDefault();
    setMethod($(this).closest('.bgc-fields'), $(this).data('method'));
  });

  $(document.body).on('input', '.bgc-postcode', function () {
    var $wrap = $(this).closest('.bgc-fields'), code = this.value.trim(); if (code.length < 4) { return; }
    $.get(BGC.ajax, { action: 'bgc_search_cities', courier: courier($wrap), term: code }, function (rows) {
      if (rows && rows.length === 1) {
        var $city = $wrap.find('.bgc-city'); var r = rows[0];
        $city.append(new Option(r.name, r.city_id, true, true)).trigger('change');
        resetOffice($wrap); showLoader($wrap); pushSelection($wrap);
      }
    });
  });

  var addrT;
  $(document.body).on('input', '.bgc-address-rows input', function () {
    var $wrap = $(this).closest('.bgc-fields');
    clearTimeout(addrT); addrT = setTimeout(function () { saveSelection($wrap); }, 600);
  });

  // Emergency help: after repeated checkout failures, show a one-time help box with a phone link.
  (function () {
    var e = (window.BGC && BGC.emergency) || {};
    if (!e.phone) { return; }
    var THRESH = 2, SHOWN = 'bgc_emerg_shown', CNT = 'bgc_fail_count';
    $(document.body).on('checkout_error', function () {
      try { if (localStorage.getItem(SHOWN)) { return; } } catch (x) {}
      var n = (parseInt(sessionStorage.getItem(CNT) || '0', 10) || 0) + 1;
      try { sessionStorage.setItem(CNT, n); } catch (x) {}
      if (n >= THRESH) { showEmergency(); try { localStorage.setItem(SHOWN, '1'); } catch (x) {} }
    });
    function showEmergency() {
      if ($('#bgc-emergency').length) { return; }
      var msg = e.message || (BGC.i18n && BGC.i18n.emerg_default) || '';
      var tel = String(e.phone).replace(/[^\d+]/g, '');
      $('body').append(
        '<div id="bgc-emergency" class="bgc-emerg-overlay"><div class="bgc-emerg-box">' +
        '<button type="button" class="bgc-emerg-close" aria-label="' + esc(BGC.i18n && BGC.i18n.close) + '">×</button>' +
        '<p class="bgc-emerg-msg">' + esc(msg) + '</p>' +
        '<a class="bgc-emerg-tel" href="tel:' + esc(tel) + '">' + esc(e.phone) + '</a>' +
        '</div></div>'
      );
      $('#bgc-emergency').on('click', function (ev) { if (ev.target === this) { $(this).remove(); } });
      $('#bgc-emergency .bgc-emerg-close').on('click', function () { $('#bgc-emergency').remove(); });
    }
  })();
})(jQuery);
