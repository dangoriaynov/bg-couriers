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

  function sel2($el, opts) { return ($.fn.selectWoo ? $el.selectWoo(opts) : $el.select2(opts)); }

  function initCity($wrap) {
    var $city = $wrap.find('.bgc-city');
    if ($city.data('bgc-init')) { return; }
    $city.data('bgc-init', 1);
    $city.append(new Option('', '', true, true)); // empty default
    sel2($city, {
      width: '100%', allowClear: true, placeholder: (BGC.i18n && BGC.i18n.city_ph) || '',
      minimumInputLength: 2,
      ajax: {
        url: BGC.ajax, dataType: 'json', delay: 250,
        data: function (params) { return { action: 'bgc_search_cities', courier: 'speedy', term: params.term || '' }; },
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
      loadOffices($wrap); pushSelection($wrap);
    });
  }

  function loadOffices($wrap) {
    var cityId = $wrap.find('.bgc-city').val();
    var type = $wrap.find('input[name=bgc_method]:checked').val();
    var $office = $wrap.find('.bgc-office');
    if (!cityId || type === 'address') { $wrap.find('.bgc-office-row').hide(); return; }
    $.get(BGC.ajax, { action: 'bgc_offices', courier: 'speedy', city_id: cityId, type: type }, function (rows) {
      if ($office.hasClass('select2-hidden-accessible')) {
        ($.fn.selectWoo ? $office.selectWoo('destroy') : $office.select2('destroy'));
      }
      $office.empty();
      rows.forEach(function (o) { $office.append(new Option(o.name + ' — ' + o.address, o.office_id, false, false)); });
      sel2($office, { width: '100%' });
      $wrap.find('.bgc-office-row').toggle(rows.length > 0);
      pushSelection($wrap);
    });
  }

  function pushSelection($wrap) {
    $.post(BGC.ajax, {
      action: 'bgc_set_selection', nonce: BGC.nonce,
      method: $wrap.find('input[name=bgc_method]:checked').val(),
      site_id: $wrap.find('.bgc-city').val() || 0,
      office_id: $wrap.find('.bgc-office').val() || 0,
      post_code: $wrap.find('.bgc-postcode').val() || ''
    }, function () { $(document.body).trigger('update_checkout'); });
  }

  $(document.body).on('updated_checkout', function () {
    var $wrap = $('.bgc-fields'); if (!$wrap.length) return; renderTypes($wrap); initCity($wrap);
  });

  $(document.body).on('input', '.bgc-postcode', function () {
    var $wrap = $(this).closest('.bgc-fields'), code = this.value.trim(); if (code.length < 4) { return; }
    $.get(BGC.ajax, { action: 'bgc_search_cities', courier: 'speedy', term: code }, function (rows) {
      if (rows && rows.length === 1) {
        var $city = $wrap.find('.bgc-city'); var r = rows[0];
        $city.append(new Option(r.name, r.city_id, true, true)).trigger('change'); loadOffices($wrap); pushSelection($wrap);
      }
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
