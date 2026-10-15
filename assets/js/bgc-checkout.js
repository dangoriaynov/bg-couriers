(function ($) {
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  var BGCOURIERS_TR = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i',
    'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't',
    'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sht', 'ъ': 'a', 'ь': 'y', 'ю': 'yu', 'я': 'ya' };
  function bgcTranslit(s) {
    var l = (s == null ? '' : String(s)).toLowerCase(), out = '';
    for (var i = 0; i < l.length; i++) { out += (BGCOURIERS_TR[l[i]] !== undefined ? BGCOURIERS_TR[l[i]] : l[i]); }
    return out;
  }
  // admin-ajax answers a bare 0 (truthy) when the action is missing, so only a real array passes
  function bgcList(rows) { return Array.isArray(rows) ? rows : []; }

  function bgcTextMatch(text, term) {
    if (!term) { return true; }
    var t = (text == null ? '' : String(text)).toLowerCase();
    return t.indexOf(term) !== -1 || bgcTranslit(t).indexOf(term) !== -1;
  }
  // 0 = starts with the term, 1 = a word in it starts with it, 2 = contains it, -1 = no match
  function bgcMatchRank(text, term) {
    if (!term) { return 0; }
    var t = (text == null ? '' : String(text)).toLowerCase(), forms = [t, bgcTranslit(t)], best = -1;
    for (var i = 0; i < forms.length; i++) {
      var pos = forms[i].indexOf(term);
      if (pos === -1) { continue; }
      var r = pos === 0 ? 0 : (/[\s\-.,()\/"']/.test(forms[i].charAt(pos - 1)) ? 1 : 2);
      if (best === -1 || r < best) { best = r; }
    }
    return best;
  }
  // select2 draws its dropdown on <body>, outside .bgc-fields, so it gets a class of its own to style
  function sel2($el, opts) {
    opts = $.extend({ dropdownCssClass: 'bgc-drop', language: s2lang() }, opts || {});
    var inst = ($.fn.selectWoo ? $el.selectWoo(opts) : $el.select2(opts));
    // pin the dropdown width to the field: drawn on <body>, a re-layout can leave it narrower
    $el.on('select2:open', function () {
      var $box = $el.next('.select2-container');
      var fit = function () {
        var $d = $('.select2-container--open .select2-dropdown.bgc-drop');
        if (!$d.length || !$box.length) { return; }
        var r = $box[0].getBoundingClientRect(), w = Math.round(r.width);
        $d.css({ width: w + 'px', minWidth: w + 'px' });
      };
      fit(); setTimeout(fit, 0);
    });
    return inst;
  }
  function s2lang() {
    var t = (BGCOURIERS && BGCOURIERS.i18n) || {};
    return {
      inputTooShort: function (a) { return (t.s2_short || 'Type at least %d characters').replace('%d', a.minimum); },
      noResults:     function () { return t.s2_none || 'Nothing found'; },
      searching:     function () { return t.s2_searching || 'Searching\u2026'; },
      loadingMore:   function () { return t.s2_more || 'Loading more\u2026'; },
      errorLoading:  function () { return t.s2_error || 'The results could not be loaded'; }
    };
  }
  // Suppress select2's "results could not be loaded" flash when it aborts an in-flight search on fast typing.
  function noAbortTransport(params, success, failure) {
    var req = $.ajax(params);
    req.then(success);
    req.fail(function (x, status) { if (status !== 'abort') { failure(); } });
    return req;
  }

  function courier($wrap) { return $wrap.attr('data-courier') || 'speedy'; }
  function country($wrap) {
    var $c = $wrap.find('.bgc-country');
    return ($c.length && $c.val()) || $wrap.attr('data-country') || BGCOURIERS.homeCountry || 'BG';
  }
  function caps($wrap) {
    var enabled = ($wrap.attr('data-methods') || 'office,address,automat').split(',');
    var order   = ($wrap.attr('data-order')   || 'office,address,automat').split(',');
    return order.filter(function (t) { return enabled.indexOf(t) !== -1; });
  }
  function method($wrap) { return $wrap.attr('data-method') || caps($wrap)[0] || 'office'; }
  function showLoader($wrap) { $wrap.addClass('bgc-loading'); }
  function hideLoader($wrap) { $wrap.removeClass('bgc-loading'); }
  function officeLabel(m) { return m === 'automat' ? (BGCOURIERS.i18n.automat_label || 'Automat') : (BGCOURIERS.i18n.office_label || 'Office'); }

  // Delivery-type tabs ------------------------------------------------------
  function renderTabs($wrap) {
    var types = caps($wrap);
    if (!$wrap.attr('data-method')) { $wrap.attr('data-method', types[0]); }
    var sel = method($wrap);
    if ($wrap.find('.bgc-tab').length) { syncMethodUI($wrap); return; }
    var icons = BGCOURIERS.icons || {};
    var html = types.map(function (t) {
      return '<button type="button" class="bgc-tab' + (t === sel ? ' active' : '') + '" data-method="' + t + '">'
        + (icons[t] || '') + '<span class="bgc-tab-txt">' + esc(BGCOURIERS.i18n[t]) + '</span></button>';
    }).join('');
    $wrap.find('.bgc-tabs').html(html);
  }

  function noCod($wrap) {
    return ($wrap.attr('data-nocod') || '').split(',').filter(function (t) { return t !== ''; });
  }

  function syncMethodUI($wrap) {
    var m = method($wrap), isAddr = m === 'address';
    $wrap.find('.bgc-tab').each(function () { $(this).toggleClass('active', $(this).data('method') === m); });
    $wrap.find('.bgc-address-rows').toggle(isAddr);
    $wrap.find('.bgc-office-row').toggle(!isAddr);
    if (!isAddr) { $wrap.find('.bgc-office-label').text(officeLabel(m)); }
    var gone = noCod($wrap).indexOf(m) !== -1;
    $wrap.find('.bgc-nocod').toggle(gone);
    if ($wrap.is(':visible')) { hideCodRows(gone); }
  }

  // the server already dropped the gateway, but this checkout never refreshes the payment fragment
  function hideCodRows(hide) {
    var ids = BGCOURIERS.codGateways || [];
    if (!ids.length) { return; }
    ids.forEach(function (id) {
      var $li = $('.wc_payment_method.payment_method_' + id);
      if (!$li.length) { return; }
      $li.toggle(!hide);
      var $radio = $li.find('input[name="payment_method"]');
      $radio.prop('disabled', hide);
      if (hide && $radio.is(':checked')) {
        $('.wc_payment_method:visible input[name="payment_method"]').first().prop('checked', true).trigger('click');
      }
    });
  }

  function resetOffice($wrap) {
    var $o = $wrap.find('.bgc-office');
    if ($o.hasClass('select2-hidden-accessible')) { $o.val(null).trigger('change.select2'); }
    $o.empty();
  }
  function resetStreet($wrap) {
    var $s = $wrap.find('.bgc-street');
    if ($s.hasClass('select2-hidden-accessible')) { $s.val(null).trigger('change.select2'); }
    $s.empty().append(new Option('', '', false, false));
    setStreetPick($wrap, null);
    streetNote($wrap, '');
    $wrap.find('.bgc-street-no').val('');
  }
  function setStreetPick($wrap, d) {
    $wrap.find('.bgc-street-id').val((d && d.sid) || 0);
    $wrap.find('.bgc-street-type').val((d && d.stype) || '');
  }
  // kept per courier and re-applied: the block lives in the order-review table, which update_checkout replaces
  var streetNotes = {};
  function streetNote($wrap, text) {
    var id = courier($wrap);
    if (text) { streetNotes[id] = text; } else { delete streetNotes[id]; }
    $wrap.find('.bgc-street-note').text(text || '').toggle(!!text);
  }
  function reapplyStreetNote($wrap) {
    var text = streetNotes[courier($wrap)];
    if (text && String($wrap.find('.bgc-street').val() || '') === '') { $wrap.find('.bgc-street-note').text(text).show(); }
    else { delete streetNotes[courier($wrap)]; $wrap.find('.bgc-street-note').hide(); }
  }

  function setMethod($wrap, m) {
    $wrap.attr('data-method', m);
    syncMethodUI($wrap);
    if (m !== 'address') { resetOffice($wrap); }
    showLoader($wrap);
    pushSelection($wrap);
    if (m !== 'address') { preloadOffices($wrap); }
  }

  var availCache = {};
  function methodOk(av, m) { return m === 'address' || (m === 'office' && av.office) || (m === 'automat' && av.automat); }
  // the preloaded index covers one country only; abroad it is empty, not incomplete, so it is not consulted
  function indexUsable($wrap) {
    return !!BGCOURIERS.preloadCities
      && country($wrap) === (BGCOURIERS.cityIndexCountry || BGCOURIERS.homeCountry || 'BG');
  }
  function cityAvailLocal($wrap, city) {
    if (!indexUsable($wrap)) { return null; }
    var idx = BGCOURIERS.cityIndex && BGCOURIERS.cityIndex[courier($wrap)];
    if (!idx) { return null; }
    function has(list) { var a = idx[list] || []; for (var i = 0; i < a.length; i++) { if (a[i][0] == city) { return true; } } return false; }
    return { office: has('office'), automat: has('automat') };
  }
  function applyAvail($wrap) {
    var city = $wrap.find('.bgc-city').val() || 0;
    if (!city) { $wrap.find('.bgc-tab').removeClass('bgc-tab-na').prop('disabled', false).attr('title', ''); return; }
    var key = courier($wrap) + ':' + country($wrap) + ':' + city;
    if (availCache[key] === undefined) {
      var local = cityAvailLocal($wrap, city);
      if (local) { availCache[key] = local; }
      else {
        $.get(BGCOURIERS.ajax, { action: 'bgcouriers_city_avail', courier: courier($wrap), country: country($wrap), city_id: city }, function (res) {
          // only a real object is cached: admin-ajax's bare "0" is not "neither"
          if (!res || typeof res !== 'object') { return; }
          availCache[key] = { office: !!(res && res.office), automat: !!(res && res.automat) };
          applyAvail($wrap);
        });
        return;
      }
    }
    var av = availCache[key], firstOk = null;
    var settled = $wrap.data('bgc-availed');
    $wrap.find('.bgc-tab').each(function () {
      var $t = $(this), m = $t.data('method'), ok = methodOk(av, m);
      // not the disabled property: a disabled button gets no mouse events, so its title never shows
      $t.toggleClass('bgc-tab-na', !ok)
        .prop('disabled', false)
        .attr('aria-disabled', ok ? null : 'true')
        .attr('title', ok ? '' : (BGCOURIERS.i18n.na_city || ''));
      if (ok && firstOk === null) { firstOk = m; }
    });
    if (methodOk(av, method($wrap)) || !firstOk) { $wrap.data('bgc-availed', true); return; }
    // on the first render switch quietly: setMethod() would start a second update_checkout
    if (settled) {
        setMethod($wrap, firstOk);
    } else {
        $wrap.attr('data-method', firstOk);
        syncMethodUI($wrap);
        saveSelection($wrap);
    }
    $wrap.data('bgc-availed', true);
  }

  // City (searchable, server-limited) --------------------------------------
  function initCity($wrap) {
    var $city = $wrap.find('.bgc-city');
    if ($city.hasClass('select2-hidden-accessible')) { return; }
    sel2($city, {
      width: '100%', allowClear: true, placeholder: (BGCOURIERS.i18n && BGCOURIERS.i18n.city_ph) || '', minimumInputLength: 0,
      ajax: {
        url: BGCOURIERS.ajax, dataType: 'json', delay: 250,
        transport: function (params, success, failure) {
          var m = method($wrap), cour = courier($wrap);
          var idx = BGCOURIERS.cityIndex && BGCOURIERS.cityIndex[cour];
          if (indexUsable($wrap) && m !== 'address' && idx && idx[m]) {
            var term = ((params.data && params.data.term) || '').toLowerCase(), rows = idx[m], out = [];
            for (var i = 0; i < rows.length; i++) {
              var a = rows[i]; // [city_id, name, post_code, name_lat]
              var r = bgcMatchRank(a[1], term);
              if (r !== 0 && a[3]) { var lp = String(a[3]).toLowerCase().indexOf(term); if (lp === 0) { r = 0; } else if (lp > 0 && r === -1) { r = 2; } }
              if (r !== 0 && String(a[2]).indexOf(term) === 0) { r = 0; }
              if (r !== -1) { out.push({ city_id: a[0], name: a[1], post_code: a[2], r: r, i: i }); }
            }
            out.sort(function (x, y) { return x.r - y.r || x.i - y.i; });
            out = out.slice(0, 200).map(function (o) { return { city_id: o.city_id, name: o.name, post_code: o.post_code }; });
            success(out); return { abort: function () {} };
          }
          return noAbortTransport(params, success, failure);
        },
        data: function (params) { return { action: 'bgcouriers_search_cities', courier: courier($wrap), country: country($wrap), term: params.term || '' }; },
        processResults: function (raw) {
          var rows = bgcList(raw), counts = {};
          rows.forEach(function (r) { counts[r.name] = (counts[r.name] || 0) + 1; });
          return { results: rows.map(function (r) {
            var text = r.name + (r.post_code ? ' (' + r.post_code + ')' : '');
            if (counts[r.name] > 1 && !r.post_code && r.region) { text += ' - ' + r.region; }
            return { id: r.city_id, text: text, post_code: r.post_code };
          }) };
        }
      }
    });
    $city.on('select2:select', function (e) {
      var d = e.params && e.params.data; if (d && d.post_code) { $wrap.find('.bgc-postcode').val(d.post_code); }
      $wrap.find('.bgc-map-btn').prop('disabled', false).attr('title', '');
      resetOffice($wrap); resetStreet($wrap);
      // office list first: it is the slow request and must not queue behind the recalculation
      var cour = courier($wrap), city = $wrap.find('.bgc-city').val() || 0, m = method($wrap);
      var listReq = preloadOffices($wrap);
      var saved = $.Deferred();
      pushSelection($wrap).always(function () { saved.resolve(); });
      $.when(listReq, saved).done(function (rows) { autoPickFromList(cour, city, m, rows); });
      $(document.body).one('updated_checkout', function () {
        idle(function () { prefetchOtherCouriers(city, m, country($wrap)); });
      });
    });
    // select2:unselect, not select2:clear: selectWoo (Select2 4.0.3) never fires clear, and unselect still holds the old value
    $city.on('select2:unselect', function () {
      $city.val(null);
      $wrap.find('.bgc-postcode').val('');
      $wrap.find('.bgc-map-btn').prop('disabled', true).attr('title', (BGCOURIERS.i18n && BGCOURIERS.i18n.office_need_city) || '');
      resetOffice($wrap); resetStreet($wrap); showLoader($wrap); pushSelection($wrap); preloadOffices($wrap);
      idle(function () { prefetchOtherCouriers($wrap.find('.bgc-city').val() || 0, method($wrap), country($wrap)); });
    });
  }

  $(document).on('change', '.bgc-fields .bgc-country', function () {
    var $wrap = $(this).closest('.bgc-fields');
    $wrap.attr('data-country', $(this).val() || '');
    var $city = $wrap.find('.bgc-city');
    $city.val('').trigger('change.select2');
    $wrap.find('.bgc-postcode').val('');
    resetOffice($wrap); resetStreet($wrap);
    $wrap.find('.bgc-map-btn').prop('disabled', true);
    $wrap.removeData('bgc-availed');
    // namespaced trigger: repaints WooCommerce's country select without its change handler recalculating again
    var $wc = $('#shipping_country, #billing_country');
    if ($wc.length) { $wc.val($(this).val()).trigger('change.select2'); }
    showLoader($wrap); pushSelection($wrap);
  });

  var officeCache = {};

  var OFFICE_STORE = 'bgcouriers_off:';
  function cacheGet(key) {
    if (officeCache[key] !== undefined) { return officeCache[key]; }
    try {
      var raw = window.sessionStorage && sessionStorage.getItem(OFFICE_STORE + key);
      if (raw) { officeCache[key] = JSON.parse(raw); return officeCache[key]; }
    } catch (e) { }
    return undefined;
  }
  // only a real array is cached: a cached "0" or HTML page would outlive the page in sessionStorage
  function cacheSet(key, rows) {
    if (!Array.isArray(rows)) { return undefined; }
    officeCache[key] = rows;
    try { window.sessionStorage && sessionStorage.setItem(OFFICE_STORE + key, JSON.stringify(rows)); }
    catch (e) { }
    return rows;
  }
  function idle(fn) {
    if (window.requestIdleCallback) { requestIdleCallback(fn, { timeout: 2000 }); } else { setTimeout(fn, 400); }
  }

  function officeKey($wrap, city, m) { return courier($wrap) + ':' + country($wrap) + ':' + city + ':' + m; }
  function preloadOffices($wrap) {
    var city = $wrap.find('.bgc-city').val() || 0, m = method($wrap), cour = courier($wrap);
    if (!city || m === 'address') { return $.Deferred().resolve().promise(); }
    if (cour === 'sameday' && m === 'automat') { warmAvail(country($wrap), city); }
    var key = officeKey($wrap, city, m);
    var hit = cacheGet(key);
    if (hit !== undefined) { return $.Deferred().resolve(hit).promise(); }
    var d = $.Deferred();
    $.get(BGCOURIERS.ajax, { action: 'bgcouriers_offices', courier: cour, country: country($wrap), city_id: city, type: m, all: 1 },
      function (rows) { d.resolve(cacheSet(key, rows)); }).fail(function () { d.resolve(undefined); });
    return d.promise();
  }

  function pickSingleOffice(cour, city, m, rows) {
    var list = bgcList(rows || []);
    if (list.length !== 1) { return false; }
    var $w = $('.bgc-fields[data-courier="' + cour + '"]');
    if (!$w.length || String($w.find('.bgc-city').val() || '') !== String(city) || method($w) !== m) { return false; }
    var $o = $w.find('.bgc-office');
    if (!$o.length || String($o.val() || '') !== '' || list[0].full === true) { return false; }
    $o.append(new Option(list[0].name + ' - ' + list[0].address, list[0].office_id, true, true)).val(String(list[0].office_id)).trigger('change.select2');
    return $w;
  }
  function autoPickFromList(cour, city, m, rows) {
    var $w = pickSingleOffice(cour, city, m, rows);
    if (!$w) { return; }
    saveSelection($w).always(function () { pickSingleOffice(cour, city, m, rows) && saveSelection($('.bgc-fields[data-courier="' + cour + '"]')); });
  }

  // live Sameday locker availability: kept out of the office cache and cached only briefly
  var availStore = {};
  var availInflight = {};
  var AVAIL_TTL = 45000;
  function availHit(ctry, city) {
    var h = availStore[ctry + ':' + city];
    return (h && (Date.now() - h.at) < AVAIL_TTL) ? h.map : null;
  }
  function warmAvail(ctry, city) {
    var key = ctry + ':' + city;
    if (availHit(ctry, city) !== null || availInflight[key]) { return; }
    availInflight[key] = true;
    $.get(BGCOURIERS.ajax, { action: 'bgcouriers_sameday_availability', country: ctry, city_id: city })
      .done(function (res) { availStore[key] = { at: Date.now(), map: (res && res.lockers) || {} }; })
      .always(function () { delete availInflight[key]; });
  }

  // the server picks the single office in render_fields; here it is only written to the session once
  function autoPickSingle($wrap) {
    var $o = $wrap.find('.bgc-office');
    if (!$o.length || $o.attr('data-auto') !== '1') { return; }
    if (String($o.val() || '') === '') { return; }
    $o.removeAttr('data-auto');
    saveSelection($wrap);
  }

  function prefetchOtherCouriers(city, m, ctry) {
    if (!city || m === 'address') { return; }
    var seen = {}, queue = [];
    $('.bgc-fields').each(function () {
      var $o = $(this), c = $o.attr('data-courier');
      if (!c || seen[c]) { return; }
      if (country($o) !== ctry) { return; }
      seen[c] = 1;
      if (cacheGet(c + ':' + ctry + ':' + city + ':' + m) === undefined) { queue.push(c); }
    });
    (function next() {
      if (!queue.length) { return; }
      var c = queue.shift();
      $.get(BGCOURIERS.ajax, { action: 'bgcouriers_offices', courier: c, country: ctry, city_id: city, type: m, all: 1 })
        .done(function (rows) { cacheSet(c + ':' + ctry + ':' + city + ':' + m, rows); })
        .always(function () { idle(next); });
    })();
  }

  function syncMapBtn($wrap) {
    var hasCity = !!$wrap.find('.bgc-city').val();
    $wrap.find('.bgc-map-btn:not(.bgc-addr-map-btn)').prop('disabled', !hasCity)
      .attr('title', hasCity ? '' : ((BGCOURIERS.i18n && BGCOURIERS.i18n.office_need_city) || ''));
  }
  function syncOffice($wrap) {
    $wrap.find('.bgc-office').prop('disabled', !$wrap.find('.bgc-city').val());
  }
  $(document).on('change', '.bgc-fields .bgc-city', function () {
    var $wrap = $(this).closest('.bgc-fields');
    syncMapBtn($wrap); syncOffice($wrap);
  });

  function initOffice($wrap) {
    var $office = $wrap.find('.bgc-office');
    var hasCity = !!$wrap.find('.bgc-city').val();
    syncMapBtn($wrap); syncOffice($wrap);
    if ($office.hasClass('select2-hidden-accessible')) { return; }
    sel2($office, {
      width: '100%', allowClear: true, minimumInputLength: 0, placeholder: hasCity ? ((BGCOURIERS.i18n && BGCOURIERS.i18n.office_ph) || '') : ((BGCOURIERS.i18n && BGCOURIERS.i18n.office_need_city) || ''),
      ajax: {
        delay: 0,
        transport: function (params, success, failure) {
          var d = params.data, key = d.courier + ':' + d.country + ':' + d.city_id + ':' + d.type, term = (d.term || '').toLowerCase();
          function done(rows) {
            var out = term ? rows.filter(function (o) {
              return bgcTextMatch(o.name, term) || (String(o.office_id).indexOf(term) !== -1) || bgcTextMatch(o.address, term);
            }) : rows;
            // Sameday automats: overlay live fullness on a copy, never into the cached rows
            if (d.courier === 'sameday' && d.type === 'automat' && d.city_id) {
              var map = availHit(d.country, d.city_id);
              success(map ? out.map(function (o) { return $.extend({}, o, { full: map[o.office_id] === false }); }) : out);
              if (!map) { warmAvail(d.country, d.city_id); }
            } else {
              success(out);
            }
          }
          var hit = cacheGet(key);
          if (hit !== undefined) { done(hit); return { abort: function () {} }; }
          var req = $.get(BGCOURIERS.ajax, { action: 'bgcouriers_offices', courier: d.courier, country: d.country, city_id: d.city_id, type: d.type, all: 1 });
          req.done(function (rows) { done(cacheSet(key, rows) || []); });
          req.fail(function (x, status) { if (status !== 'abort') { failure(); } });
          return req;
        },
        data: function (params) {
          return { courier: courier($wrap), country: country($wrap), city_id: $wrap.find('.bgc-city').val() || 0, type: method($wrap), term: params.term || '' };
        },
        processResults: function (rows) {
          var fullTxt = (BGCOURIERS.i18n && BGCOURIERS.i18n.allmap_full) || 'Full';
          return { results: bgcList(rows).map(function (o) {
            var full = o.full === true;
            return { id: o.office_id, text: o.name + ' - ' + o.address + (full ? ' (' + fullTxt + ')' : ''), disabled: full };
          }) };
        }
      }
    });
    $office.on('select2:select', function () { saveSelection($wrap); });
    // unselect, not clear: see the city box above
    $office.on('select2:unselect', function () { $office.val(null); saveSelection($wrap); });
  }

  var mapIconsSet = false, bgcMap = null;
  function setMapIcons() {
    if (mapIconsSet || !window.L) { return; }
    var base = BGCOURIERS.leaflet_images || '';
    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({ iconRetinaUrl: base + 'marker-icon-2x.png', iconUrl: base + 'marker-icon.png', shadowUrl: base + 'marker-shadow.png' });
    mapIconsSet = true;
  }
  function closeMap() { $('#bgc-map-overlay').remove(); if (bgcMap) { bgcMap.remove(); bgcMap = null; } }

  // ── Office / APS map ─────────────────────────────────────────────────────────
  $(document).on('click', '.bgc-map-btn:not(.bgc-addr-map-btn)', function (e) {
    e.preventDefault();
    var $wrap = $(this).closest('.bgc-fields');
    if (!window.BGCouriersAllMap) { return; }
    window.BGCouriersAllMap.open({ only: courier($wrap), wrap: $wrap });
  });
  $(document).on('click', '.bgc-map-close', function () { closeMap(); });
  $(document).on('click', '#bgc-map-overlay', function (e) { if (e.target === this) { closeMap(); } });
  $(document).on('keydown', function (e) { if ((e.key === 'Escape' || e.keyCode === 27) && $('#bgc-map-overlay').length) { closeMap(); } });

  // ── Address map picker: drop/drag a pin -> reverse-geocode -> fill the (still editable) address fields.
  var geoT;
  function reverseGeocode(lat, lng, cb) {
    clearTimeout(geoT);
    geoT = setTimeout(function () { $.get(BGCOURIERS.ajax, { action: 'bgcouriers_geocode', lat: lat, lng: lng }, function (r) { cb(r || {}); }); }, 350);
  }
  function fillAddress($wrap, geo) {
    var $city = $wrap.find('.bgc-city');
    function pickCity(r) {
      $city.append(new Option(r.name + (r.post_code ? ' (' + r.post_code + ')' : ''), r.city_id, true, true)).trigger('change');
    }
    function fields(pc) {
      if (pc) { $wrap.find('.bgc-postcode').val(pc); }
      if (geo.number) { $wrap.find('.bgc-street-no').val(geo.number); }
      resetOffice($wrap);
      if (!geo.street) { showLoader($wrap); pushSelection($wrap); return; }
      // ask the courier for this exact street first, so the one save carries its id and type
      showLoader($wrap);
      var listOnly = ((BGCOURIERS.streetListOnly || []).indexOf(courier($wrap)) !== -1);
      var $street = $wrap.find('.bgc-street');
      $.get(BGCOURIERS.ajax, { action: 'bgcouriers_streets', courier: courier($wrap), country: country($wrap), city_id: $wrap.find('.bgc-city').val() || 0, term: geo.street, exact: 1 })
        .always(function (rows) {
          var hits = bgcList(rows);
          $street.find('option').not('[value=""]').remove();
          streetNote($wrap, '');
          if (hits.length === 1) {
            $street.append(new Option(hits[0].label || hits[0].name, hits[0].name, true, true));
            setStreetPick($wrap, { sid: hits[0].id, stype: hits[0].type });
          } else if (!listOnly) {
            $street.append(new Option(geo.street, geo.street, true, true));
            setStreetPick($wrap, null);
          } else {
            $street.val(null);
            setStreetPick($wrap, null);
          }
          $street.trigger('change');
          if (hits.length !== 1 && listOnly) {
            var who = ($wrap.attr('data-label') || courier($wrap));
            streetNote($wrap, (BGCOURIERS.i18n && BGCOURIERS.i18n.street_unlisted || '%1$s: %2$s').replace('%1$s', who).replace('%2$s', geo.street));
            invalidIds.push($wrap.find('.bgc-street-field').attr('id')); markInvalid();
          }
          pushSelection($wrap);
        });
    }
    function findCity(term, cb) {
      if (!term) { cb(null); return; }
      $.get(BGCOURIERS.ajax, { action: 'bgcouriers_search_cities', courier: courier($wrap), country: country($wrap), term: term }, function (rows) {
        cb((rows && rows.length) ? rows[0] : null);
      });
    }
    // the city name is the reliable key; a map point's postcode is only a fallback
    findCity(geo.city, function (r) {
      if (r) { pickCity(r); fields(geo.postcode || r.post_code || ''); return; }
      findCity(geo.postcode, function (r2) {
        if (r2) { pickCity(r2); fields(geo.postcode || r2.post_code || ''); }
        else { $city.val('').trigger('change'); fields(geo.postcode || ''); }
      });
    });
  }
  function openAddressMap($wrap) {
    if (!window.L) { return; }
    var i18n = BGCOURIERS.i18n || {};
    var $ov = $('<div id="bgc-map-overlay" class="bgc-map-overlay"><div class="bgc-map-box">'
      + '<div class="bgc-map-head"><strong>' + esc(i18n.addr_map_title || 'Map') + '</strong>'
      + '<button type="button" class="bgc-map-close" aria-label="' + esc(i18n.close || 'Close') + '">×</button></div>'
      + '<div class="bgc-map-canvas" id="bgc-map"></div>'
      + '<div class="bgc-map-actions"><button type="button" class="button bgc-map-locate">' + esc(i18n.map_locate || 'My location') + '</button>'
      + '<span class="bgc-map-hint bgc-addr-preview">' + esc(i18n.addr_map_hint || '') + '</span>'
      + '<button type="button" class="button button-primary bgc-addr-use" disabled>' + esc(i18n.addr_use || 'Use') + '</button></div></div></div>');
    $('body').append($ov);
    setMapIcons();
    bgcMap = L.map('bgc-map', { scrollWheelZoom: true }).setView([42.7, 25.3], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(bgcMap);
    var pin = null, current = {};
    function place(ll) {
      if (pin) { pin.setLatLng(ll); } else { pin = L.marker(ll, { draggable: true }).addTo(bgcMap); pin.on('dragend', function () { place(pin.getLatLng()); }); }
      $ov.find('.bgc-addr-preview').text('…'); $ov.find('.bgc-addr-use').prop('disabled', true);
      reverseGeocode(ll.lat, ll.lng, function (geo) {
        current = geo;
        var txt = [geo.street, geo.number].filter(Boolean).join(' ') + (geo.city ? ((geo.street ? ', ' : '') + geo.city) : '');
        $ov.find('.bgc-addr-preview').text(txt || (i18n.addr_none || ''));
        $ov.find('.bgc-addr-use').prop('disabled', !(geo.city || geo.street));
      });
    }
    bgcMap.on('click', function (e) { place(e.latlng); });
    function locate() { if (navigator.geolocation) { navigator.geolocation.getCurrentPosition(function (pos) { var here = L.latLng(pos.coords.latitude, pos.coords.longitude); bgcMap.setView(here, 15); place(here); }); } }
    $ov.find('.bgc-map-locate').on('click', locate);
    locate();
    // meanwhile open on the town already named; a geolocation fix that lands first wins
    var town = $wrap.find('.bgc-city option:selected').text().match(/^(.*?)\s*(?:\((\d+)\))?\s*$/);
    if (town && town[1] && window.BGCouriersAllMap && BGCouriersAllMap.centreOf) {
      BGCouriersAllMap.centreOf(town[1], town[2] || '', function (c) {
        if (!c || pin || !bgcMap || !document.getElementById('bgc-map')) { return; }
        bgcMap.setView([c.lat, c.lng], 13);
      });
    }
    $ov.find('.bgc-addr-use').on('click', function () { fillAddress($wrap, current); closeMap(); });
    setTimeout(function () { if (bgcMap) { bgcMap.invalidateSize(); } }, 60);
  }
  $(document).on('click', '.bgc-addr-map-btn', function (e) { e.preventDefault(); openAddressMap($(this).closest('.bgc-fields')); });

  // free typing is off for a courier that refuses a street without an id (Express One)
  function initStreet($wrap) {
    var $street = $wrap.find('.bgc-street');
    if (!$street.length || $street[0].tagName !== 'SELECT' || $street.hasClass('select2-hidden-accessible')) { return; }
    var listOnly = ((BGCOURIERS.streetListOnly || []).indexOf(courier($wrap)) !== -1);
    sel2($street, {
      width: '100%', tags: !listOnly, allowClear: true, minimumInputLength: 2, placeholder: (BGCOURIERS.i18n && BGCOURIERS.i18n.street_ph) || '',
      ajax: {
        url: BGCOURIERS.ajax, dataType: 'json', delay: 250, transport: noAbortTransport,
        data: function (params) { return { action: 'bgcouriers_streets', courier: courier($wrap), country: country($wrap), city_id: $wrap.find('.bgc-city').val() || 0, term: params.term || '' }; },
        // id is the courier's label, not the bare name: "бул. X" and "ул. X" must stay two rows
        processResults: function (rows) { return { results: bgcList(rows).map(function (s) { return { id: s.label || s.name, text: s.label || s.name, sname: s.name, sid: s.id || 0, stype: s.type || '' }; }) }; }
      },
      createTag: function (params) {
        if (listOnly) { return null; }
        var t = (params.term || '').trim();
        return t ? { id: t, text: t } : null;
      }
    });
    $street.on('select2:select', function (e) {
      var d = (e.params && e.params.data) || {};
      $street.find('option').not('[value=""]').remove();
      $street.append(new Option(d.text || '', d.sname || d.id || '', true, true)).trigger('change.select2');
      setStreetPick($wrap, d);
      streetNote($wrap, '');
      saveSelection($wrap);
    });
    // the clear button fires this before select2 empties the box, so the value is cleared here
    $street.on('select2:unselect', function () { $street.val(null); setStreetPick($wrap, null); saveSelection($wrap); });
  }

  // Save the selection ------------------------------------------------------
  function selectionData($wrap) {
    return {
      action: 'bgcouriers_set_selection', nonce: BGCOURIERS.nonce, courier: courier($wrap), country: country($wrap), method: method($wrap),
      site_id: $wrap.find('.bgc-city').val() || 0,
      office_id: $wrap.find('.bgc-office').val() || 0,
      post_code: $wrap.find('.bgc-postcode').val() || '',
      street_name: $wrap.find('.bgc-street').val() || '', street_no: $wrap.find('.bgc-street-no').val() || '',
      street_id: $wrap.find('.bgc-street-id').val() || 0, street_type: $wrap.find('.bgc-street-type').val() || '',
      complex: $wrap.find('.bgc-complex').val() || '', block: $wrap.find('.bgc-block').val() || '',
      entrance: $wrap.find('.bgc-entrance').val() || '', floor: $wrap.find('.bgc-floor').val() || '',
      apartment: $wrap.find('.bgc-apartment').val() || '', address_note: $wrap.find('.bgc-note').val() || ''
    };
  }
  function pushSelection($wrap) { showLoader($wrap); return $.post(BGCOURIERS.ajax, selectionData($wrap), function () { $(document.body).trigger('update_checkout'); }); }
  function saveSelection($wrap) { return $.post(BGCOURIERS.ajax, selectionData($wrap)); }

  // flush the pending save before the form posts: the server validates against the session, not the form; capture phase, ahead of WooCommerce
  var resubmitting = false;
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.classList || !form.classList.contains('checkout')) { return; }
    if (resubmitting) { resubmitting = false; return; }
    var $wrap = $('.bgc-fields[data-courier="' + chosenCourier() + '"]').first();
    if (!$wrap.length) { return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    clearTimeout(addrT);
    var done = false;
    var go = function () {
      if (done) { return; }
      done = true;
      resubmitting = true;
      $(form).trigger('submit');
    };
    saveSelection($wrap).always(go);
    setTimeout(go, 5000);
  }, true);

  // the same flush for the checkout block (bgc-blocks.js onCheckoutValidation)
  window.BGCOURIERS.flushSelection = function () {
    clearTimeout(addrT);
    var $wrap = $('.bgc-fields[data-courier="' + chosenCourier() + '"]').first();
    return new Promise(function (resolve) {
      var done = false;
      var go = function () { if (!done) { done = true; resolve(true); } };
      if (!$wrap.length) { go(); return; }
      try { saveSelection($wrap).always(go); } catch (e) { go(); }
      setTimeout(go, 5000);
    });
  };

  // Wiring ------------------------------------------------------------------
  function chosenCourier() {
    var v = $('input[name^="shipping_method"]:checked').val()
         || $('input[name^="shipping_method"][type="hidden"]').val() || '';
    var m = String(v).match(/^bgcouriers_([a-z0-9]+)/);
    return m ? m[1] : '';
  }

  function dimRates() {
    $('input[name^="shipping_method"]').each(function () {
      $('label[for="' + this.id + '"]').toggleClass('bgc-rate-inactive', !this.checked);
    });
  }

  // html.bgc-relocating hides the rows from first paint; drop it whenever the move cannot happen
  function stopHiding() {
    document.documentElement.className =
      document.documentElement.className.replace(/(^|\s)bgc-relocating(\s|$)/, ' ');
  }

  function relocateDelivery() {
    if (!BGCOURIERS || BGCOURIERS.deliveryPosition !== 'details') { stopHiding(); return; }
    var $host = $('#bgcouriers-delivery-host');
    if (!$host.length) { stopHiding(); return; }
    var $rows = $('tr.bgc-allmap-open, tr.woocommerce-shipping-totals, tr.shipping');
    if (!$rows.length) { stopHiding(); return; }
    var $moving = $rows.children('td').children();
    // nothing to move means it is already in the host; emptying would delete the only copy
    if ($moving.length) { $host.empty().append($moving); }
    $rows.addClass('bgc-moved-away');
  }


  function reveal($wrap) {
    if ($wrap.hasClass('bgc-ready')) { return; }
    window.requestAnimationFrame(function () { $wrap.addClass('bgc-ready'); });
  }

  // a cell cannot span its row from CSS; only a WooCommerce-shaped row with an empty <th> is touched
  function spanShippingRow() {
    var wide = window.matchMedia('(min-width: 783px)').matches;
    $('tr.woocommerce-shipping-totals, tr.shipping').each(function () {
      var $tr = $(this), $th = $tr.children('th'), $td = $tr.children('td');
      if ($tr.hasClass('bgc-moved-away')) { return; }
      if ($th.length !== 1 || $td.length !== 1 || $.trim($th.text()) !== '') { return; }
      var cols = 0;
      $tr.closest('table').find('tr').each(function () {
        var n = 0;
        $(this).children('th,td').each(function () { n += (parseInt(this.getAttribute('colspan'), 10) || 1); });
        if (n > cols) { cols = n; }
      });
      if (cols < 2) { return; }
      if (wide) { $th.css('display', ''); $td.removeAttr('colspan'); }
      else { $th.css('display', 'none'); $td.attr('colspan', cols); }
    });
  }
  var spanTimer = null;
  $(window).on('resize orientationchange', function () {
    clearTimeout(spanTimer); spanTimer = setTimeout(spanShippingRow, 150);
  });

  // ── The field a refusal is about, painted red where it stands ─────────────────────────────────
  // kept as ids: the fields live in the order-review table, which update_checkout replaces
  var invalidIds = [];
  function filled($f) {
    var yes = false;
    $f.find('select, input').not('[type=hidden]').each(function () { if (String($(this).val() || '').trim() !== '') { yes = true; } });
    return yes;
  }
  function markInvalid() {
    $('.bgc-fields .bgc-invalid').removeClass('bgc-invalid');
    invalidIds = invalidIds.filter(function (id) {
      var $f = $('#' + id).filter('.bgc-field');
      if ($f.length && filled($f)) { return false; }
      $f.addClass('bgc-invalid');
      return true;
    });
  }
  function clearInvalid(el) {
    var $f = $(el).closest('.bgc-field.bgc-invalid');
    if (!$f.length) { return; }
    $f.removeClass('bgc-invalid').removeAttr('aria-invalid aria-describedby');
    invalidIds = invalidIds.filter(function (id) { return id !== $f.attr('id'); });
  }
  $(document.body).on('checkout_error', function (e, html) {
    invalidIds = [];
    $('<div>').html(String(html || '')).find('li[data-id]').each(function () {
      var id = String($(this).attr('data-id') || '');
      if (id.indexOf('bgcouriers-') === 0) { invalidIds.push(id); }
    });
    markInvalid();
  });
  $(document.body).on('change', '.bgc-fields .bgc-city, .bgc-fields .bgc-office, .bgc-fields .bgc-street', function () { clearInvalid(this); });
  $(document.body).on('input', '.bgc-fields .bgc-street-no', function () { clearInvalid(this); });

  $(document.body).on('updated_checkout', function () {
    relocateDelivery();
    spanShippingRow();
    dimRates();
    markInvalid();
    if (!$('.bgc-fields').length) return;
    var chosen = chosenCourier();
    $('.bgc-fields').each(function () {
      var $wrap = $(this);
      var mine = $wrap.attr('data-courier') === chosen;
      if (!mine) { $wrap.hide().removeClass('bgc-ready'); return; }
      $wrap.show();
      renderTabs($wrap); initCity($wrap); initOffice($wrap); initStreet($wrap); syncMethodUI($wrap); applyAvail($wrap); autoPickSingle($wrap); hideLoader($wrap);
      reapplyStreetNote($wrap);
      reveal($wrap);
    });
  });

  $(document.body).on('click', '.bgc-tab', function (e) {
    e.preventDefault();
    if ($(this).hasClass('bgc-tab-na') || this.disabled) { return; }
    var $wrap = $(this).closest('.bgc-fields');
    // the same tab again is a no-op: a full switch would clear the chosen office
    if (String($(this).data('method')) === method($wrap)) { return; }
    setMethod($wrap, $(this).data('method'));
  });

  $(document.body).on('change', 'input[name^="shipping_method"]', dimRates);

  $(document.body).on('click', 'ul#shipping_method > li', function (e) {
    var $radio = $(this).find('> input[name^="shipping_method"]').first();
    if (!$radio.length || $radio.prop('checked') || $radio.prop('disabled')) { return; }
    if ($(e.target).closest('label, a, button, input, select, textarea, .bgc-fields, .select2-container, .bgc-tip').length) { return; }
    $radio.prop('checked', true).trigger('change');
  });

  var addrT;
  $(document.body).on('input', '.bgc-address-rows input', function () {
    var $wrap = $(this).closest('.bgc-fields');
    clearTimeout(addrT); addrT = setTimeout(function () { saveSelection($wrap); }, 600);
  });
  // commit on leaving the field: a recalculation inside the debounce window would re-render from a stale session
  $(document.body).on('change blur', '.bgc-address-rows input', function () {
    var $wrap = $(this).closest('.bgc-fields');
    clearTimeout(addrT);
    saveSelection($wrap);
  });

  (function () {
    var e = (window.BGCOURIERS && BGCOURIERS.emergency) || {};
    if (!e.phone) { return; }
    var THRESH = 2, SHOWN = 'bgcouriers_emerg_shown', CNT = 'bgcouriers_fail_count';
    $(document.body).on('checkout_error', function () {
      try { if (localStorage.getItem(SHOWN)) { return; } } catch (x) {}
      var n = (parseInt(sessionStorage.getItem(CNT) || '0', 10) || 0) + 1;
      try { sessionStorage.setItem(CNT, n); } catch (x) {}
      if (n >= THRESH) { showEmergency(); try { localStorage.setItem(SHOWN, '1'); } catch (x) {} }
    });
    function showEmergency() {
      if ($('#bgc-emergency').length) { return; }
      var msg = e.message || (BGCOURIERS.i18n && BGCOURIERS.i18n.emerg_default) || '';
      var tel = String(e.phone).replace(/[^\d+]/g, '');
      $('body').append(
        '<div id="bgc-emergency" class="bgc-emerg-overlay"><div class="bgc-emerg-box">' +
        '<button type="button" class="bgc-emerg-close" aria-label="' + esc(BGCOURIERS.i18n && BGCOURIERS.i18n.close) + '">×</button>' +
        '<p class="bgc-emerg-msg">' + esc(msg) + '</p>' +
        '<a class="bgc-emerg-tel" href="tel:' + esc(tel) + '">' + esc(e.phone) + '</a>' +
        '</div></div>'
      );
      $('#bgc-emergency').on('click', function (ev) { if (ev.target === this) { $(this).remove(); } });
      $('#bgc-emergency .bgc-emerg-close').on('click', function () { $('#bgc-emergency').remove(); });
    }
  })();

  // ── The one thing the combined map dialog (bgc-allmap.js) can see ─────────────
  // module-level so a new applyPick can retire a listener an earlier one left behind
  var pendingOfficeApply = null;

  window.BGCouriersCheckout = {
    textMatch: bgcTextMatch,
    applyPick: function (pick) {
      var $wrap = $('.bgc-fields[data-courier="' + pick.courier + '"]');
      if (!$wrap.length) { return false; }
      // rate values may carry an instance id ("bgcouriers_speedy:5"): match the courier segment
      var want = 'bgcouriers_' + pick.courier;
      var $radio = $('input[name^="shipping_method"]').filter(function () {
        var v = String(this.value || '');
        return v === want || v.indexOf(want + ':') === 0;
      });
      // ticked without a change event: WooCommerce's own recalculation raced the save
      if ($radio.length) { $radio.prop('checked', true); dimRates(); }
      var $city = $wrap.find('.bgc-city');
      var newTown = String($city.val() || '') !== String(pick.cityId);
      $wrap.find('.bgc-postcode').val(pick.postCode || '');
      $city.append(new Option(pick.cityLabel, pick.cityId, true, true)).val(String(pick.cityId)).trigger('change');
      if (newTown) { resetStreet($wrap); }
      $wrap.attr('data-method', pick.method);
      syncMethodUI($wrap); syncOffice($wrap); syncMapBtn($wrap);
      var $office = $wrap.find('.bgc-office');
      resetOffice($wrap);
      $office.append(new Option(pick.officeLabel, pick.officeId, true, true)).val(String(pick.officeId)).trigger('change.select2');
      showLoader($wrap);
      if (pendingOfficeApply) { $(document.body).off('updated_checkout', pendingOfficeApply); pendingOfficeApply = null; }
      var restore = function () {
        var $w = $('.bgc-fields[data-courier="' + pick.courier + '"]');
        if (!$w.length) { return null; }
        if (String($w.find('.bgc-city').val() || '') !== String(pick.cityId)) {
          $w.find('.bgc-city').append(new Option(pick.cityLabel, pick.cityId, true, true)).val(String(pick.cityId));
        }
        $w.find('.bgc-postcode').val(pick.postCode || '');
        if (method($w) !== pick.method) { $w.attr('data-method', pick.method); syncMethodUI($w); }
        syncOffice($w); syncMapBtn($w);
        $w.find('.bgc-office').append(new Option(pick.officeLabel, pick.officeId, true, true)).val(String(pick.officeId)).trigger('change.select2');
        return $w;
      };
      saveSelection($wrap).always(function () {
        // one re-check after the save lands; a safety net, never a loop
        var check = function () {
          $(document.body).off('updated_checkout', check);
          pendingOfficeApply = null;
          var $w = $('.bgc-fields[data-courier="' + pick.courier + '"]');
          if (!$w.length || String($w.find('.bgc-office').val() || '') === String(pick.officeId)) { return; }
          var checkedVal = String($('input[name^="shipping_method"]:checked').val() || '');
          if (checkedVal !== want && checkedVal.indexOf(want + ':') !== 0) { return; }
          $w = restore();
          if ($w) { saveSelection($w); }
        };
        pendingOfficeApply = check;
        $(document.body).on('updated_checkout', check);
        $(document.body).trigger('update_checkout');
      });
      return true;
    }
  };
})(jQuery);
