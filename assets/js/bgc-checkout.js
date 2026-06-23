(function ($) {
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function caps() {
    // Enabled methods, in the configured order (defaults to office/address/automat).
    var enabled = (BGC && BGC.methods && BGC.methods.length) ? BGC.methods : ['office', 'address', 'automat'];
    var order = (BGC && BGC.order && BGC.order.length) ? BGC.order : ['office', 'address', 'automat'];
    return order.filter(function (t) { return enabled.indexOf(t) !== -1; });
  }
  function renderTypes($wrap) {
    if ($wrap.find('.bgc-types input').length) return;
    var types = caps();
    var html = types.map(function (t, i) {
      return '<label><input type="radio" name="bgc_method" value="' + t + '"' + (i === 0 ? ' checked' : '') + '> ' + esc(BGC.i18n[t]) + '</label>';
    }).join(' ');
    $wrap.find('.bgc-types').html(html);
  }
  function searchCities(term, cb) {
    $.get(BGC.ajax, { action: 'bgc_search_cities', courier: 'speedy', term: term }, cb);
  }
  function loadOffices($wrap) {
    var cityId = $wrap.find('.bgc-city-id').val();
    var type = $wrap.find('input[name=bgc_method]:checked').val();
    if (!cityId || type === 'address') { $wrap.find('.bgc-office-row').hide(); return; }
    $.get(BGC.ajax, { action: 'bgc_offices', courier: 'speedy', city_id: cityId, type: type }, function (rows) {
      var opts = rows.map(function (o) { return '<option value="' + parseInt(o.office_id, 10) + '">' + esc(o.name) + ' — ' + esc(o.address) + '</option>'; }).join('');
      $wrap.find('.bgc-office').html(opts); $wrap.find('.bgc-office-row').show();
    });
  }
  function pushSelection($wrap) {
    $.post(BGC.ajax, {
      action: 'bgc_set_selection', nonce: BGC.nonce,
      method: $wrap.find('input[name=bgc_method]:checked').val(),
      site_id: $wrap.find('.bgc-city-id').val() || 0,
      office_id: $wrap.find('.bgc-office').val() || 0,
      post_code: $wrap.find('.bgc-postcode').val() || ''
    }, function () { $(document.body).trigger('update_checkout'); });
  }
  $(document.body).on('updated_checkout', function () {
    var $wrap = $('.bgc-fields'); if (!$wrap.length) return; renderTypes($wrap);
  });
  $(document.body).on('input', '.bgc-postcode', function () {
    var $wrap = $(this).closest('.bgc-fields'), code = this.value.trim();
    if (code.length < 4) return;
    searchCities(code, function (rows) {
      if (rows[0]) { $wrap.find('.bgc-city').val(rows[0].name); $wrap.find('.bgc-city-id').val(rows[0].city_id); loadOffices($wrap); }
    });
  });
  $(document.body).on('input', '.bgc-city', function () {
    var $wrap = $(this).closest('.bgc-fields');
    searchCities(this.value, function (rows) {
      if (rows[0]) { $wrap.find('.bgc-city-id').val(rows[0].city_id); $wrap.find('.bgc-postcode').val(rows[0].post_code); }
    });
  });
  $(document.body).on('change', 'input[name=bgc_method], .bgc-office', function () {
    var $wrap = $(this).closest('.bgc-fields'); loadOffices($wrap); pushSelection($wrap);
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
