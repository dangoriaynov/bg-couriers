(function ($) {
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function caps() { return ['address', 'office', 'automat']; } // Speedy supports all three
  function renderTypes($wrap) {
    if ($wrap.find('.bgc-types input').length) return;
    var html = caps().map(function (t, i) {
      return '<label><input type="radio" name="bgc_method" value="' + t + '"' + (i === 1 ? ' checked' : '') + '> ' + esc(BGC.i18n[t]) + '</label>';
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
})(jQuery);
