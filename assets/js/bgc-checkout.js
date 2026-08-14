(function ($) {
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  // Bulgarian Cyrillic -> Latin transliteration, so a customer can type the city/office/APS in Latin letters
  // and still match the Cyrillic-named entries (official Наредба scheme).
  var BGCOURIERS_TR = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i',
    'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't',
    'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sht', 'ъ': 'a', 'ь': 'y', 'ю': 'yu', 'я': 'ya' };
  function bgcTranslit(s) {
    var l = (s == null ? '' : String(s)).toLowerCase(), out = '';
    for (var i = 0; i < l.length; i++) { out += (BGCOURIERS_TR[l[i]] !== undefined ? BGCOURIERS_TR[l[i]] : l[i]); }
    return out;
  }
  // True if `text` matches an already-lowercased `term` directly (Cyrillic) or via its Latin transliteration.
  function bgcTextMatch(text, term) {
    if (!term) { return true; }
    var t = (text == null ? '' : String(text)).toLowerCase();
    return t.indexOf(term) !== -1 || bgcTranslit(t).indexOf(term) !== -1;
  }
  // select2 renders its dropdown at the end of <body>, outside .bgc-fields, so our stylesheet cannot
  // reach it by nesting. Tag every dropdown we open with a class of our own and style that - otherwise
  // the search box inside keeps select2's default height and looks squashed next to our 38px fields.
  function sel2($el, opts) {
    opts = $.extend({ dropdownCssClass: 'bgc-drop' }, opts || {});
    return ($.fn.selectWoo ? $el.selectWoo(opts) : $el.select2(opts));
  }
  // Suppress select2's "results could not be loaded" flash when it aborts an in-flight search on fast typing.
  function noAbortTransport(params, success, failure) {
    var req = $.ajax(params);
    req.then(success);
    req.fail(function (x, status) { if (status !== 'abort') { failure(); } });
    return req;
  }

  function courier($wrap) { return $wrap.attr('data-courier') || 'speedy'; }
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
  function resetStreet($wrap) {
    var $s = $wrap.find('.bgc-street');
    if ($s.hasClass('select2-hidden-accessible')) { $s.val(null).trigger('change.select2'); }
    $s.empty().append(new Option('', '', false, false));
    $wrap.find('.bgc-street-no').val('');
  }

  function setMethod($wrap, m) {
    $wrap.attr('data-method', m);
    syncMethodUI($wrap);
    if (m !== 'address') { resetOffice($wrap); }
    showLoader($wrap);
    pushSelection($wrap); // saves method + recalc; loader cleared on updated_checkout
    if (m !== 'address') { preloadOffices($wrap); }
  }

  // Per-city availability: grey out + disable a delivery option the chosen city has none of.
  var availCache = {};
  function methodOk(av, m) { return m === 'address' || (m === 'office' && av.office) || (m === 'automat' && av.automat); }
  // Derive availability from the preloaded index (no AJAX) when the switch is on; else null -> AJAX below.
  function cityAvailLocal($wrap, city) {
    if (!BGCOURIERS.preloadCities) { return null; }
    var idx = BGCOURIERS.cityIndex && BGCOURIERS.cityIndex[courier($wrap)];
    if (!idx) { return null; }
    function has(list) { var a = idx[list] || []; for (var i = 0; i < a.length; i++) { if (a[i][0] == city) { return true; } } return false; }
    return { office: has('office'), automat: has('automat') };
  }
  function applyAvail($wrap) {
    var city = $wrap.find('.bgc-city').val() || 0;
    if (!city) { $wrap.find('.bgc-tab').removeClass('bgc-tab-na').prop('disabled', false).attr('title', ''); return; }
    var key = courier($wrap) + ':' + city;
    if (availCache[key] === undefined) {
      var local = cityAvailLocal($wrap, city);
      if (local) { availCache[key] = local; }
      else {
        $.get(BGCOURIERS.ajax, { action: 'bgcouriers_city_avail', courier: courier($wrap), city_id: city }, function (res) {
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
      // Marked unavailable WITHOUT the disabled property. A disabled button receives no mouse events, so
      // the browser never shows its title - the explanation was being attached to the one element that
      // could not deliver it, and the tab simply looked as though this courier does not do lockers at
      // all rather than "not in this town". The click handler already ignores .bgc-tab-na, so nothing
      // was relying on `disabled` to stop the press; aria-disabled carries the state for assistive
      // technology, which the class alone would not.
      $t.toggleClass('bgc-tab-na', !ok)
        .prop('disabled', false)
        .attr('aria-disabled', ok ? null : 'true')
        .attr('title', ok ? '' : (BGCOURIERS.i18n.na_city || ''));
      if (ok && firstOk === null) { firstOk = m; }
    });
    if (methodOk(av, method($wrap)) || !firstOk) { $wrap.data('bgc-availed', true); return; }
    // The active delivery option is not available here, so move to one that is. On the FIRST render this
    // fires while the checkout is still settling and setMethod() would trigger a second full
    // update_checkout - a second spinner and a second round of live courier price calls, which is the
    // long wait customers were seeing. Nothing is priced yet at that point anyway (no city chosen), so
    // switch the UI and save the choice quietly; the price is recalculated as soon as a city is picked.
    if (settled) {
        setMethod($wrap, firstOk);            // a later, user-driven change: recalculate as usual
    } else {
        $wrap.attr('data-method', firstOk);
        syncMethodUI($wrap);
        saveSelection($wrap);                  // remember it without a checkout round-trip
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
        // When "preload cities" is on (default) + we have the index, office/automat searches the preloaded
        // cities-with-offices locally (instant, no AJAX). Otherwise - and always for address - the original
        // AJAX path (noAbortTransport) runs unchanged.
        transport: function (params, success, failure) {
          var m = method($wrap), cour = courier($wrap);
          var idx = BGCOURIERS.cityIndex && BGCOURIERS.cityIndex[cour];
          if (BGCOURIERS.preloadCities && m !== 'address' && idx && idx[m]) {
            var term = ((params.data && params.data.term) || '').toLowerCase(), rows = idx[m], out = [];
            for (var i = 0; i < rows.length && out.length < 200; i++) {
              var a = rows[i]; // [city_id, name, post_code, name_lat]
              // Match the Cyrillic name (and its Latin transliteration), the official Latin name, or postcode -
              // so typing "sofia"/"София"/"1000" all find гр. София.
              if (!term || bgcTextMatch(a[1], term) || (a[3] && String(a[3]).toLowerCase().indexOf(term) !== -1) || String(a[2]).indexOf(term) !== -1) {
                out.push({ city_id: a[0], name: a[1], post_code: a[2] });
              }
            }
            success(out); return { abort: function () {} };
          }
          return noAbortTransport(params, success, failure); // original AJAX path, untouched
        },
        data: function (params) { return { action: 'bgcouriers_search_cities', courier: courier($wrap), term: params.term || '' }; },
        processResults: function (rows) {
          var counts = {};
          rows.forEach(function (r) { counts[r.name] = (counts[r.name] || 0) + 1; });
          return { results: rows.map(function (r) {
            // Postcode in the label: lets people search/pick by it and tells apart same-named villages.
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
      resetOffice($wrap); resetStreet($wrap); showLoader($wrap); pushSelection($wrap); preloadOffices($wrap);
      idle(function () { prefetchOtherCouriers($wrap.find('.bgc-city').val() || 0, method($wrap)); });
    });
    // Clearing the city must re-run availability (re-enable the greyed options) + recalc.
    $city.on('select2:clear', function () {
      $wrap.find('.bgc-postcode').val('');
      $wrap.find('.bgc-map-btn').prop('disabled', true).attr('title', (BGCOURIERS.i18n && BGCOURIERS.i18n.office_need_city) || '');
      resetOffice($wrap); resetStreet($wrap); showLoader($wrap); pushSelection($wrap); preloadOffices($wrap);
      idle(function () { prefetchOtherCouriers($wrap.find('.bgc-city').val() || 0, method($wrap)); });
    });
  }

  // Office / automat - preloaded per city+method, cached client-side until refresh; search is then local.
  var officeCache = {}; // 'courier:city:type' -> all office rows for that city+type

  /* Office lists are cached per TAB in sessionStorage, not preloaded for the whole country: every city
     for every courier is ~137 KB gzipped, which no shopper needs - they pick one city. Caching what was
     actually fetched means going back to a city, or reloading the checkout, costs no request at all, and
     sessionStorage bounds how stale it can get (it dies with the tab; the server also caches 6h). */
  var OFFICE_STORE = 'bgcouriers_off:';
  function cacheGet(key) {
    if (officeCache[key] !== undefined) { return officeCache[key]; }
    try {
      var raw = window.sessionStorage && sessionStorage.getItem(OFFICE_STORE + key);
      if (raw) { officeCache[key] = JSON.parse(raw); return officeCache[key]; }
    } catch (e) { /* private mode / bad JSON - fall through to a fetch */ }
    return undefined;
  }
  function cacheSet(key, rows) {
    officeCache[key] = rows || [];
    try { window.sessionStorage && sessionStorage.setItem(OFFICE_STORE + key, JSON.stringify(officeCache[key])); }
    catch (e) { /* quota or private mode - the in-memory cache still does its job */ }
  }
  function idle(fn) {
    if (window.requestIdleCallback) { requestIdleCallback(fn, { timeout: 2000 }); } else { setTimeout(fn, 400); }
  }

  function preloadOffices($wrap) {
    var city = $wrap.find('.bgc-city').val() || 0, m = method($wrap);
    if (!city || m === 'address') { return; }
    var key = courier($wrap) + ':' + city + ':' + m;
    if (cacheGet(key) !== undefined) { return; }
    $.get(BGCOURIERS.ajax, { action: 'bgcouriers_offices', courier: courier($wrap), city_id: city, type: m, all: 1 },
      function (rows) { cacheSet(key, rows); });
  }

  /**
   * Store the office the SERVER chose when a town turned out to have exactly one.
   *
   * The choosing happens in render_fields, not here: this block is re-rendered on every recalculation,
   * so a selection made in the browser was made and then wiped by the very next round - the same race
   * that has caught this file before. The server renders it as the selected option and marks the field
   * data-auto; all that is left is to put it in the session once, so the ORDER carries what the customer
   * can plainly see in the field. Saved without a recalculation, exactly as picking one by hand is.
   */
  function autoPickSingle($wrap) {
    var $o = $wrap.find('.bgc-office');
    if (!$o.length || $o.attr('data-auto') !== '1') { return; }
    if (String($o.val() || '') === '') { return; }
    $o.removeAttr('data-auto');      // once per render; the session now has it
    saveSelection($wrap);
  }

  /* Once a city is known, quietly fetch the SAME city for the other couriers on the page, so switching
     courier is instant instead of costing a round-trip. Runs at idle, one request at a time, and skips
     anything already cached - it is a nicety, never allowed to compete with what the shopper is doing. */
  function prefetchOtherCouriers(city, m) {
    if (!city || m === 'address') { return; }
    var seen = {}, queue = [];
    $('.bgc-fields').each(function () {
      var c = $(this).attr('data-courier');
      if (!c || seen[c]) { return; }
      seen[c] = 1;
      if (cacheGet(c + ':' + city + ':' + m) === undefined) { queue.push(c); }
    });
    (function next() {
      if (!queue.length) { return; }
      var c = queue.shift();
      $.get(BGCOURIERS.ajax, { action: 'bgcouriers_offices', courier: c, city_id: city, type: m, all: 1 })
        .done(function (rows) { cacheSet(c + ':' + city + ':' + m, rows); })
        .always(function () { idle(next); });
    })();
  }

  /**
   * The map needs a city before it can plot anything, so its button follows the city field.
   *
   * It used to be decided ONCE, while the block was being built. That is fine when a person picks the
   * city themselves - the block is rebuilt afterwards and the answer comes out right - but not when
   * the city arrives from the combined map: the block re-renders, this runs before the city has
   * landed in it, and the button stays greyed out for good with a city plainly sitting above it.
   * Bound to the field instead, so it can never disagree with what the field says.
   */
  function syncMapBtn($wrap) {
    var hasCity = !!$wrap.find('.bgc-city').val();
    $wrap.find('.bgc-map-btn:not(.bgc-addr-map-btn)').prop('disabled', !hasCity)
      .attr('title', hasCity ? '' : ((BGCOURIERS.i18n && BGCOURIERS.i18n.office_need_city) || ''));
  }
  $(document).on('change', '.bgc-fields .bgc-city', function () { syncMapBtn($(this).closest('.bgc-fields')); });

  function initOffice($wrap) {
    var $office = $wrap.find('.bgc-office');
    var hasCity = !!$wrap.find('.bgc-city').val();
    syncMapBtn($wrap);
    if ($office.hasClass('select2-hidden-accessible')) { return; }
    $office.prop('disabled', !hasCity); // no office search until a city is chosen
    sel2($office, {
      width: '100%', allowClear: true, minimumInputLength: 0, placeholder: hasCity ? ((BGCOURIERS.i18n && BGCOURIERS.i18n.office_ph) || '') : ((BGCOURIERS.i18n && BGCOURIERS.i18n.office_need_city) || ''),
      ajax: {
        delay: 0,
        transport: function (params, success, failure) {
          var d = params.data, key = d.courier + ':' + d.city_id + ':' + d.type, term = (d.term || '').toLowerCase();
          function done(rows) {
            success(term ? rows.filter(function (o) {
              // Office names/addresses are Cyrillic only - also match their Latin transliteration so a
              // Latin-typed term (e.g. "mladost", "metro") finds them.
              return bgcTextMatch(o.name, term) || (String(o.office_id).indexOf(term) !== -1) || bgcTextMatch(o.address, term);
            }) : rows);
          }
          var hit = cacheGet(key);
          if (hit !== undefined) { done(hit); return { abort: function () {} }; } // cached: no request at all
          var req = $.get(BGCOURIERS.ajax, { action: 'bgcouriers_offices', courier: d.courier, city_id: d.city_id, type: d.type, all: 1 });
          req.done(function (rows) { cacheSet(key, rows); done(officeCache[key]); });
          req.fail(function (x, status) { if (status !== 'abort') { failure(); } });
          return req;
        },
        data: function (params) {
          return { courier: courier($wrap), city_id: $wrap.find('.bgc-city').val() || 0, type: method($wrap), term: params.term || '' };
        },
        processResults: function (rows) {
          return { results: rows.map(function (o) { return { id: o.office_id, text: o.name + ' - ' + o.address }; }) };
        }
      }
    });
    // Picking/clearing a specific office in the same city doesn't change the price (it's per city+weight),
    // so save it without a recalc/loader - no more blinking on this "elementary" action.
    $office.on('select2:select', function () { saveSelection($wrap); });
    $office.on('select2:clear', function () { saveSelection($wrap); });
  }

  // Shared with the ADDRESS map picker below, which is a different feature and stays: it drops a pin
  // to fill in a street address, rather than choosing a pickup point.
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
  // There used to be a second, per-courier map here. It answered a narrower question than the
  // combined one - this courier's offices, when the customer's real question is "where can I collect
  // this" - and keeping two maps meant every fix landed twice and they drifted apart anyway. The
  // button now opens the SAME dialog, filtered to this courier and seeded with the city already
  // chosen here; the customer can switch the other couriers on from its legend, and choosing one of
  // their points moves the whole selection over exactly as it does from the map's own button.
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
      if (geo.street) { $wrap.find('.bgc-street').append(new Option(geo.street, geo.street, true, true)).trigger('change'); }
      if (geo.number) { $wrap.find('.bgc-street-no').val(geo.number); }
      resetOffice($wrap); showLoader($wrap); pushSelection($wrap); // recalc for the (possibly new) city
    }
    function findCity(term, cb) {
      if (!term) { cb(null); return; }
      $.get(BGCOURIERS.ajax, { action: 'bgcouriers_search_cities', courier: courier($wrap), term: term }, function (rows) {
        cb((rows && rows.length) ? rows[0] : null);
      });
    }
    // The city NAME is the reliable key into the courier nomenclature; a map point's specific postcode is
    // often not the city's representative postcode, so it is only a fallback. Never keep the previously
    // chosen city - that leaves city, postcode and street disagreeing (e.g. Plovdiv with a Sofia postcode).
    findCity(geo.city, function (r) {
      if (r) { pickCity(r); fields(geo.postcode || r.post_code || ''); return; }
      findCity(geo.postcode, function (r2) {
        if (r2) { pickCity(r2); fields(geo.postcode || r2.post_code || ''); }
        else { $city.val('').trigger('change'); fields(geo.postcode || ''); } // no match - clear so the customer picks the city
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
    locate(); // auto-locate + drop a pin on open
    $ov.find('.bgc-addr-use').on('click', function () { fillAddress($wrap, current); closeMap(); });
    setTimeout(function () { if (bgcMap) { bgcMap.invalidateSize(); } }, 60);
  }
  $(document).on('click', '.bgc-addr-map-btn', function (e) { e.preventDefault(); openAddressMap($(this).closest('.bgc-fields')); });

  // Street (autocomplete via /location/street; tags:true keeps free-typed streets working).
  function initStreet($wrap) {
    var $street = $wrap.find('.bgc-street');
    if (!$street.length || $street[0].tagName !== 'SELECT' || $street.hasClass('select2-hidden-accessible')) { return; }
    sel2($street, {
      width: '100%', tags: true, allowClear: true, minimumInputLength: 2, placeholder: (BGCOURIERS.i18n && BGCOURIERS.i18n.street_ph) || '',
      ajax: {
        url: BGCOURIERS.ajax, dataType: 'json', delay: 250, transport: noAbortTransport,
        data: function (params) { return { action: 'bgcouriers_streets', courier: courier($wrap), city_id: $wrap.find('.bgc-city').val() || 0, term: params.term || '' }; },
        processResults: function (rows) { return { results: rows.map(function (s) { return { id: s.name, text: s.label || s.name }; }) }; }
      },
      createTag: function (params) { var t = (params.term || '').trim(); return t ? { id: t, text: t } : null; }
    });
    $street.on('select2:select', function () { saveSelection($wrap); });
  }

  // Save the selection ------------------------------------------------------
  function selectionData($wrap) {
    return {
      action: 'bgcouriers_set_selection', nonce: BGCOURIERS.nonce, courier: courier($wrap), method: method($wrap),
      site_id: $wrap.find('.bgc-city').val() || 0,
      office_id: $wrap.find('.bgc-office').val() || 0,
      post_code: $wrap.find('.bgc-postcode').val() || '',
      street_name: $wrap.find('.bgc-street').val() || '', street_no: $wrap.find('.bgc-street-no').val() || '',
      complex: $wrap.find('.bgc-complex').val() || '', block: $wrap.find('.bgc-block').val() || '',
      entrance: $wrap.find('.bgc-entrance').val() || '', floor: $wrap.find('.bgc-floor').val() || '',
      apartment: $wrap.find('.bgc-apartment').val() || '', address_note: $wrap.find('.bgc-note').val() || ''
    };
  }
  function pushSelection($wrap) { showLoader($wrap); $.post(BGCOURIERS.ajax, selectionData($wrap), function () { $(document.body).trigger('update_checkout'); }); }
  function saveSelection($wrap) { $.post(BGCOURIERS.ajax, selectionData($wrap)); } // save without recalc (address details don't change price)

  // BOX NOW locker picker - the official map widget (built-in GPS "nearest to me") ---------
  function boxnowUrl() {
    var c = BGCOURIERS.boxnow || {}, p = [];
    if (c.partnerId) { p.push('partnerId=' + encodeURIComponent(c.partnerId)); }
    p.push('countryCode=' + encodeURIComponent(c.country || 'bg'));
    p.push('language=' + encodeURIComponent(c.country || 'bg'));
    p.push('gps=' + (c.gps === 'no' ? 'no' : 'yes'));
    return (c.widget || 'https://map.boxnow.bg/iframe.html') + '?' + p.join('&');
  }
  var boxnowWrap = null;
  function openBoxnow($wrap) {
    boxnowWrap = $wrap;
    var $ov = $('<div class="bgc-boxnow-overlay"><div class="bgc-boxnow-modal">'
      + '<button type="button" class="bgc-boxnow-close" aria-label="' + esc(BGCOURIERS.i18n && BGCOURIERS.i18n.close) + '">×</button>'
      + '<iframe class="bgc-boxnow-frame" src="' + esc(boxnowUrl()) + '" allow="geolocation"></iframe>'
      + '</div></div>');
    $ov.on('click', function (e) { if (e.target === $ov[0] || $(e.target).hasClass('bgc-boxnow-close')) { $ov.remove(); } });
    $('body').append($ov);
  }
  function closeBoxnow() { $('.bgc-boxnow-overlay').remove(); }
  function pickBoxnow(d) {
    var $wrap = (boxnowWrap && boxnowWrap.length) ? boxnowWrap : $('.bgc-fields.bgc-boxnow:visible').first();
    if (!$wrap.length || !d.boxnowLockerId) { return; }
    var name = d.boxnowLockerName || '', addr = d.boxnowLockerAddressLine1 || '';
    $wrap.find('.bgc-boxnow-id').val(d.boxnowLockerId);
    $wrap.find('.bgc-boxnow-name').text(name);
    $wrap.find('.bgc-boxnow-addr').text(addr ? ' ' + addr : '');
    $wrap.find('.bgc-boxnow-selected').show();
    closeBoxnow(); showLoader($wrap);
    $.post(BGCOURIERS.ajax, { action: 'bgcouriers_set_selection', nonce: BGCOURIERS.nonce, courier: 'boxnow', method: 'automat', office_id: d.boxnowLockerId, boxnow_name: name, boxnow_addr: addr },
      function () { $(document.body).trigger('update_checkout'); });
  }
  window.addEventListener('message', function (event) {
    var d = event.data;
    if (d === 'closeIframe') { closeBoxnow(); return; }
    if (typeof d === 'string') { try { d = JSON.parse(d); } catch (e) { return; } }
    if (!d || typeof d !== 'object') { return; }
    if (d.boxnowClose !== undefined) { closeBoxnow(); return; }
    if (d.boxnowLockerId) { pickBoxnow(d); }
  });
  $(document.body).on('click', '.bgc-boxnow-pick', function (e) { e.preventDefault(); openBoxnow($(this).closest('.bgc-fields')); });

  // Wiring ------------------------------------------------------------------
  // The chosen bgcouriers_<id> shipping method's courier id (each courier renders its own .bgc-fields).
  function chosenCourier() {
    var v = $('input[name^="shipping_method"]:checked').val()
         || $('input[name^="shipping_method"][type="hidden"]').val() || '';
    var m = String(v).match(/^bgcouriers_([a-z0-9]+)/);
    return m ? m[1] : '';
  }

  // Dim the label + price of the shipping methods that aren't selected (you only pay for the chosen one).
  function dimRates() {
    $('input[name^="shipping_method"]').each(function () {
      $('label[for="' + this.id + '"]').toggleClass('bgc-rate-inactive', !this.checked);
    });
  }

  // Fade the chosen courier's fields in once they are fully built, instead of flashing raw selects on load.
  // Only reveals the first time (persists across totals refreshes); re-reveals when you switch couriers.
  function reveal($wrap) {
    if ($wrap.hasClass('bgc-ready')) { return; }
    window.requestAnimationFrame(function () { $wrap.addClass('bgc-ready'); });
  }

  $(document.body).on('updated_checkout', function () {
    dimRates();
    if (!$('.bgc-fields').length) return;
    var chosen = chosenCourier();
    $('.bgc-fields').each(function () {
      var $wrap = $(this);
      var mine = $wrap.attr('data-courier') === chosen;
      if (!mine) { $wrap.hide().removeClass('bgc-ready'); return; } // hide (and re-arm) the other couriers' fields
      $wrap.show(); // show only the chosen courier's fields (multiple couriers can share a zone)
      if ($wrap.hasClass('bgc-boxnow')) { hideLoader($wrap); reveal($wrap); return; } // locker picked via the map widget - nothing to init
      renderTabs($wrap); initCity($wrap); initOffice($wrap); initStreet($wrap); syncMethodUI($wrap); applyAvail($wrap); autoPickSingle($wrap); hideLoader($wrap);
      reveal($wrap);
    });
  });

  $(document.body).on('click', '.bgc-tab', function (e) {
    e.preventDefault();
    if ($(this).hasClass('bgc-tab-na') || this.disabled) { return; } // unavailable for this city
    var $wrap = $(this).closest('.bgc-fields');
    // Clicking the tab you are already on is a no-op. It used to run the full switch: the office was
    // cleared, the whole list re-fetched and the checkout recalculated - so a customer who tapped
    // "To office" a second time, or hit it while already there, silently lost the office they had
    // chosen. Nothing about the delivery type has changed, so nothing should happen.
    if (String($(this).data('method')) === method($wrap)) { return; }
    setMethod($wrap, $(this).data('method'));
  });

  $(document.body).on('change', 'input[name^="shipping_method"]', dimRates);

  // Let the WHOLE courier row select it, not just the name - a much bigger target, which matters most
  // on a phone. The row of the CHOSEN courier also contains its delivery form, so any click on a real
  // control (or on the label/radio, where the browser already does the right thing) is left alone.
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
  // The debounce above is there so every keystroke does not post, but it leaves a 600ms window in
  // which a recalculation started by something else re-renders this block from a session that has not
  // been told what was typed - and the house number the customer just entered is simply gone. Leaving
  // the field commits it at once, which is the moment they stop typing and reach for something else.
  $(document.body).on('change blur', '.bgc-address-rows input', function () {
    var $wrap = $(this).closest('.bgc-fields');
    clearTimeout(addrT);
    saveSelection($wrap);
  });

  // Emergency help: after repeated checkout failures, show a one-time help box with a phone link.
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
  // That dialog is a separate script and everything in here is private to this IIFE, so this is the
  // entire contract between them: hand over a chosen point and let the ordinary flow do the rest -
  // pick the courier's rate, switch its tab, set city and office, save, recalculate. Nothing about
  // what the order records lives in the other file.
  // Left over from an in-flight applyPick's step 5 below, if one hasn't finished yet - kept here (not a
  // closure-local) so a NEW applyPick can find and retire it instead of leaving it listening forever.
  var pendingOfficeApply = null;

  window.BGCouriersCheckout = {
    // Shared with bgc-allmap.js so its city box can filter the preloaded index the same way this one
    // does - "sofia", "София" and "1000" all finding гр. София. Exposed rather than copied: a second
    // transliteration table would drift from this one the first time either was corrected.
    textMatch: bgcTextMatch,
    applyPick: function (pick) {
      var $wrap = $('.bgc-fields[data-courier="' + pick.courier + '"]');
      if (!$wrap.length) { return false; }               // that courier is not offered for this cart
      // 1. the courier's own rate row - this is what WooCommerce charges for
      // WooCommerce rate values carry an instance id ("bgcouriers_speedy:5"), and some do not, so an
      // exact-value selector silently matches nothing on a real checkout. Match the courier segment
      // and let the colon end it - no courier id is a prefix of another.
      var want = 'bgcouriers_' + pick.courier;
      var $radio = $('input[name^="shipping_method"]').filter(function () {
        var v = String(this.value || '');
        return v === want || v.indexOf(want + ':') === 0;
      });
      if ($radio.length) { $radio.prop('checked', true).trigger('change'); }
      // 2. its delivery-type tab
      setMethod($wrap, pick.method);
      // 3. the city. Its change starts an async office reload, and pushSelection below triggers
      //    update_checkout, which re-renders this whole block - so the office cannot be written here
      //    and survive. It is set once the block is final, below.
      var $city = $wrap.find('.bgc-city');
      // The POST CODE travels with the city, and it is not decoration: it is the only courier-agnostic
      // handle on a place. Switching courier resolves the same town for the new one from this value
      // (render_fields' carry branch), so a pick made on the map that left it empty meant the city
      // silently vanished the moment the customer tried another courier.
      $wrap.find('.bgc-postcode').val(pick.postCode || '');
      $city.append(new Option(pick.cityLabel, pick.cityId, true, true)).val(String(pick.cityId)).trigger('change');

      // 4. the same save + recalculate a manual pick performs
      pushSelection($wrap);

      // 5. the office, on the re-rendered block. The radio's own `change`, setMethod(), the city's
      //    `change` and the pushSelection above each start their OWN checkout recalculation, so several
      //    independent 'updated_checkout' events can land before the block is actually final. A `.one()`
      //    subscription is spent by whichever fires FIRST - often one of the earlier, not-yet-settled
      //    rounds - so the office write never happens; it only looked reliable on dev because WooCommerce
      //    happens to abort the earlier in-flight request there. Subscribe with `.on()` instead.
      //
      //    Which round is "the settled one" is not knowable from here, so write the office on every round
      //    instead of guessing - self-correcting whatever order the rounds land in. But an UNBOUNDED
      //    version of that is worse than the race it replaces: left running, it would (a) leak - keep
      //    firing on every future checkout edit for the rest of the session once the customer switches to
      //    a different courier, (b) override the customer - silently put this office back the moment they
      //    pick a DIFFERENT one by hand, since any value but pick.officeId reads as "not settled yet", and
      //    (c) hammer the server with a real save on every single recalculation. So retire the handler the
      //    moment ANY of three things is true: the value stuck, the customer's chosen rate is no longer
      //    this pick's courier, or it has already written the office 3 times without it sticking (past
      //    that many rounds something else owns this field, and continuing to fight it is the harm this
      //    guard exists to prevent). Also retire whatever was left over from a PREVIOUS applyPick, so a
      //    pick nobody wants applied any more doesn't keep listening either.
      if (pendingOfficeApply) { $(document.body).off('updated_checkout', pendingOfficeApply); }
      var officeTries = 0;
      var onOfficeRound = function () {
        var $w = $('.bgc-fields[data-courier="' + pick.courier + '"]');
        var $o = $w.find('.bgc-office');
        function done() {
          $(document.body).off('updated_checkout', onOfficeRound);
          pendingOfficeApply = null;
        }
        if (!$o.length) { return; }
        if (String($o.val() || '') === String(pick.officeId)) { done(); return; }         // 1. it stuck
        // 2. the customer moved on to a different courier - same match as the radio above (want + the
        //    colon guard), against whichever rate is actually CHECKED now.
        var checkedVal = String($('input[name^="shipping_method"]:checked').val() || '');
        if (checkedVal !== want && checkedVal.indexOf(want + ':') !== 0) { done(); return; }
        if (officeTries >= 3) { done(); return; }                                          // 3. gave it enough tries
        officeTries++;
        // The delivery TYPE has to be put back too, and before the rest. On one of the recalculations
        // this pick sets off, the server can render this block with an EMPTY data-method - the session
        // has not recorded the courier yet at that moment - and renderTabs() then falls back to the
        // courier's FIRST tab. For Sameday that is "to address", so choosing a locker on the map
        // landed the customer on an empty address form with the locker still saved underneath.
        // Set the attribute and re-sync the tabs directly rather than calling setMethod(): that one
        // clears the office and starts another recalculation, which is what we are already inside of.
        if (method($w) !== pick.method) {
          $w.attr('data-method', pick.method);
          syncMethodUI($w);
        }
        // The CITY has to be put back first. saveSelection() below sends the whole block - courier,
        // city, office, address - read straight off the fields, so saving while the re-rendered city
        // select is still empty stores site_id 0 and wipes the city that was chosen a moment ago. The
        // customer was left with an office and no town, which is not a state they can order from.
        var $c = $w.find('.bgc-city');
        if ($c.length && String($c.val() || '') !== String(pick.cityId)) {
          $c.append(new Option(pick.cityLabel, pick.cityId, true, true)).val(String(pick.cityId));
        }
        // Re-asserted with the city, for the same reason: this block was re-rendered by the server and
        // the hidden field came back with whatever the session had, which may be nothing yet.
        $w.find('.bgc-postcode').val(pick.postCode || '');
        $o.append(new Option(pick.officeLabel, pick.officeId, true, true)).val(String(pick.officeId)).trigger('change');
        saveSelection($w);
      };
      pendingOfficeApply = onOfficeRound;
      $(document.body).on('updated_checkout', onOfficeRound);
      return true;
    }
  };
})(jQuery);
