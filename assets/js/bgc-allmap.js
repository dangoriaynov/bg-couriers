/* Combined office/APS map dialog: every enabled courier's pickup points for one place. Talks to the checkout only through BGCouriersCheckout.applyPick(). */
(function ($) {
  // admin-ajax answers a bare 0 (truthy) when the action is missing, so only a real array passes
  function bgcList(rows) { return Array.isArray(rows) ? rows : []; }

  var I = (window.BGCOURIERS && BGCOURIERS.i18n) || {};
  var STORE = 'bgcouriers_map_pick';
  // a place by name, not an id: city ids belong to the courier that issued them
  var state = { cityName: '', cityCode: '', cityLabel: '', citySeed: '' };
  // where distances are measured from; worked out in the browser and never sent anywhere
  var origin = null;
  // placed by the dialog (the chosen office, else the town's centre) until the customer moves it: it follows the town
  var originAuto = false;
  // the current render's pins, wiped as one on the next render (a stale pin's popup would index the new points array)
  var $dlg = null, map = null, layer = null, markers = [], points = [], cache = {};
  var availT = null, availSeq = 0;
  // clusters = the bubbles, clustered[i] = point i is inside one; only paintPins() writes pin visibility
  var clusters = null, clustered = [];
  var CLUSTER_CELL = 56;      // px at the current zoom: pins closer than this share a bubble
  var CLUSTER_OFF_ZOOM = 16;  // from here in every pin stands alone
  var hidden = {};
  var current = { courier: '', officeId: '' };
  var mode = 'map';
  // destination country, fixed at open: decides whether the preloaded index may be consulted
  var dest = '';

  var dists = [], rowEls = [];

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

  // 'no' rather than a falsy test: wp_localize_script() hands PHP false over as ''
  function nearOn() { return !window.BGCOURIERS || BGCOURIERS.allmapNearest !== 'no'; }

  function nextFrame(fn) {
    if (window.requestAnimationFrame) { window.requestAnimationFrame(fn); } else { setTimeout(fn, 16); }
  }

  function distanceM(a, b) {
    var R = 6371000, toRad = Math.PI / 180;
    var dLat = (b.lat - a.lat) * toRad, dLng = (b.lng - a.lng) * toRad;
    var la1 = a.lat * toRad, la2 = b.lat * toRad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
          + Math.sin(dLng / 2) * Math.sin(dLng / 2) * Math.cos(la1) * Math.cos(la2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  function fmtDist(m) {
    if (m == null) { return ''; }
    if (m < 950) { return Math.round(m / 10) * 10 + '\u00a0' + (I.near_m || 'm'); }
    return (Math.round(m / 100) / 10).toString().replace('.', ',') + '\u00a0' + (I.near_km || 'km');
  }
  function distOf(p) {
    if (!origin) { return null; }
    var lat = Number(p.office.lat), lng = Number(p.office.lng);
    if (!lat && !lng) { return null; }
    return distanceM(origin, { lat: lat, lng: lng });
  }
  function amount(text) {
    var m = String(text || '').replace(/[\s\u00a0]/g, '').match(/\d+(?:[.,]\d+)?/);
    return m ? parseFloat(m[0].replace(',', '.')) : null;
  }
  // a house number left alone on the last line reads as a stray: bind it to its marker and the word before
  function addrText(s) {
    return String(s || '')
      .replace(/(^|[ \t])(No|№|бл\.|вх\.|ет\.|ап\.)[ \t]+(?=\S)/g, function (m, pre, w) { return pre + w + '\u00a0'; })
      .replace(/[ \t]+([^ \t]{1,9})$/, '\u00a0$1');
  }
  function money(v, like) {
    var m = String(like || '').match(/\d+(?:([.,])\d+)?/);
    if (!m) { return ''; }
    return String(like).replace(m[0], v.toFixed(2).replace('.', m[1] || ','));
  }

  function homeIndexCountry() {
    var B = window.BGCOURIERS || {};
    return String(B.cityIndexCountry || B.homeCountry || 'BG');
  }
  function checkoutCountry($wrap) {
    var $w = ($wrap && $wrap.length) ? $wrap : $('.bgc-fields[data-courier]:visible').first();
    if (!$w.length) { return homeIndexCountry(); }
    var $sel = $w.find('.bgc-country');
    return String(($sel.length && $sel.val()) || $w.attr('data-country') || homeIndexCountry());
  }

  var cityList = null;
  function buildCityList() {
    var idx = (window.BGCOURIERS && BGCOURIERS.cityIndex) || null;
    if (!idx) { return null; }
    var seen = {}, out = [];
    Object.keys(idx).forEach(function (cid) {
      ['office', 'automat'].forEach(function (type) {
        (idx[cid] && idx[cid][type] ? idx[cid][type] : []).forEach(function (r) {
          var name = r[1], code = String(r[2] || '');
          var key = String(name).toLowerCase() + '|' + code;
          if (seen[key]) { return; }
          seen[key] = true;
          out.push({ name: name, post_code: code, lat: String(r[3] || '') });
        });
      });
    });
    out.sort(function (a, b) { return a.name.localeCompare(b.name); });
    return out;
  }

  function localCities(term) {
    // abroad the index holds nothing of that country at all; the server is asked instead
    if (dest && dest !== homeIndexCountry()) { return null; }
    if (cityList === null) { cityList = buildCityList() || false; }
    if (!cityList || !cityList.length) { return null; }
    var match = (window.BGCouriersCheckout && window.BGCouriersCheckout.textMatch)
      || function (text, t) { return String(text).toLowerCase().indexOf(t) !== -1; };
    var t = term.toLowerCase(), out = [];
    for (var i = 0; i < cityList.length && out.length < 30; i++) {
      var c = cityList[i];
      if (match(c.name, t) || (c.lat && c.lat.toLowerCase().indexOf(t) !== -1) || c.post_code.indexOf(t) !== -1) {
        out.push({ name: c.name, post_code: c.post_code });
      }
    }
    return out;
  }

  function busyCity(on) {
    if (!$dlg) { return; }
    $dlg.find('.bgc-allmap-cityrow').toggleClass('bgc-loading', !!on);
  }

  // fixed per courier; no greens or greys, those are OpenStreetMap's own
  var PIN_COLOURS = {
    speedy:  '#D00030',
    econt:   '#204080',
    pigeon:  '#F08010',
    boxnow:  '#5A2BC4',
    // sameday, expressone, evropat: their brand colours collide with other pins, so deliberately off-brand
    sameday: '#00838F',
    expressone: '#E0189B',
    evropat: '#FFCC00'
  };
  var PIN_FALLBACK = ['#6A1B9A', '#00838F', '#4E342E', '#1B5E20', '#37474F'];
  var pinColour = {};
  function colourFor(courierId) {
    if (PIN_COLOURS[courierId]) { return PIN_COLOURS[courierId]; }
    if (!pinColour[courierId]) {
      pinColour[courierId] = PIN_FALLBACK[Object.keys(pinColour).length % PIN_FALLBACK.length];
    }
    return pinColour[courierId];
  }

  function typeGlyph(type) {
    var icons = (window.BGCOURIERS && BGCOURIERS.icons) || {};
    return '<span class="bgc-allmap-kind" title="' + esc(typeLabel(type)) + '">' + (icons[type] || '') + '</span>';
  }
  function priceLabel(p) { return (p.estimated ? '~ ' : '') + p.price; }

  function typeLabel(type) { return (type === 'automat' ? I.automat : I.office) || ''; }

  function isCurrent(p) {
    return !!current.officeId && p.courier === current.courier
        && String(p.office.office_id) === current.officeId;
  }
  function usable(p) { return !!p.available && !p.full; }
  function naText(p) { return p.full ? (I.allmap_full || '') : (I.allmap_na || ''); }
  function pinIcon(courierId, available, chosen) {
    return L.divIcon({
      className: 'bgc-allmap-pin' + (available ? '' : ' bgc-na') + (chosen ? ' bgc-chosen' : ''),
      html: '<span style="background:' + colourFor(courierId) + '"></span>',
      iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -9]
    });
  }

  function load() {
    try {
      var v = JSON.parse(window.localStorage.getItem(STORE) || 'null');
      if (v && v.cityName) {
        state.cityName = v.cityName; state.cityCode = v.cityCode || '';
        state.cityLabel = v.cityLabel || v.cityName;
        state.citySeed = 'stored';
      }
      // the position is never restored from storage; wipe what an older version left
      if (v && v.origin) { save(); }
    } catch (e) { }
  }
  // the town only: the position lives in memory, so a revoked permission is honoured
  function save() {
    try {
      window.localStorage.setItem(STORE, JSON.stringify({
        cityName: state.cityName, cityCode: state.cityCode, cityLabel: state.cityLabel
      }));
    } catch (e) {}
  }

  function close() {
    if (availT) { clearInterval(availT); availT = null; }
    if (map) { map.remove(); map = null; }
    meMarker = null;
    if (originAuto) { origin = null; originAuto = false; }
    layer = null;
    clusters = null; clustered = [];
    markers = []; points = []; rowEls = []; dists = [];
    mode = 'map';
    $(window).off('.bgcallmap');
    $('html, body').removeClass('bgc-allmap-lock');
    if ($dlg) { $dlg.remove(); $dlg = null; }
  }

  // Leaflet caches its container size: re-measure on the way in
  function setMode(v) {
    if (!$dlg) { return; }
    mode = (v === 'list') ? 'list' : 'map';
    $dlg.toggleClass('bgc-mode-list', mode === 'list').toggleClass('bgc-mode-map', mode === 'map');
    $dlg.find('.bgc-allmap-switch button').each(function () {
      var on = $(this).attr('data-v') === mode;
      $(this).toggleClass('on', on).attr('aria-pressed', on ? 'true' : 'false');
    });
    if (mode === 'map' && map) { map.invalidateSize(); }
  }

  function open(opts) {
    if ($dlg) { return; }
    opts = opts || {};
    hidden = {};
    dest = checkoutCountry(opts.wrap);
    current = { courier: '', officeId: '' };
    if (opts.wrap && opts.wrap.length) {
      current = { courier: String(opts.only || opts.wrap.attr('data-courier') || ''),
                  officeId: String(opts.wrap.find('.bgc-office').val() || '') };
    } else {
      var $open = $('.bgc-fields[data-courier]:visible').first();
      if ($open.length) {
        current = { courier: String($open.attr('data-courier') || ''),
                    officeId: String($open.find('.bgc-office').val() || '') };
      }
    }
    load();
    // the checkout's current town wins over the remembered one
    seedFromCheckout(opts.wrap);
    // a known town opens at full width: growing from the search-only box once the points land reads as a glitch
    var wide = !!state.cityLabel;
    // bgc-mode-map in the markup: a phone with neither mode class would draw both panes
    $dlg = $('<div class="bgc-allmap-overlay bgc-mode-map' + (wide ? ' bgc-has-map' : '') + '"><div class="bgc-allmap-box">'
      + '<div class="bgc-allmap-head"><strong>' + esc(I.allmap_title || '') + '</strong>'
      + '<button type="button" class="bgc-allmap-close" aria-label="' + esc(I.close || '') + '">&times;</button></div>'
      + '<div class="bgc-allmap-form">'
      + '<div class="bgc-allmap-cityrow">'
      + '<input type="text" class="bgc-allmap-cityinput" autocomplete="off" role="combobox"'
      + ' aria-expanded="false" aria-autocomplete="list" aria-controls="bgc-allmap-cityres"'
      + ' placeholder="' + esc(I.allmap_city_ph || '') + '">'
      + '<button type="button" class="bgc-allmap-citycaret" tabindex="-1" aria-label="'
      + esc(I.allmap_city_ph || '') + '"></button>'
      + '<button type="button" class="bgc-allmap-cityclear" hidden aria-label="' + esc(I.clear || '') + '"'
      + ' title="' + esc(I.clear || '') + '">&times;</button>'
      + (nearOn()
          ? '<button type="button" class="bgc-map-locate bgc-allmap-citylocate" data-tip="' + esc(I.map_locate || '')
            + '" title="' + esc(I.map_locate || '') + '" aria-label="' + esc(I.map_locate || '') + '"></button>'
          : '')
      + '<ul class="bgc-allmap-cityres" id="bgc-allmap-cityres" role="listbox" hidden></ul></div>'
      + '<div class="bgc-allmap-legendcol"><div class="bgc-allmap-legend" hidden></div></div>'
      + '</div>'
      + '<div class="bgc-allmap-body"' + (wide ? '' : ' style="display:none;"') + '>'
      + '<div class="bgc-allmap-side">'
      + '<div class="bgc-allmap-sidetop">'
      + '<div class="bgc-allmap-searchrow">'
      + '<input type="text" class="bgc-allmap-search" autocomplete="off" placeholder="' + esc(I.office_ph || '') + '">'
      + (nearOn()
          ? '<button type="button" class="bgc-map-locate" data-tip="' + esc(I.map_locate || '')
            + '" aria-label="' + esc(I.map_locate || '') + '"></button>'
          : '')
      + '</div>'
      + '<div class="bgc-allmap-answers" hidden></div>'
      + '</div>'
      + '<ul class="bgc-allmap-list"></ul></div>'
      + '<div class="bgc-allmap-canvas" id="bgc-allmap-canvas"></div>'
      + '<div class="bgc-allmap-switch" role="group">'
      + '<button type="button" data-v="map" class="on" aria-pressed="true">' + esc(I.allmap_map || '') + '</button>'
      + '<button type="button" data-v="list" aria-pressed="false">' + esc(I.allmap_list || '')
      + ' <span class="bgc-allmap-n"></span></button>'
      + '</div>'
      + '</div></div></div>');
    $('body').append($dlg);
    $('html, body').addClass('bgc-allmap-lock');
    $(window).on('resize.bgcallmap orientationchange.bgcallmap', function () {
      if (map) { map.invalidateSize(); }
      syncLegend();
    });

    // own suggest list, not select2: its dropdown positioning breaks inside a position:fixed overlay
    var $input = $dlg.find('.bgc-allmap-cityinput');
    var $res = $dlg.find('.bgc-allmap-cityres');
    var searchT = null, blurT = null;

    function resOpen() { return !$res.is('[hidden]') && !!$res.children().length; }
    function hideRes() {
      $res.attr('hidden', true).empty();
      $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
    }
    function syncClear() {
      var empty = $.trim($input.val()) === '';
      $dlg.find('.bgc-allmap-cityclear').attr('hidden', empty ? true : null);
      $dlg.find('.bgc-allmap-citycaret').attr('hidden', empty ? null : true);
    }
    function clearCity() {
      state.cityName = state.cityCode = state.cityLabel = state.citySeed = '';
      save();
      $input.val('').focus();
      hideRes(); syncClear(); busyCity(false);
      // meMarker must go with its map, or the next locate moves a marker on a destroyed map
      if (map) { map.remove(); map = null; layer = null; meMarker = null; }
      markers = []; points = []; rowEls = []; dists = [];
      if (originAuto) { origin = null; originAuto = false; }
      $dlg.removeClass('bgc-has-map');
      $dlg.find('.bgc-allmap-body').hide().find('.bgc-allmap-list').empty();
      $dlg.find('.bgc-allmap-legend').attr('hidden', true).empty();
      $dlg.find('.bgc-allmap-answers').attr('hidden', true).empty();
      $dlg.find('.bgc-allmap-canvas').empty();
    }
    $dlg.on('click', '.bgc-allmap-cityclear', function (e) { e.preventDefault(); clearCity(); });
    function pickCity(name, code, label) {
      state.cityName = name; state.cityCode = code; state.cityLabel = label;
      state.citySeed = 'picked';
      $input.val(label);
      hideRes(); save(); syncClear();
      showOffices();
    }
    function fill(raw) {
      $res.empty();
      bgcList(raw).forEach(function (r, i) {
        var label = r.name + (r.post_code ? ' (' + r.post_code + ')' : '');
        $('<li class="bgc-allmap-cityopt" role="option"></li>').text(label)
          .attr({ id: 'bgc-allmap-cityopt-' + i, 'data-name': r.name, 'data-code': r.post_code || '' })
          .appendTo($res);
      });
      var any = !!$res.children().length;
      $res.attr('hidden', !any);
      $input.attr('aria-expanded', any ? 'true' : 'false');
      if (any && state.cityLabel) {
        setActive($res.children().filter(function () { return $(this).text() === state.cityLabel; }).first());
      }
      scrollActive();
    }
    function setActive($li) {
      $res.children().removeClass('is-active');
      if ($li && $li.length) {
        $li.addClass('is-active');
        $input.attr('aria-activedescendant', $li.attr('id'));
      } else {
        $input.removeAttr('aria-activedescendant');
      }
    }
    function scrollActive() {
      var el = $res.children('.is-active')[0];
      if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'nearest' }); }
    }
    pickCityFn = pickCity;
    clearCityFn = clearCity;
    function search(term) {
      clearTimeout(searchT);

      var local = localCities(term);
      if (local) { busyCity(false); fill(local); return; }

      if (term.length === 1) { hideRes(); busyCity(false); return; }
      busyCity(true);
      searchT = setTimeout(function () {
        $.get(BGCOURIERS.ajax, { action: 'bgcouriers_allmap_cities', term: term }, function (rows) {
          fill(rows);
        }).always(function () { busyCity(false); });
      }, 220);
    }
    function openList() {
      // cancel a blur close left over from the press that opened this
      clearTimeout(blurT);
      if (!$input.is(':focus')) { $input.trigger('focus'); }
      if ($input.val()) { try { $input[0].select(); } catch (e) {} }
      search('');
    }
    $input.on('input', function () { syncClear(); search($.trim($input.val())); });
    $input.on('click', function () { if (!resOpen()) { openList(); } });
    // mousedown with the default prevented: a click would blur the input and close the list first
    $dlg.on('mousedown', '.bgc-allmap-citycaret', function (e) {
      e.preventDefault();
      if (resOpen()) { hideRes(); } else { openList(); }
    });
    $input.on('keydown', function (e) {
      var k = e.key;
      if (k === 'ArrowDown' && !resOpen()) { e.preventDefault(); openList(); return; }
      if (k === 'Escape' && resOpen()) { e.preventDefault(); hideRes(); return; }
      if (!resOpen()) { return; }
      if (k === 'ArrowDown' || k === 'ArrowUp') {
        e.preventDefault();
        var $opts = $res.children(), $cur = $opts.filter('.is-active');
        var i = $cur.length ? $opts.index($cur) : -1;
        i = (k === 'ArrowDown') ? Math.min(i + 1, $opts.length - 1) : Math.max(i - 1, 0);
        setActive($opts.eq(i));
        scrollActive();
      } else if (k === 'Enter') {
        var $a = $res.children('.is-active');
        if ($a.length) { e.preventDefault(); $a.trigger('click'); }
      }
    });
    $res.on('mouseenter', '.bgc-allmap-cityopt', function () { setActive($(this)); });
    $res.on('click', '.bgc-allmap-cityopt', function () {
      pickCity($(this).attr('data-name'), $(this).attr('data-code'), $(this).text());
    });
    $input.on('blur', function () { clearTimeout(blurT); blurT = setTimeout(hideRes, 150); });

    if (state.cityLabel) { $input.val(state.cityLabel); showOffices(); }
    syncClear();

    $dlg.on('click', '.bgc-allmap-close', close);
    // guarded: choosing a point nulls $dlg while this click is still bubbling
    $dlg.on('click', function (e) { if ($dlg && e.target === $dlg[0]) { close(); } });
    $dlg.on('input', '.bgc-allmap-search', scheduleFilter);
    $dlg.on('click', '.bgc-allmap-ans', function () { focusPoint(+$(this).attr('data-i')); });
    $dlg.on('click', '.bgc-map-locate', function (e) { e.preventDefault(); showMe(); });
    $dlg.on('click', '.bgc-allmap-switch button', function () { setMode($(this).attr('data-v')); });
    $dlg.on('click', '.bgc-allmap-pick', function () {
      var p = points[+$(this).data('i')];
      if (!p || !window.BGCouriersCheckout) { return; }
      var ok = window.BGCouriersCheckout.applyPick({
        courier: p.courier, method: p.type,
        cityId: p.cityId, cityLabel: state.cityLabel,
        postCode: state.cityCode,
        officeId: p.office.office_id,
        officeLabel: (p.office.name || '') + ' - ' + (p.office.address || '')
      });
      if (ok) { close(); }
    });
  }

  function refreshNear() { measureNear(); pickNear(); }

  // the costly half: runs when the origin or the points change, never on a filter change
  function measureNear() {
    if (!$dlg) { return; }
    var $list = $dlg.find('.bgc-allmap-list');
    var on = !!origin && nearOn();
    $dlg.toggleClass('bgc-has-origin', on);
    dists = [];
    if (!on) {
      $list.find('.bgc-allmap-dist').remove();
      return;
    }

    points.forEach(function (p, i) {
      var d = distOf(p);
      dists[i] = d;
      if (!rowEls[i]) { return; }
      var $row = $(rowEls[i]);
      $row.children('.bgc-allmap-dist').remove();
      if (d != null) { $row.append('<span class="bgc-allmap-dist">' + esc(fmtDist(d)) + '</span>'); }
    });

    var order = [];
    for (var i = 0; i < rowEls.length; i++) { if (rowEls[i]) { order.push(i); } }
    order.sort(function (a, b) {
      var da = dists[a], db = dists[b];
      if (da == null) { return db == null ? a - b : 1; }
      if (db == null) { return -1; }
      return da - db;
    });
    var frag = document.createDocumentFragment();
    order.forEach(function (i) { frag.appendChild(rowEls[i]); });
    if ($list[0]) { $list[0].appendChild(frag); }
  }

  // the instant half: cached distances and flags only, so it can run on a legend click
  function pickNear() {
    if (!$dlg || !nearOn()) { return; }
    var best = {}, near = null, cheap = null, addr = null, term = searchTerm();
    // nearer first; with no distance (or the same one) the cheaper
    function better(a, b) {
      if (!b) { return true; }
      if (a.d != null && b.d != null && a.d !== b.d) { return a.d < b.d; }
      if ((a.d == null) !== (b.d == null)) { return a.d != null; }
      return a.v != null && (b.v == null || a.v < b.v);
    }
    points.forEach(function (p, i) {
      if (!usable(p) || !shown(p, term)) { return; }
      var x = { i: i, p: p, d: dists[i] == null ? null : dists[i], v: amount(p.price) };
      // measured from the chosen office itself, "closest" means the closest alternative to it
      var self = originAuto && isCurrent(p);
      if (!self && better(x, best[p.courier])) { best[p.courier] = x; }
      if (!self && x.d != null && (!near || x.d < near.d)) { near = x; }
      if (x.v != null && (!cheap || x.v < cheap.v || (x.v === cheap.v && better(x, cheap)))) { cheap = x; }
      var a = amount(p.addressPrice);
      if (a != null && (!addr || a < addr.v)) { addr = { v: a, text: p.addressPrice }; }
    });

    $dlg.find('.bgc-allmap-chip').each(function () {
      var b = best[$(this).attr('data-c')];
      $(this).find('.bgc-chip-d').remove();
      if (!b) { return; }
      var bits = [];
      if (b.d != null) { bits.push(fmtDist(b.d)); }
      if (b.p.price) { bits.push(priceLabel(b.p)); }
      if (bits.length) { $(this).append('<span class="bgc-chip-d">' + esc(bits.join(' · ')) + '</span>'); }
    });
    syncLegend();

    renderAnswers(near, cheap, addr);
  }

  function focusPoint(i) {
    var mk = markers[i], el = rowEls[i];
    if (!$dlg) { return; }
    var $list = $dlg.find('.bgc-allmap-list');
    if (el) {
      $list.find('.active').removeClass('active');
      $(el).addClass('active');
      // scroll the sidebar only, measured against the list's box rather than offsetTop
      if ($list[0]) {
        var top = el.getBoundingClientRect().top - $list[0].getBoundingClientRect().top;
        $list[0].scrollTop = Math.max(0, $list[0].scrollTop + top - $list[0].clientHeight / 2);
      }
    }
    if (!mk || !map) { return; }
    setMode('map');
    // re-measure first: Leaflet centres against its last measured size
    map.invalidateSize();
    // animate:false: an animated setView races openPopup's auto-pan; zoom to where no bubble can swallow the pin
    map.setView(mk.getLatLng(), Math.max(map.getZoom(), CLUSTER_OFF_ZOOM), { animate: false });
    mk.openPopup();
    if (mk._icon) {
      var icon = mk._icon;
      icon.classList.remove('bgc-pin-flash');
      void icon.offsetWidth;          // restart the animation when the same pin is asked for twice
      icon.classList.add('bgc-pin-flash');
      $(icon).one('animationend', function () { icon.classList.remove('bgc-pin-flash'); });
    }
  }

  function answerTile(cls, label, x) {
    var p = x.p;
    return '<button type="button" class="bgc-allmap-ans ' + cls + '" data-i="' + x.i + '"'
      + ' title="' + esc(I.near_which || '') + '">'
      + '<span class="bgc-ans-k">' + esc(label || '') + '</span>'
      + '<span class="bgc-ans-c">'
      + (p.logo ? '<img src="' + esc(p.logo) + '" alt="">' : '')
      + '<span class="bgc-near-c">' + esc(p.courierLabel) + '</span>' + typeGlyph(p.type) + '</span>'
      + '<span class="bgc-ans-m">'
      + (x.d != null ? '<span class="bgc-near-d" title="' + esc(I.near_straight || '') + '">' + esc(fmtDist(x.d)) + '</span>' : '')
      + (p.price ? '<span class="bgc-near-p">' + esc(priceLabel(p)) + '</span>' : '')
      + '</span></button>';
  }

  function renderAnswers(near, cheap, addr) {
    var $box = $dlg.find('.bgc-allmap-answers');
    if (!near && !cheap) { $box.attr('hidden', true).empty(); return; }
    var html = '';
    if (near && cheap && near.i === cheap.i) {
      html = answerTile('bgc-allmap-near bgc-allmap-cheap bgc-ans-both', I.ans_both, near);
    } else {
      if (near) { html += answerTile('bgc-allmap-near', I.ans_near, near); }
      if (cheap) { html += answerTile('bgc-allmap-cheap', I.ans_cheap, cheap); }
    }
    if (addr) {
      var save = cheap && addr.v > cheap.v ? money(addr.v - cheap.v, addr.text) : '';
      html += '<div class="bgc-ans-addr">' + esc(String(I.ans_addr || '%s').replace('%s', addr.text))
        + (save ? ' <span class="bgc-near-save">' + esc((I.near_save || '') + ' ' + save) + '</span>' : '')
        + '</div>';
    }
    $box.html(html).attr('hidden', null);
  }

  // one line of chips that scrolls sideways; the fade says there is more only while there is
  function syncLegend() {
    var el = $dlg && $dlg.find('.bgc-allmap-legend')[0];
    if (!el) { return; }
    $(el).toggleClass('bgc-more', el.scrollWidth - el.clientWidth - el.scrollLeft > 2);
  }

  function searchTerm() {
    return $dlg ? String($dlg.find('.bgc-allmap-search').val() || '').toLowerCase() : '';
  }
  function shown(p, term) {
    if (!p || hidden[p.courier]) { return false; }
    if (term == null) { term = searchTerm(); }
    if (!term) { return true; }
    var o = p.office || {};
    return String(o.name || '').toLowerCase().indexOf(term) !== -1
        || String(o.address || '').toLowerCase().indexOf(term) !== -1;
  }

  function applyFilter() {
    if (!$dlg) { return; }
    var n = 0, term = searchTerm();
    points.forEach(function (p, i) {
      var on = shown(p, term);
      if (on) { n++; }
      var el = rowEls[i];
      if (el) { el.style.display = on ? '' : 'none'; }
    });
    $dlg.find('.bgc-allmap-n').text('(' + n + ')');
    recluster(term);
    pickNear();
  }

  function applyAvailability(lockers) {
    if (!lockers || !points.length) { return; }
    var any = false;
    points.forEach(function (p, i) {
      if (p.courier !== 'sameday' || p.type !== 'automat') { return; }
      var id = p.office && (p.office.office_id != null ? p.office.office_id : p.office.code);
      if (id == null) { return; }
      // a locker missing from the answer is a Sameday PUDO counter, never "full"
      var full = Object.prototype.hasOwnProperty.call(lockers, String(id)) ? !lockers[String(id)] : false;
      if (p.full === full) { return; }
      p.full = full; any = true;
      var el = rowEls[i];
      if (el) { $(el).html(rowInner(p)).toggleClass('bgc-na', !usable(p)); }
      var mk = markers[i];
      if (mk) {
        if (mk.setIcon) { mk.setIcon(pinIcon(p.courier, usable(p), isCurrent(p))); }
        if (mk.isPopupOpen && mk.isPopupOpen() && mk.getPopup()) { mk.getPopup().update(); }
      }
    });
    if (any) { applyFilter(); }
  }

  function hasSamedayLockers() {
    return points.some(function (p) { return p.courier === 'sameday' && p.type === 'automat'; });
  }

  function pollAvailability() {
    if (!hasSamedayLockers()) { return; }
    var seq = availSeq;
    $.get(BGCOURIERS.ajax, { action: 'bgcouriers_sameday_availability' })
      .done(function (res) {
        if (!$dlg || seq !== availSeq) { return; }
        applyAvailability(res && res.lockers);
      });
  }

  function startAvailabilityPoll() {
    if (availT) { clearInterval(availT); availT = null; }
    availSeq++;
    if (!hasSamedayLockers()) { return; }
    pollAvailability();
    availT = setInterval(pollAvailability, 60000);
  }

  // bucketed in projected space at the current zoom, so only zoomend has to recluster
  function recluster(term) {
    if (!map || !layer) { return; }
    if (term == null) { term = searchTerm(); }
    if (!clusters) { clusters = L.layerGroup().addTo(map); }
    clusters.clearLayers();
    clustered = [];
    var zoom = map.getZoom(), cells = {};
    if (zoom < CLUSTER_OFF_ZOOM) {
      points.forEach(function (p, i) {
        var mk = markers[i];
        if (!mk || !shown(p, term)) { return; }
        if (isCurrent(p)) { return; }
        var px = map.project(mk.getLatLng(), zoom);
        var key = Math.floor(px.x / CLUSTER_CELL) + ':' + Math.floor(px.y / CLUSTER_CELL);
        (cells[key] = cells[key] || []).push(i);
      });
    }
    // a lone point joins the fullest neighbouring bubble, one hop only
    Object.keys(cells).forEach(function (key) {
      if (cells[key].length !== 1) { return; }
      var at = key.split(':'), cx = +at[0], cy = +at[1], best = '', most = 1;
      for (var dx = -1; dx <= 1; dx++) {
        for (var dy = -1; dy <= 1; dy++) {
          if (!dx && !dy) { continue; }
          var k = (cx + dx) + ':' + (cy + dy);
          if (cells[k] && cells[k].length > most) { best = k; most = cells[k].length; }
        }
      }
      if (best) { cells[best].push(cells[key][0]); cells[key] = []; }
    });
    Object.keys(cells).forEach(function (key) {
      var idx = cells[key];
      if (idx.length < 2) { return; }
      var lat = 0, lng = 0, share = {}, order = [], lls = [], offered = false;
      idx.forEach(function (i) {
        clustered[i] = true;
        var ll = markers[i].getLatLng(); lat += ll.lat; lng += ll.lng; lls.push(ll);
        var c = points[i].courier;
        if (!share[c]) { share[c] = 0; order.push(c); }
        share[c]++;
        if (usable(points[i])) { offered = true; }
      });
      var n = idx.length, bg;
      if (order.length === 1) { bg = colourFor(order[0]); }
      else {
        var stops = [], at = 0;
        order.forEach(function (c) {
          var to = at + share[c] / n * 100;
          stops.push(colourFor(c) + ' ' + at.toFixed(1) + '% ' + to.toFixed(1) + '%');
          at = to;
        });
        bg = 'conic-gradient(' + stops.join(',') + ')';
      }
      var size = n < 10 ? 30 : (n < 100 ? 36 : 42);
      var bubble = L.marker([lat / n, lng / n], {
        icon: L.divIcon({
          className: 'bgc-allmap-cluster' + (offered ? '' : ' bgc-na'),
          html: '<span style="background:' + bg + '"><b>' + n + '</b></span>',
          iconSize: [size, size], iconAnchor: [size / 2, size / 2]
        }),
        title: String(I.allmap_cluster || '%d').replace('%d', n),
        zIndexOffset: 500, keyboard: false
      }).addTo(clusters);
      bubble.on('click', function () {
        var box = L.latLngBounds(lls);
        var z = Math.min(map.getBoundsZoom(box, false, L.point(40, 40)), 17);
        if (z <= map.getZoom()) { z = Math.min(map.getZoom() + 2, 18); }
        map.setView(box.getCenter(), z);
      });
    });
    paintPins(term);
  }

  // hidden by style, not removed: tearing down and re-adding Leaflet markers is the slow half of a legend click
  function paintPins(term) {
    points.forEach(function (p, i) {
      var mk = markers[i];
      if (!mk) { return; }
      var on = shown(p, term) && !clustered[i];
      if (mk._icon) {
        mk._icon.style.display = on ? '' : 'none';
        if (!on && mk.isPopupOpen && mk.isPopupOpen()) { mk.closePopup(); }
      } else if (layer && on && !layer.hasLayer(mk)) {
        layer.addLayer(mk);
      }
    });
  }

  var filterQueued = false;
  function scheduleFilter() {
    if (filterQueued) { return; }
    filterQueued = true;
    nextFrame(function () { filterQueued = false; applyFilter(); });
  }

  function seedFromCheckout($from) {
    var $w = ($from && $from.length) ? $from : $('.bgc-fields[data-courier]:visible').first();
    if (!$w.length) { return; }
    var label = $.trim($w.find('.bgc-city option:selected').text() || '');
    if (!label) { return; }
    var m = label.match(/^(.*?)\s*\((\d+)\)\s*$/);
    state.cityName  = m ? m[1] : label;
    state.cityCode  = m ? m[2] : String($w.find('.bgc-postcode').val() || '');
    state.cityLabel = label;
    state.citySeed  = 'checkout';
  }

  function busy(on) {
    if (!$dlg) { return; }
    $dlg.find('.bgc-allmap-wait, .bgc-allmap-busy').remove();
    if (!on) { return; }
    if ($dlg.hasClass('bgc-has-map')) {
      $dlg.find('.bgc-allmap-body').append('<div class="bgc-allmap-busy"><span class="bgc-spinner"></span></div>');
    } else {
      $dlg.find('.bgc-allmap-form').after('<div class="bgc-allmap-wait"><span class="bgc-spinner"></span>'
        + '<span>' + esc(I.allmap_loading || '') + '</span></div>');
    }
  }

  function couriersOnPage() {
    var out = {};
    $('input[name^="shipping_method"]').each(function () {
      var v = String(this.value || '');
      if (v.indexOf('bgcouriers_') !== 0) { return; }
      var id = v.replace('bgcouriers_', '').split(':')[0];
      var $row = $(this).closest('li');
      var $amt = $row.find('.woocommerce-Price-amount').first();
      var $logo = $row.find('img').first();
      out[id] = {
        available: true,
        price: $amt.length ? $amt.text() : '',
        method: String($('.bgc-fields[data-courier="' + id + '"]').attr('data-method') || ''),
        logo: $logo.length ? $logo.attr('src') : '',
        label: ($row.find('label').first().text() || id).split(':')[0].trim()
      };
    });
    return out;
  }

  function showOffices() {
    var key = state.cityName + '|' + state.cityCode + '|both';
    if (cache[key]) { render(cache[key]); return; }
    busy(true);
    $.get(BGCOURIERS.ajax, {
      action: 'bgcouriers_allmap_offices',
      name: state.cityName, post_code: state.cityCode, type: 'both'
    }, function (data) {
      // only a real object is cached: a cached "0" would drop the customer's town for good
      if (!data || typeof data !== 'object') { return; }
      cache[key] = data; render(cache[key]);
    })
     .always(function () { busy(false); });
  }

  function rowInner(p) {
    return (p.logo ? '<img src="' + esc(p.logo) + '" alt="' + esc(p.courierLabel) + '">' : '')
      + '<span class="n">' + typeGlyph(p.type) + esc(p.office.name || '') + '</span>'
      + (usable(p) && p.price ? '<span class="p">' + esc(priceLabel(p)) + '</span>' : '')
      + '<span class="a">' + esc(addrText(p.office.address)) + '</span>'
      + (usable(p) ? '' : '<span class="bgc-allmap-na-note">' + esc(naText(p)) + '</span>');
  }

  function popupHtml(p, i) {
    var lat = Number(p.office.lat), lng = Number(p.office.lng);
    var d = nearOn() ? distOf(p) : null;
    return '<div class="bgc-allmap-pop">'
      + '<div class="bgc-allmap-pop-c">'
      + (p.logo ? '<img src="' + esc(p.logo) + '" alt="' + esc(p.courierLabel) + '">' : '')
      + '<span class="c">' + esc(p.courierLabel) + '</span>'
      + typeGlyph(p.type)
      + '<span class="t">' + esc(typeLabel(p.type)) + '</span>'
      + (usable(p) && p.price ? '<span class="bgc-allmap-pop-price">' + esc(priceLabel(p)) + '</span>' : '')
      + '</div>'
      + '<div class="bgc-allmap-pop-n">' + esc(p.office.name || '') + '</div>'
      + '<div class="bgc-allmap-pop-a"><span class="bgc-pop-addr">' + esc(addrText(p.office.address)) + '</span>'
      + '<span class="bgc-pop-meta">'
      + (d != null
          ? '<span class="bgc-allmap-pop-d" title="' + esc(I.near_straight || '') + '">'
            + esc(fmtDist(d)) + ' ' + esc(I.near_from_you || '') + '</span>'
          : '')
      + (lat && lng
          ? '<a class="bgc-allmap-dir" target="_blank" rel="noopener noreferrer"'
            + ' href="https://www.google.com/maps/dir/?api=1&destination='
            + encodeURIComponent(lat + ',' + lng) + '"'
            + ' title="' + esc(I.allmap_directions || '') + '"'
            + ' aria-label="' + esc(I.allmap_directions || '') + '">'
            + '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"'
            + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></a>'
          : '')
      + '</span></div>'
      + (usable(p)
          ? '<button type="button" class="button bgc-allmap-pick" data-i="' + i + '">' + esc(I.allmap_choose || '') + '</button>'
          : '<em class="bgc-allmap-pop-na">' + esc(naText(p)) + '</em>')
      + '</div>';
  }

  function medianOf(data) {
    var lats = [], lngs = [];
    Object.keys(data || {}).forEach(function (cid) {
      ((data[cid] && data[cid].offices) || []).forEach(function (o) {
        var lat = Number(o.lat), lng = Number(o.lng);
        if (lat && lng) { lats.push(lat); lngs.push(lng); }
      });
    });
    if (!lats.length) { return null; }
    var mid = function (a) { a.sort(function (x, y) { return x - y; }); return a[Math.floor(a.length / 2)]; };
    return { lat: mid(lats), lng: mid(lngs), n: lats.length };
  }

  function countPoints(data) {
    var n = 0;
    Object.keys(data || {}).forEach(function (cid) { n += (((data[cid] || {}).offices) || []).length; });
    return n;
  }

  function render(data) {
    // a remembered town with no points left is dropped; one the customer just named stays
    if (state.citySeed === 'stored' && !countPoints(data) && clearCityFn) { clearCityFn(); return; }
    // back to the map before plotting: fitBounds() on a display:none container leaves a bad centre
    setMode('map');
    if (layer) { layer.clearLayers(); }
    var live = couriersOnPage();
    points = []; markers = [];
    Object.keys(data).forEach(function (cid) {
      var c = live[cid] || { available: false, price: '', method: '', logo: '', label: cid };
      var est = data[cid].prices || {};
      (data[cid].offices || []).forEach(function (o) {
        var type = o.type === 'automat' ? 'automat' : 'office';
        var exact = c.available && c.method === type && c.price;
        points.push({
          courier: cid, courierLabel: c.label || cid, logo: c.logo || '',
          available: !!c.available,
          full: false,
          addressPrice: est.address || '',
          savesVsAddress: (data[cid].saves || {})[type] || '',
          price: exact ? c.price : (est[type] || c.price || ''),
          estimated: !exact && !!est[type],
          cityId: data[cid].city_id,
          type: type,
          office: o
        });
      });
    });

    $dlg.find('.bgc-allmap-body').show();
    $dlg.addClass('bgc-has-map');
    var $list = $dlg.find('.bgc-allmap-list').empty();
    if (!map) {
      map = L.map('bgc-allmap-canvas', { scrollWheelZoom: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
      layer = L.layerGroup().addTo(map);
      map.on('zoomend', function () { recluster(); });
      var bubbleClosedAt = 0;
      map.on('popupclose', function () { bubbleClosedAt = Date.now(); });
      map.on('click', function (e) {
        if (!nearOn() || Date.now() - bubbleClosedAt < 400) { return; }
        setOrigin(e.latlng);
      });
    }
    var bounds = [], chosenAt = null, rowHtml = [];
    points.forEach(function (p, i) {
      rowHtml.push('<li class="bgc-allmap-item' + (usable(p) ? '' : ' bgc-na')
        + (isCurrent(p) ? ' bgc-chosen' : '') + '" data-i="' + i + '"'
        + ' style="border-left-color:' + colourFor(p.courier) + '">'
        + rowInner(p) + '</li>');
      var lat = Number(p.office.lat), lng = Number(p.office.lng);
      if (!lat && !lng) { return; }
      var mk = L.marker([lat, lng], { icon: pinIcon(p.courier, usable(p), isCurrent(p)),
        zIndexOffset: isCurrent(p) ? 1000 : 0 }).addTo(layer);
      mk.bindPopup(function () { return popupHtml(p, i); }, {
          // bottom padding clears the Map/List pill; top-left clears the zoom buttons
          minWidth: 210, maxWidth: 330,
          autoPanPaddingBottomRight: L.point(12, 78),
          autoPanPaddingTopLeft: L.point(58, 82)
        });
      markers[i] = mk; bounds.push([lat, lng]);
      if (isCurrent(p)) { chosenAt = [lat, lng]; }
    });
    $list.html(rowHtml.join(''));
    rowEls = $list.children('.bgc-allmap-item').get();
    var placed = false;
    if (nearOn() && (!origin || originAuto)) {
      var at = chosenAt ? { lat: chosenAt[0], lng: chosenAt[1] } : medianOf(data);
      origin = at ? { lat: at.lat, lng: at.lng } : null;
      originAuto = !!origin;
      placed = originAuto;
    }
    // frame now and again after the width transition: Leaflet caches the container size
    function frame() {
      if (!map) { return; }
      map.invalidateSize();
      if (!bounds.length) { map.setView([42.73, 25.3], 7); return; }
      // computed from the bounds, not getZoom() + 2: this runs up to three times per render
      if (chosenAt) {
        map.setView(chosenAt, 16, { animate: false });
        return;
      }
      var box = L.latLngBounds(bounds);
      map.setView(box.getCenter(), Math.min(map.getBoundsZoom(box, false, L.point(34, 34)), 16),
        { animate: false });
    }
    frame();
    $dlg.find('.bgc-allmap-box').off('transitionend.bgcfit').on('transitionend.bgcfit', function (e) {
      if (e.originalEvent && e.originalEvent.propertyName === 'width') { frame(); }
    });
    setTimeout(frame, 420);

    var seen = [], $legend = $dlg.find('.bgc-allmap-legend').empty();
    points.forEach(function (p) { if (seen.indexOf(p.courier) === -1) { seen.push(p.courier); } });
    seen.forEach(function (cid) {
      var first = points.filter(function (p) { return p.courier === cid; })[0] || {};
      var label = first.courierLabel || cid;
      $legend.append('<button type="button" class="bgc-allmap-chip on" data-c="' + esc(cid) + '">'
        + '<span class="bgc-allmap-swatch" style="background:' + colourFor(cid) + '"></span>'
        + (first.logo ? '<img src="' + esc(first.logo) + '" alt="' + esc(label) + '">' : '')
        + esc(label) + '</button>');
    });
    $legend.attr('hidden', !seen.length);
    $legend.off('scroll.bgc wheel.bgc').on('scroll.bgc', syncLegend).on('wheel.bgc', function (e) {
      var el = this, ev = e.originalEvent || {};
      if (el.scrollWidth <= el.clientWidth || Math.abs(ev.deltaY || 0) <= Math.abs(ev.deltaX || 0)) { return; }
      el.scrollLeft += ev.deltaY;
      e.preventDefault();
    });
    $legend.off('click').on('click', '.bgc-allmap-chip', function () {
      var cid = $(this).attr('data-c');
      hidden[cid] = !hidden[cid];
      $(this).toggleClass('on', !hidden[cid]);
      pickNear();
      scheduleFilter();
    });
    applyFilter();

    // live prices land after the map is on screen; guarded against a town change mid-flight
    var forCity = state.cityName + '|' + state.cityCode;
    seen.forEach(function (cid) {
      $.get(BGCOURIERS.ajax, { action: 'bgcouriers_allmap_prices', courier: cid,
                               name: state.cityName, post_code: state.cityCode })
        .done(function (res) {
          if (!$dlg || forCity !== state.cityName + '|' + state.cityCode) { return; }
          var d = (res && res.success) ? res.data : null;
          if (!d || !d.prices) { return; }
          points.forEach(function (p, i) {
            if (p.courier !== cid) { return; }
            if (d.prices[p.type]) {
              p.price = d.prices[p.type];
              p.estimated = false;
              var el = rowEls[i];
              if (el) { $(el).children('.p').text(priceLabel(p)); }
            }
            if (d.prices.address) { p.addressPrice = d.prices.address; }
            if (d.saves && d.saves[p.type]) { p.savesVsAddress = d.saves[p.type]; }
          });
          // refresh an open bubble, never close it
          markers.forEach(function (mk) {
            if (mk && mk.isPopupOpen && mk.isPopupOpen() && mk.getPopup()) { mk.getPopup().update(); }
          });
          pickNear();
        });
    });

    if (origin && nearOn()) {
      placeMe([origin.lat, origin.lng]);
      if (placed && meMarker && meMarker.openTooltip) { meMarker.openTooltip(); }
    }
    refreshNear();

    $list.off('click').on('click', '.bgc-allmap-item:not(.bgc-na)', function () {
      focusPoint(+$(this).data('i'));
    });

    startAvailabilityPoll();
  }

  var meMarker = null, pickCityFn = null, clearCityFn = null;

  function cityFromPosition(here) {
    busyCity(true);
    $.get(BGCOURIERS.ajax, { action: 'bgcouriers_geocode', lat: here[0], lng: here[1] }, function (geo) {
      var town = geo && geo.city ? String(geo.city) : '';
      if (!town || !pickCityFn) { return; }
      var rows = localCities(town) || [];
      var hit = rows[0] || null;
      if (geo.postcode) {
        rows.forEach(function (r) { if (String(r.post_code) === String(geo.postcode)) { hit = r; } });
      }
      if (!hit) { return; }
      pickCityFn(hit.name, hit.post_code, hit.name + (hit.post_code ? ' (' + hit.post_code + ')' : ''));
    }).always(function () { busyCity(false); });
  }

  function dropPin() {
    if (!map) { return; }
    var c = map.getCenter();
    origin = { lat: c.lat, lng: c.lng };
    originAuto = false;
    save();
    if (meMarker) { meMarker.setLatLng(c); }
    else { placeMe([c.lat, c.lng]); }
    refreshNear();
    if (meMarker && meMarker.openTooltip) { meMarker.openTooltip(); }
  }

  // the hint has done its job once the customer moved the pin; unbindTooltip() would leave Leaflet 1.9 a dangling focus listener
  function quietTip() {
    if ($dlg) { $dlg.addClass('bgc-tip-done'); }
    if (meMarker && meMarker.closeTooltip) { meMarker.closeTooltip(); }
  }

  function setOrigin(ll) {
    origin = { lat: ll.lat, lng: ll.lng };
    originAuto = false;
    placeMe([ll.lat, ll.lng]);
    quietTip();
    refreshNear();
  }

  function placeMe(here) {
    if (!map) { return; }
    if (meMarker) { meMarker.setLatLng(here); return; }
    meMarker = L.marker(here, {
      draggable: true,
      zIndexOffset: 2000,
      icon: L.divIcon({
        className: 'bgc-allmap-me',
        html: '<span class="bgc-me-ring"></span>'
      + '<svg viewBox="0 0 24 34" width="24" height="34" aria-hidden="true">'
      + '<path d="M12 0C5.4 0 0 5.4 0 12c0 8.4 12 22 12 22s12-13.6 12-22c0-6.6-5.4-12-12-12z"/>'
      + '<circle cx="12" cy="12" r="4.4"/></svg>',
        iconSize: [24, 34], iconAnchor: [12, 34]
      })
    }).addTo(map);
    meMarker.bindTooltip(I.near_drag || I.map_locate || '', { direction: 'top', offset: [0, -34], className: 'bgc-me-tip' });
    meMarker.on('dragend', function () {
      var ll = meMarker.getLatLng();
      origin = { lat: ll.lat, lng: ll.lng };
      originAuto = false;
      quietTip();
      save();
      refreshNear();
    });
  }

  // in the layer and not filtered out; a pin folded into a bubble still counts
  function pinShown(i) {
    var mk = markers[i];
    if (!mk || !layer || !layer.hasLayer(mk)) { return false; }
    return shown(points[i]);
  }

  function showMe() {
    if (!nearOn()) { return; }
    if (!navigator.geolocation) { dropPin(); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var here = [pos.coords.latitude, pos.coords.longitude];
      origin = { lat: here[0], lng: here[1] };
      originAuto = false;
      save();
      if (!state.cityName || !map) { cityFromPosition(here); return; }
      placeMe(here);
      var visible = points.filter(function (p, i) { return pinShown(i); });
      if (!visible.length) { map.setView(here, 14); return; }
      // 0.74 is about cos(42 deg): longitude degrees are shorter this far north
      var near = visible.map(function (p) {
        var dlat = Number(p.office.lat) - here[0], dlng = (Number(p.office.lng) - here[1]) * 0.74;
        return { ll: [Number(p.office.lat), Number(p.office.lng)], d: dlat * dlat + dlng * dlng };
      }).sort(function (a, b) { return a.d - b.d; }).slice(0, 6).map(function (x) { return x.ll; });
      map.fitBounds([here].concat(near), { padding: [45, 45], maxZoom: 16 });
      refreshNear();
    }, function () {
      dropPin();
    }, { enableHighAccuracy: true, timeout: 8000 });
  }

  // one request for all couriers: the wait is WordPress booting, not the lookup
  function prefetch() {
    if (!window.BGCOURIERS || !BGCOURIERS.ajax) { return; }
    load();
    seedFromCheckout();
    if (!state.cityName) { return; }
    var key = state.cityName + '|' + state.cityCode + '|both';
    if (cache[key]) { return; }
    $.get(BGCOURIERS.ajax, {
      action: 'bgcouriers_allmap_offices',
      name: state.cityName, post_code: state.cityCode, type: 'both'
    }, function (data) { if (!data || typeof data !== 'object') { return; } cache[key] = data; });
  }
  $(function () {
    if (window.requestIdleCallback) { window.requestIdleCallback(prefetch, { timeout: 5000 }); }
    else { setTimeout(prefetch, 3000); }
  });

  // median of the pickup points' coordinates, from the shared per-place cache
  function centreOf(name, code, cb) {
    if (!name || !window.BGCOURIERS || !BGCOURIERS.ajax) { cb(null); return; }
    var key = name + '|' + code + '|both';
    function answer(data) { cb(medianOf(data)); }
    if (cache[key]) { answer(cache[key]); return; }
    $.get(BGCOURIERS.ajax, { action: 'bgcouriers_allmap_offices', name: name, post_code: code, type: 'both' })
      .done(function (data) { if (!data || typeof data !== 'object') { cb(null); return; } cache[key] = data; answer(cache[key]); })
      .fail(function () { cb(null); });
  }

  $(document).on('click', '.bgc-allmap-btn', function (e) { e.preventDefault(); open(); });
  window.BGCouriersAllMap = { open: open, close: close, points: function () { return points; }, centreOf: centreOf,
    origin: function () { return origin ? { lat: origin.lat, lng: origin.lng, auto: originAuto } : null; } };
})(jQuery);
