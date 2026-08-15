/**
 * The combined office/APS map: one dialog showing every enabled courier's pickup points for a place,
 * so a customer can choose by WHERE they will collect the parcel rather than by which courier they
 * happened to select first.
 *
 * Separate from bgc-checkout.js, which keeps its own per-courier Map button unchanged. The only thing
 * crossing between them is window.BGCouriersCheckout.applyPick().
 *
 * No user-facing text lives in this file - every label comes from BGCOURIERS.i18n, which PHP builds
 * from __() calls. A literal typed here would never reach the .pot.
 */
(function ($) {
  var I = (window.BGCOURIERS && BGCOURIERS.i18n) || {};
  var STORE = 'bgcouriers_map_pick';
  // A PLACE, not an id: city ids belong to the courier that issued them, so the dialog remembers what
  // the place is called and lets the server resolve it per courier.
  var state = { cityName: '', cityCode: '', cityLabel: '' };
  // Where distances are measured FROM. Set by the locate button or by dragging the pin, remembered
  // with the place. Never sent anywhere: every distance below is worked out in the browser, over
  // points it already has, so the customer's position does not leave the page.
  var origin = null;
  // `layer` holds every pin from the CURRENT render, so the next render can wipe it in one call. Without
  // that, a stale pin from a previous Show would stay on the map with a popup baked from the OLD points
  // array - clicking Choose on it would resolve against the NEW array at the same index and could book a
  // different courier, city or office than the pin the customer actually clicked.
  var $dlg = null, map = null, layer = null, markers = [], points = [], cache = {};
  // Couriers the customer has switched OFF in the legend. Empty by default - a map that opens
  // showing only some of what it has would be lying about the choice available.
  var hidden = {};
  // A courier this dialog was opened FOR, when it was opened from that courier's own Map button. It
  // starts as the only one showing; the customer can switch the others on from the legend, and
  // choosing one of their points moves the whole selection over, exactly as from the map's own button.
  var only = '';
  // The office/APS this courier already has selected, when the dialog was opened from its own block.
  // Marked on the map so the customer can see what they picked last time instead of hunting for it.
  var current = { courier: '', officeId: '' };
  // Which half of the dialog a PHONE is showing. On a desktop both are on screen and this does
  // nothing - the classes it writes are only read inside the narrow-screen media query.
  var mode = 'map';

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

  /**
   * Metres between two points, by the haversine formula.
   *
   * Straight-line, and the interface says so: a real walking route would need a directions API and one
   * request per point, and calling a straight line "800 m to walk" would be a number the customer could
   * catch us out on. The exact route is one tap away in the popup's directions link.
   */
  function distanceM(a, b) {
    var R = 6371000, toRad = Math.PI / 180;
    var dLat = (b.lat - a.lat) * toRad, dLng = (b.lng - a.lng) * toRad;
    var la1 = a.lat * toRad, la2 = b.lat * toRad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
          + Math.sin(dLng / 2) * Math.sin(dLng / 2) * Math.cos(la1) * Math.cos(la2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  /** "850 m" / "1.2 km" - rounded the way a person says it, not to the metre. */
  function fmtDist(m) {
    if (m == null) { return ''; }
    if (m < 950) { return Math.round(m / 10) * 10 + ' ' + (I.near_m || 'm'); }
    return (Math.round(m / 100) / 10).toString().replace('.', ',') + ' ' + (I.near_km || 'km');
  }
  /** A point's distance from the origin, or null when either end has no coordinates. */
  function distOf(p) {
    if (!origin) { return null; }
    var lat = Number(p.office.lat), lng = Number(p.office.lng);
    if (!lat && !lng) { return null; }
    return distanceM(origin, { lat: lat, lng: lng });
  }

  /**
   * Every place any enabled courier can deliver to, from the index the checkout already carries.
   *
   * BGCOURIERS.cityIndex is localised into the page per courier and per delivery type, as
   * [city_id, name, post_code, name_lat] rows, and holds only cities that actually HAVE a pickup point -
   * which is precisely the set this dialog can plot. Merged and de-duplicated on name+post code, the
   * same key the server's own bgcouriers_allmap_cities uses, so both paths answer alike.
   *
   * Built once, lazily: on a big shop this is a few thousand rows and there is no reason to touch them
   * until somebody types.
   */
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

  /**
   * @return {Array|null} Matching places, or null when this shop has no preloaded index and the server
   *                      has to be asked instead.
   */
  function localCities(term) {
    if (cityList === null) { cityList = buildCityList() || false; }
    if (!cityList || !cityList.length) { return null; }
    // The checkout's own matcher, so "sofia", "София" and "1000" all find гр. София here too.
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

  /** The city box says it is working. Only ever seen on a shop with no preloaded index. */
  function busyCity(on) {
    if (!$dlg) { return; }
    $dlg.find('.bgc-allmap-cityrow').toggleClass('bgc-loading', !!on);
  }

  /**
   * Each courier's pin colour, FIXED - the same on every shop, every visit, until the owner says
   * otherwise. A colour that moved between sessions would make the legend useless, since the whole
   * point of it is that a customer learns "the orange ones are Pigeon" once.
   *
   * Sampled from the couriers' own logos, then pulled apart where the brands collide. Pigeon and
   * Econt are both navy, so Pigeon takes the orange that is also in its mark. Speedy and Sameday are
   * both red - and unlike Pigeon, NEITHER has a second colour to fall back on: both marks contain
   * exactly one hue. Speedy keeps red, because a red parcel is what Speedy is; Sameday gets a colour
   * that is not in its logo at all, which is worse than taking one from the brand and better than two
   * pins nobody can tell apart.
   *
   * Deliberately no greens or greys: OpenStreetMap's own parks, water and roads are those, and a pin
   * has to be obviously not-map.
   */
  var PIN_COLOURS = {
    speedy:  '#D00030', // its own crimson - the only colour in the Speedy mark
    econt:   '#204080', // Econt navy, straight off the logo
    pigeon:  '#F08010', // the orange in the Pigeon mark; its navy is Econt's
    boxnow:  '#5A2BC4', // the deep purple of the BOX NOW mark, lightened to hold up on a pale map
    // Sameday's mark contains ONE colour and it is red (#E02020) - which is Speedy's. Two red pins
    // are not a legend, so this one is deliberately NOT from its logo: teal is as far from crimson as
    // this palette gets, and it is not a colour the map itself uses.
    sameday: '#00838F'
  };
  var PIN_FALLBACK = ['#6A1B9A', '#00838F', '#4E342E', '#1B5E20', '#37474F'];
  var pinColour = {};
  function colourFor(courierId) {
    if (PIN_COLOURS[courierId]) { return PIN_COLOURS[courierId]; }
    // A courier added later, before anyone has chosen its colour: give it a stable one from the
    // reserve rather than something that changes with the order the map happens to load them in.
    if (!pinColour[courierId]) {
      pinColour[courierId] = PIN_FALLBACK[Object.keys(pinColour).length % PIN_FALLBACK.length];
    }
    return pinColour[courierId];
  }

  /** The delivery-type glyph, from the same set the checkout's own tabs use (localised in PHP). */
  function typeGlyph(type) {
    var icons = (window.BGCOURIERS && BGCOURIERS.icons) || {};
    return '<span class="bgc-allmap-kind" title="' + esc(typeLabel(type)) + '">' + (icons[type] || '') + '</span>';
  }
  /** "~ 1,52 €" when the figure is this courier's per-type estimate rather than its quoted rate. */
  function priceLabel(p) { return (p.estimated ? '~ ' : '') + p.price; }

  function typeLabel(type) { return (type === 'automat' ? I.automat : I.office) || ''; }

  function isCurrent(p) {
    return !!current.officeId && p.courier === current.courier
        && String(p.office.office_id) === current.officeId;
  }
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
      }
      if (v && v.origin && v.origin.lat && v.origin.lng) {
        origin = { lat: Number(v.origin.lat), lng: Number(v.origin.lng) };
      }
    } catch (e) { /* private mode, or a value from an older version - start fresh */ }
  }
  function save() {
    try {
      window.localStorage.setItem(STORE, JSON.stringify($.extend({}, state, { origin: origin })));
    } catch (e) {}
  }

  function close() {
    if (map) { map.remove(); map = null; }
    meMarker = null;
    layer = null; // the layer group is destroyed along with the map; just drop our reference to it
    markers = []; points = [];
    mode = 'map';
    $(window).off('.bgcallmap');
    $('html, body').removeClass('bgc-allmap-lock');
    if ($dlg) { $dlg.remove(); $dlg = null; }
  }

  /**
   * Show one of the two panes, on the screens too small to hold both.
   *
   * Leaflet measures its container once and caches the result. A map that was display:none while the
   * points were plotted comes back the size it was when it was hidden - which is nothing - so the
   * tiles are grey and fitBounds() left it centred on a rectangle that never existed. Re-measuring on
   * the way IN is what makes the switch safe to use at any moment, and it is cheap enough to do
   * unconditionally rather than trying to work out whether this particular screen is a phone.
   */
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

  /**
   * @param {Object} [opts] {only: courier id to show alone, wrap: the courier block to seed the city
   *                         from}. Passed by a courier's own Map button, which opens THIS dialog
   *                         filtered to itself rather than a second map of its own.
   */
  function open(opts) {
    if ($dlg) { return; }
    opts = opts || {};
    only = opts.only || '';
    current = { courier: '', officeId: '' };
    if (opts.wrap && opts.wrap.length) {
      current = { courier: only || String(opts.wrap.attr('data-courier') || ''),
                  officeId: String(opts.wrap.find('.bgc-office').val() || '') };
    } else {
      // Opened from the shortcut above the rates, which belongs to no courier in particular. It should
      // still know what the customer has already picked - marking it and opening on it is the whole
      // reason the per-courier button feels like it remembers - so the chosen point is read from the
      // block that is currently on screen. `only` is deliberately left empty: this dialog must not
      // filter down to one courier, it just needs to know which point is already theirs.
      var $open = $('.bgc-fields[data-courier]:visible').first();
      if ($open.length) {
        current = { courier: String($open.attr('data-courier') || ''),
                    officeId: String($open.find('.bgc-office').val() || '') };
      }
    }
    load();
    // bgc-mode-map is in the markup, not left to a setMode() call after the append: busy() shows the
    // body and adds bgc-has-map before render() ever runs, and a phone with neither mode class set
    // would draw BOTH panes for that moment - which is the layout this whole dialog stopped using.
    $dlg = $('<div class="bgc-allmap-overlay bgc-mode-map"><div class="bgc-allmap-box">'
      + '<div class="bgc-allmap-head"><strong>' + esc(I.allmap_title || '') + '</strong>'
      + '<button type="button" class="bgc-allmap-close" aria-label="' + esc(I.close || '') + '">&times;</button></div>'
      + '<div class="bgc-allmap-form">'
      + '<div class="bgc-allmap-cityrow">'
      + '<input type="text" class="bgc-allmap-cityinput" autocomplete="off" placeholder="' + esc(I.allmap_city_ph || '') + '">'
      + '<button type="button" class="bgc-allmap-cityclear" hidden aria-label="' + esc(I.clear || '') + '"'
      + ' title="' + esc(I.clear || '') + '">&times;</button>'
      // The same "find me" control, for the state where the other one does not exist yet: the search
      // row lives inside the map's own panel, which is not on screen until there is a town to draw.
      // That is exactly the moment this button is most useful, and it was unreachable.
      + '<button type="button" class="bgc-map-locate bgc-allmap-citylocate" data-tip="' + esc(I.map_locate || '')
      + '" title="' + esc(I.map_locate || '') + '" aria-label="' + esc(I.map_locate || '') + '"></button>'
      + '<ul class="bgc-allmap-cityres" hidden></ul></div>'
      + '<div class="bgc-allmap-legend" hidden></div>'
      + '</div>'
      + '<div class="bgc-allmap-body" style="display:none;">'
      + '<div class="bgc-allmap-side">'
      + '<div class="bgc-allmap-searchrow">'
      + '<input type="text" class="bgc-allmap-search" autocomplete="off" placeholder="' + esc(I.office_ph || '') + '">'
      + '<button type="button" class="bgc-map-locate" data-tip="' + esc(I.map_locate || '')
      + '" aria-label="' + esc(I.map_locate || '') + '"></button>'
      + '</div>'
      + '<ul class="bgc-allmap-list"></ul></div>'
      + '<div class="bgc-allmap-canvas" id="bgc-allmap-canvas"></div>'
      // Floating over the pane rather than sitting in a row of its own: a row would cost the map the
      // very height this dialog is short of. Hidden outright on a screen wide enough for both panes.
      + '<div class="bgc-allmap-switch" role="group">'
      + '<button type="button" data-v="map" class="on" aria-pressed="true">' + esc(I.allmap_map || '') + '</button>'
      + '<button type="button" data-v="list" aria-pressed="false">' + esc(I.allmap_list || '')
      + ' <span class="bgc-allmap-n"></span></button>'
      + '</div>'
      + '</div></div></div>');
    $('body').append($dlg);
    // A phone's dialog covers the screen, and a page still scrolling behind it moves the map out from
    // under the finger that was dragging it. Scoped to narrow screens in the stylesheet, so a desktop
    // keeps its scrollbar and does not shift sideways when the dialog opens.
    $('html, body').addClass('bgc-allmap-lock');
    // Rotating the phone, or the URL bar sliding away, changes the map's box without Leaflet noticing.
    $(window).on('resize.bgcallmap orientationchange.bgcallmap', function () {
      if (map) { map.invalidateSize(); }
    });

    // Our own city suggest rather than select2. select2 positions its dropdown by arithmetic that
    // adds the page's scroll offset, which a position:fixed overlay does not have, so inside this
    // dialog the list landed hundreds of pixels down the screen - twice, fixed two different ways,
    // wrong both times. A list anchored to its own input by CSS cannot drift, and it is less code
    // than the workarounds were.
    var $input = $dlg.find('.bgc-allmap-cityinput');
    var $res = $dlg.find('.bgc-allmap-cityres');
    var searchT = null;

    function hideRes() { $res.attr('hidden', true).empty(); }
    function syncClear() { $dlg.find('.bgc-allmap-cityclear').attr('hidden', $.trim($input.val()) === '' ? true : null); }
    /** Back to the one question this dialog starts with: no place, so nothing to plot. */
    function clearCity() {
      state.cityName = state.cityCode = state.cityLabel = '';
      save();
      $input.val('').focus();
      hideRes(); syncClear(); busyCity(false);
      if (map) { map.remove(); map = null; layer = null; }
      markers = []; points = [];
      $dlg.removeClass('bgc-has-map');
      $dlg.find('.bgc-allmap-body').hide().find('.bgc-allmap-list').empty();
      $dlg.find('.bgc-allmap-legend').attr('hidden', true).empty();
      $dlg.find('.bgc-allmap-canvas').empty();
    }
    $dlg.on('click', '.bgc-allmap-cityclear', function (e) { e.preventDefault(); clearCity(); });
    function pickCity(name, code, label) {
      state.cityName = name; state.cityCode = code; state.cityLabel = label;
      $input.val(label);
      hideRes(); save(); syncClear();
      // Choosing a place IS the instruction to show it. A button afterwards asked the customer to
      // confirm a decision they had just made.
      showOffices();
    }
    function fill(rows) {
      $res.empty();
      (rows || []).forEach(function (r) {
        var label = r.name + (r.post_code ? ' (' + r.post_code + ')' : '');
        $('<li class="bgc-allmap-cityopt"></li>').text(label)
          .attr({ 'data-name': r.name, 'data-code': r.post_code || '' }).appendTo($res);
      });
      $res.attr('hidden', !$res.children().length);
    }
    pickCityFn = pickCity;
    $input.on('input', function () {
      var term = $.trim($input.val());
      syncClear();
      clearTimeout(searchT);
      if (term.length < 2) { hideRes(); busyCity(false); return; }

      // Local first, and normally the only path. Asking the server costs a whole admin-ajax boot -
      // measured at ~5s on the live shop for a request that returns 150 bytes, and the same 5s for an
      // action with no handler at all, so it is the boot and not this lookup. The courier's own city
      // box has always felt instant for exactly this reason: it filters a list the page already has.
      var local = localCities(term);
      if (local) { busyCity(false); fill(local); return; }

      // No preloaded index on this shop: the round trip is unavoidable, so at least say it is happening.
      // Silence for five seconds is indistinguishable from a broken field.
      busyCity(true);
      searchT = setTimeout(function () {
        $.get(BGCOURIERS.ajax, { action: 'bgcouriers_allmap_cities', term: term }, function (rows) {
          fill(rows);
        }).always(function () { busyCity(false); });
      }, 220);
    });
    $res.on('click', '.bgc-allmap-cityopt', function () {
      pickCity($(this).attr('data-name'), $(this).attr('data-code'), $(this).text());
    });
    // Typing something new and walking away must not leave a stale place selected.
    $input.on('blur', function () { setTimeout(hideRes, 150); });

    // The city the CHECKOUT is on right now wins over the one remembered from last time. It used to be
    // the other way round - remembered first, checkout only as a fallback - so a customer who set their
    // courier to Ахелой and then opened the map was shown София, because that is where they had been
    // looking on some earlier visit. The remembered place is a courtesy for when the checkout has no
    // city yet; it is not news, and it must never overrule what the customer has just chosen.
    // seedFromCheckout() leaves the loaded values alone when it finds no city, so this is safe to call
    // unconditionally.
    seedFromCheckout(opts.wrap);
    if (state.cityLabel) { $input.val(state.cityLabel); showOffices(); }
    syncClear();

    $dlg.on('click', '.bgc-allmap-close', close);
    // Guarded: choosing a point closes the dialog and nulls $dlg, and THIS handler still runs as the
    // same click finishes bubbling - dereferencing a variable the click itself just emptied. It threw
    // a TypeError into the console of every checkout where somebody chose an office.
    $dlg.on('click', function (e) { if ($dlg && e.target === $dlg[0]) { close(); } });
    $dlg.on('input', '.bgc-allmap-search', applyFilter);
    $dlg.on('click', '.bgc-map-locate', function (e) { e.preventDefault(); showMe(); });
    $dlg.on('click', '.bgc-allmap-switch button', function () { setMode($(this).attr('data-v')); });
    // The popup's Choose is the only crossing into bgc-checkout.js: hand over the point and let the
    // ordinary flow pick the rate, switch the tab, set city and office, save, recalculate. Delegated
    // on $dlg (not $list) because the popup's markup lives in Leaflet's map pane, not the sidebar.
    $dlg.on('click', '.bgc-allmap-pick', function () {
      var p = points[+$(this).data('i')];
      if (!p || !window.BGCouriersCheckout) { return; }
      var ok = window.BGCouriersCheckout.applyPick({
        courier: p.courier, method: p.type,
        cityId: p.cityId, cityLabel: state.cityLabel,
        postCode: state.cityCode,   // courier-agnostic, so a later courier switch can find this town again
        officeId: p.office.office_id,
        officeLabel: (p.office.name || '') + ' - ' + (p.office.address || '')
      });
      if (ok) { close(); } // a courier applyPick() cannot find on this page leaves the dialog open
    });
  }

  /**
   * Everything that depends on WHERE the customer is: the per-row distances, the order of the list,
   * each courier's nearest point in the legend, and the one line that actually answers the question.
   *
   * Recomputed on every origin change and every filter change, because "nearest" has to mean nearest
   * among the points the customer can actually order - a courier switched off in the legend, or one
   * that cannot carry this order at all, must never be recommended.
   */
  function refreshNear() {
    if (!$dlg) { return; }
    var $list = $dlg.find('.bgc-allmap-list');
    $dlg.toggleClass('bgc-has-origin', !!origin);
    if (!origin) {
      $list.find('.bgc-allmap-dist').remove();
      $dlg.find('.bgc-allmap-near').remove();
      $dlg.find('.bgc-allmap-chip .bgc-chip-d').remove();
      return;
    }

    // Distance onto every row, and the best CHOOSABLE point per courier.
    var best = {}, overall = null;
    points.forEach(function (p, i) {
      var d = distOf(p);
      var $row = $list.find('.bgc-allmap-item[data-i="' + i + '"]');
      $row.find('.bgc-allmap-dist').remove();
      if (d == null) { return; }
      $row.find('.a').append('<span class="bgc-allmap-dist">' + esc(fmtDist(d)) + '</span>');
      if (!p.available || hidden[p.courier]) { return; }   // not orderable: cannot be "nearest"
      if (!best[p.courier] || d < best[p.courier].d) { best[p.courier] = { d: d, p: p }; }
      if (!overall || d < overall.d) { overall = { d: d, p: p }; }
    });

    // Nearest first. The rows keep their data-i, so only their ORDER changes - the array behind them
    // is untouched, and every index the markers and the popups carry stays valid.
    var rows = $list.children('.bgc-allmap-item').get();
    rows.sort(function (a, b) {
      var da = distOf(points[+$(a).data('i')]);
      var db = distOf(points[+$(b).data('i')]);
      if (da == null) { return 1; }
      if (db == null) { return -1; }
      return da - db;
    });
    $list.append(rows);

    // Each courier's own nearest, on its legend chip: on a map carrying four couriers the comparison
    // IS the answer, and the chips are already the place the eye goes to compare them.
    $dlg.find('.bgc-allmap-chip').each(function () {
      var cid = $(this).attr('data-c');
      $(this).find('.bgc-chip-d').remove();
      if (best[cid]) { $(this).append('<span class="bgc-chip-d">' + esc(fmtDist(best[cid].d)) + '</span>'); }
    });

    renderNearLine(overall);
  }

  /** The sentence the whole feature exists for: how far, how much, and what it saves. */
  function renderNearLine(overall) {
    $dlg.find('.bgc-allmap-near').remove();
    if (!overall) { return; }
    var p = overall.p;
    var addr = (p.addressPrice || '');
    var html = '<div class="bgc-allmap-near">'
      + '<span class="bgc-near-lead">' + esc(I.near_title || '') + '</span>'
      + (p.logo ? '<img src="' + esc(p.logo) + '" alt="' + esc(p.courierLabel) + '">' : '')
      + '<span class="bgc-near-c">' + esc(p.courierLabel) + '</span>'
      + typeGlyph(p.type)
      + '<span class="bgc-near-d" title="' + esc(I.near_straight || '') + '">' + esc(fmtDist(overall.d)) + '</span>'
      + (p.price ? '<span class="bgc-near-p">' + esc(priceLabel(p)) + '</span>' : '');
    if (addr) {
      html += '<span class="bgc-near-vs">' + esc(I.near_to_address || '') + ' <b>' + esc(addr) + '</b>'
        + (p.savesVsAddress
            ? ' <span class="bgc-near-save">' + esc(I.near_save || '') + ' ' + esc(p.savesVsAddress) + '</span>'
            : '')
        + '</span>';
    }
    html += '</div>';
    $dlg.find('.bgc-allmap-body').before(html);
  }

  /**
   * One filter, two conditions - which couriers are switched on, and what has been typed in the
   * search. Both apply to the list AND the map: a row the map does not show, or a pin the list does
   * not have, is the two halves telling the customer different things.
   */
  function applyFilter() {
    if (!$dlg) { return; }
    var term = String($dlg.find('.bgc-allmap-search').val() || '').toLowerCase();
    function shown(p) {
      if (!p || hidden[p.courier]) { return false; }
      if (!term) { return true; }
      var o = p.office || {};
      return String(o.name || '').toLowerCase().indexOf(term) !== -1
          || String(o.address || '').toLowerCase().indexOf(term) !== -1;
    }
    // The count rides on the List button because on a phone the list is behind the map: "List (3)"
    // after typing a street is the only way to know the search found anything without switching over.
    var n = 0;
    $dlg.find('.bgc-allmap-list .bgc-allmap-item').each(function () {
      var on = shown(points[+$(this).data('i')]);
      $(this).toggle(on);
      if (on) { n++; }
    });
    $dlg.find('.bgc-allmap-n').text('(' + n + ')');
    // "Nearest" has to follow the filter: a courier the customer just switched off must stop being
    // recommended, and the one behind it becomes the answer.
    refreshNear();
    points.forEach(function (p, i) {
      var mk = markers[i];
      if (!mk || !layer) { return; }
      var on = shown(p);
      if (!on && layer.hasLayer(mk)) { layer.removeLayer(mk); }
      if (on && !layer.hasLayer(mk)) { layer.addLayer(mk); }
    });
  }

  /**
   * The place already chosen in whichever courier block is open, if there is one. The customer has
   * told the checkout where they are once; asking again is the small rudeness that makes a dialog
   * feel like a form.
   */
  function seedFromCheckout($from) {
    var $w = ($from && $from.length) ? $from : $('.bgc-fields[data-courier]:visible').first();
    if (!$w.length) { return; }
    var label = $.trim($w.find('.bgc-city option:selected').text() || '');
    if (!label) { return; }
    var m = label.match(/^(.*?)\s*\((\d+)\)\s*$/);
    state.cityName  = m ? m[1] : label;
    state.cityCode  = m ? m[2] : String($w.find('.bgc-postcode').val() || '');
    state.cityLabel = label;
  }

  /**
   * Say that the couriers are being asked.
   *
   * Two shapes, because there are two situations. Opening the dialog on a remembered place used to
   * unroll the FULL two-pane layout immediately - an empty sidebar, an empty search box and a small
   * spinner adrift in about 900x400 of white - and then sit there for the length of an admin-ajax
   * round trip. That reads as nothing happening, which is exactly what it was reported as. Before
   * there is anything to show, the dialog stays its compact size and says so in words under the city
   * field. Once a map exists, changing the city dims THAT instead, because the previous answer is
   * still on screen and worth keeping until the new one arrives.
   */
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

  /**
   * Which couriers the checkout is offering, and what it is charging for each. Read from the rate rows
   * already on the page: those are the numbers the customer is being shown, so the map cannot
   * contradict the list beside it. A courier with no row here is not available for this cart.
   */
  function couriersOnPage() {
    var out = {};
    $('input[name^="shipping_method"]').each(function () {
      var v = String(this.value || '');
      if (v.indexOf('bgcouriers_') !== 0) { return; }
      var id = v.replace('bgcouriers_', '').split(':')[0];   // rate values carry an instance id
      var $row = $(this).closest('li');
      var $amt = $row.find('.woocommerce-Price-amount').first();
      var $logo = $row.find('img').first();
      out[id] = {
        available: true,
        price: $amt.length ? $amt.text() : '',
        // WHICH delivery type that price belongs to. A rate row shows one number - the one for the type
        // this courier is currently set to - so it is exact for that type and wrong for the others.
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
    // Every courier's points for a city is a real wait - four live lookups behind one request - and
    // with nothing on screen the dialog looked like the button had not worked. The plugin's own
    // spinner, in the space the map is about to fill, so the wait is where the eye already is.
    busy(true);
    $.get(BGCOURIERS.ajax, {
      action: 'bgcouriers_allmap_offices',
      name: state.cityName, post_code: state.cityCode, type: 'both'
    }, function (data) { cache[key] = data || {}; render(cache[key]); })
     .always(function () { busy(false); });
  }

  function render(data) {
    // Back to the map before anything is plotted. fitBounds() on a container that is display:none
    // measures zero and leaves the map centred on a rectangle that does not exist - and unlike the
    // container's SIZE, which invalidateSize() repairs on the way in, that bad centre survives the
    // switch: the customer taps Map and lands in the sea. Showing the map is also what a customer who
    // has just named a new place is asking for.
    setMode('map');
    // Wipe the PREVIOUS run's pins before plotting new ones. `points`/`markers` below get reassigned to a
    // fresh array on every render, but Leaflet does not know that - a marker it already placed stays on
    // the map with a popup whose Choose button still carries the index into the array it was built
    // against. Left in place, clicking that leftover pin would resolve the NEW `points` array at the OLD
    // index and apply a different courier/city/office than the pin the customer actually clicked.
    if (layer) { layer.clearLayers(); }
    var live = couriersOnPage();
    points = []; markers = [];
    Object.keys(data).forEach(function (cid) {
      var c = live[cid] || { available: false, price: '', method: '', logo: '', label: cid };
      var est = data[cid].prices || {};
      (data[cid].offices || []).forEach(function (o) {
        var type = o.type === 'automat' ? 'automat' : 'office';
        // The rate row's number is EXACT, but only for the type that courier is currently set to.
        // Every other type gets the server's per-type figure, marked as the estimate it is - which is
        // the same thing the rate row itself shows before a city is chosen. Labelling all of them with
        // the row's one number is what advertised Speedy's lockers at its office price and back again.
        var exact = c.available && c.method === type && c.price;
        points.push({
          courier: cid, courierLabel: c.label || cid, logo: c.logo || '',
          available: !!c.available,
          // What THIS courier charges to deliver to the door - the number an office is being compared
          // against. Not a point on the map, which is exactly why it has to travel on the points.
          addressPrice: est.address || '',
          savesVsAddress: (data[cid].saves || {})[type] || '',
          price: exact ? c.price : (est[type] || c.price || ''),
          estimated: !exact && !!est[type],
          cityId: data[cid].city_id,          // that courier's OWN id
          // Per POINT, not per dialog: office and locker share one map, and this is what the
          // checkout's delivery type must be set to when this particular point is chosen.
          type: type,
          office: o
        });
      });
    });

    // The box lifts to the top and the map unrolls out from under the city field. Adding the class
    // AFTER the rows exist means the height it animates to is the real one.
    $dlg.find('.bgc-allmap-body').show();
    $dlg.addClass('bgc-has-map');
    var $list = $dlg.find('.bgc-allmap-list').empty();
    if (!map) {
      map = L.map('bgc-allmap-canvas', { scrollWheelZoom: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
      layer = L.layerGroup().addTo(map); // holds this and every later render's pins, so they can be cleared as one
    }
    var bounds = [], chosenAt = null;
    points.forEach(function (p, i) {
      // Inline style, not a class: the colour is assigned at runtime (first-seen-courier order), so
      // there is no fixed set of classes to put in a stylesheet. This markup is built here in JS and
      // printed straight into the DOM, not passed through wp_kses, so the attribute is fine as-is.
      $list.append('<li class="bgc-allmap-item' + (p.available ? '' : ' bgc-na')
        + (isCurrent(p) ? ' bgc-chosen' : '') + '" data-i="' + i + '"'
        + ' style="border-left-color:' + colourFor(p.courier) + '">'
        + (p.logo ? '<img src="' + esc(p.logo) + '" alt="' + esc(p.courierLabel) + '">' : '')
        + '<span><span class="n">' + typeGlyph(p.type) + esc(p.office.name || '') + '</span>'
        + '<span class="a">' + esc(p.office.address || '') + '</span>'
        + (p.available ? '' : '<span class="bgc-allmap-na-note">' + esc(I.allmap_na || '') + '</span>')
        + '</span>'
        + (p.available && p.price ? '<span class="p">' + esc(priceLabel(p)) + '</span>' : '')
        + '</li>');
      var lat = Number(p.office.lat), lng = Number(p.office.lng);
      if (!lat && !lng) { return; }          // no coordinates: it stays in the list, off the map
      // The chosen one keeps its courier's colour - a colour of its own would fight the legend, which
      // is the one thing on this map that has to stay true. It pulses instead: motion says "this one"
      // without taking a hue away from anybody.
      var mk = L.marker([lat, lng], { icon: pinIcon(p.courier, p.available, isCurrent(p)),
        zIndexOffset: isCurrent(p) ? 1000 : 0 }).addTo(layer);
      // Three lines, in the order a person reads them: WHOSE it is, WHAT it is called, WHERE it is.
      // The price rides on the courier line because that is what it belongs to, not to the address.
      // Three rows, each answering one question. WHO carries it and HOW it is collected belong
      // together - they are the pair that decides whether this point suits you at all - with the price
      // small at the end of that same line. Then WHICH one it is, then WHERE it is, a step quieter.
      mk.bindPopup('<div class="bgc-allmap-pop">'
        + '<div class="bgc-allmap-pop-c">'
        + (p.logo ? '<img src="' + esc(p.logo) + '" alt="' + esc(p.courierLabel) + '">' : '')
        + '<span class="c">' + esc(p.courierLabel) + '</span>'
        + typeGlyph(p.type)
        + '<span class="t">' + esc(typeLabel(p.type)) + '</span>'
        + (p.available && p.price ? '<span class="bgc-allmap-pop-price">' + esc(priceLabel(p)) + '</span>' : '')
        + '</div>'
        + '<div class="bgc-allmap-pop-n">' + esc(p.office.name || '') + '</div>'
        + '<div class="bgc-allmap-pop-a">' + esc(p.office.address || '')
        // "How do I get there, and how long does it take" is the question a pin cannot answer and a
        // map application can. No origin is given, so Google starts from wherever the customer actually
        // is - which also means this needs no location permission of our own. Opened in a new tab: the
        // checkout behind it is half-filled in and must not be navigated away from.
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
        + '</div>'
        + (p.available
            ? '<button type="button" class="button bgc-allmap-pick" data-i="' + i + '">' + esc(I.allmap_choose || '') + '</button>'
            : '<em class="bgc-allmap-pop-na">' + esc(I.allmap_na || '') + '</em>')
        + '</div>', {
          // Leaflet pans until the popup fits the CONTAINER, and knows nothing about the Map/List pill
          // floating over the bottom of it. Measured on a 390x844 screen without this: tapping the
          // lowest pin put the Choose button 49px UNDERNEATH the pill - painted, and impossible to
          // press. The pill occupies 51px (37 tall, 14 up from the bottom); 78 leaves ~25px of daylight
          // for a popup made taller by a two-line office name.
          // Wide enough for "Sameday · До автомат · ~ 1,57 €" to stay on one line, and no wider: the
          // popup grows to its content between these two.
          minWidth: 210, maxWidth: 330,
          autoPanPaddingBottomRight: L.point(12, 78),
          // ...and the zoom buttons occupy the opposite corner: 10px in from the edge, two 30px
          // squares tall. Without this the +/- printed straight across the courier and office names.
          autoPanPaddingTopLeft: L.point(58, 82)
        });
      markers[i] = mk; bounds.push([lat, lng]);
      if (isCurrent(p)) { chosenAt = [lat, lng]; }
    });
    /**
     * Measure the container, then frame the points inside it - in that order, and again once the box
     * has finished growing.
     *
     * The dialog opens compact and widens to its full size when a map arrives, and that width is a CSS
     * transition: for the third of a second it takes, the map's container is still the narrow one.
     * Leaflet caches whatever it measured, so fitting here alone left the map drawn at the old width
     * with the rest of the panel blank - the same stale-measurement trap as the phone panes, arriving
     * this time through an animation I added.
     */
    function frame() {
      if (!map) { return; }
      map.invalidateSize();
      if (!bounds.length) { map.setView([42.73, 25.3], 7); return; }
      // Computed from the BOUNDS, never from the zoom the map happens to be on. It used to read
      // getZoom() + 2 after a fitBounds, which is only correct if it runs exactly once - and this now
      // runs up to three times per render (immediately, on the width transition, and on the timeout
      // behind it), so each pass zoomed two steps further in than the last and the map ended up four to
      // six steps past where it should be. Framed this way the answer is the same however often it is
      // asked, which is what lets it be called freely.
      // If this courier already HAS a point chosen, the map opens on it rather than on the whole town.
      // Centring on the city meant re-opening the dialog put the customer back at the start, hunting a
      // ring among two hundred identical dots for the choice they had already made. The city is still
      // one zoom-out away; their own pick is not findable at all by scanning.
      if (chosenAt) {
        map.setView(chosenAt, 16, { animate: false });
        return;
      }
      var box = L.latLngBounds(bounds);
      // Every point in view. It used to open two steps closer than that, on the reasoning that street
      // names are only readable further in - but a customer who has just named a town wants to see what
      // it HAS, and the outlying points were simply off the screen with nothing to say they existed.
      // The padding keeps them off the very edge, and a town with one or two points is capped rather
      // than zoomed to the rooftops.
      map.setView(box.getCenter(), Math.min(map.getBoundsZoom(box, false, L.point(34, 34)), 16),
        { animate: false });
    }
    frame();  // now, so the tiles start loading at once
    // ...and again when the box stops growing. Both, rather than only the second: transitionend does
    // not fire when the transition is suppressed (prefers-reduced-motion), and the timeout is the net
    // for that as much as for a missed event.
    $dlg.find('.bgc-allmap-box').off('transitionend.bgcfit').on('transitionend.bgcfit', function (e) {
      if (e.originalEvent && e.originalEvent.propertyName === 'width') { frame(); }
    });
    setTimeout(frame, 420);

    // The legend says which colour is whose AND doubles as the filter - on a map carrying four
    // couriers at once, "which of these dots is Econt" is the first question, and "show me only
    // Econt" is the second. Built from the couriers that actually have points in THIS place.
    var seen = [], $legend = $dlg.find('.bgc-allmap-legend').empty();
    points.forEach(function (p) { if (seen.indexOf(p.courier) === -1) { seen.push(p.courier); } });
    seen.forEach(function (cid) {
      var first = points.filter(function (p) { return p.courier === cid; })[0] || {};
      var label = first.courierLabel || cid;
      // Colour AND logo: the colour is what identifies a pin on the map, the logo is what the customer
      // actually recognises. The chip has to carry both or it only answers half the question.
      $legend.append('<button type="button" class="bgc-allmap-chip on" data-c="' + esc(cid) + '">'
        + '<span class="bgc-allmap-swatch" style="background:' + colourFor(cid) + '"></span>'
        + (first.logo ? '<img src="' + esc(first.logo) + '" alt="' + esc(label) + '">' : '')
        + esc(label) + '</button>');
    });
    // Opened from one courier's button: start with just that courier, but say so with the legend
    // rather than by hiding the others - the whole point is that the rest are one click away.
    if (only) {
      seen.forEach(function (cid) { if (cid !== only) { hidden[cid] = true; } });
      $legend.find('.bgc-allmap-chip').each(function () {
        $(this).toggleClass('on', $(this).attr('data-c') === only);
      });
    }
    $legend.attr('hidden', !seen.length);
    $legend.off('click').on('click', '.bgc-allmap-chip', function () {
      var cid = $(this).attr('data-c');
      hidden[cid] = !hidden[cid];
      $(this).toggleClass('on', !hidden[cid]);
      applyFilter();
    });
    applyFilter();

    refreshNear();

    $list.off('click').on('click', '.bgc-allmap-item:not(.bgc-na)', function () {
      var mk = markers[+$(this).data('i')];
      if (!mk) { return; }
      $list.find('.active').removeClass('active');
      $(this).addClass('active');
      // On a phone the list is covering the map, so picking a row means "show me this one" - go to the
      // map first, THEN centre it, or the pan and the popup both happen somewhere nobody can see.
      setMode('map');
      map.setView(mk.getLatLng(), Math.max(map.getZoom(), 15));
      mk.openPopup();
    });
  }

  /**
   * Where the customer is, and the few points nearest them - not the whole city again. Same behaviour
   * as the per-courier picker so the two dialogs answer this the same way.
   */
  var meMarker = null, pickCityFn = null;

  /**
   * No town chosen yet, and the customer pressed "find me": answer the question they actually asked.
   * Without this the button did nothing at all in that state - there is no map to centre and no points
   * to measure against - which is the one moment it would be most useful.
   */
  function cityFromPosition(here) {
    busyCity(true);
    $.get(BGCOURIERS.ajax, { action: 'bgcouriers_geocode', lat: here[0], lng: here[1] }, function (geo) {
      var town = geo && geo.city ? String(geo.city) : '';
      if (!town || !pickCityFn) { return; }
      // Resolved against the places this map can actually plot, not taken at face value: the geocoder
      // spells towns its own way, and a name no courier lists would give an empty map.
      var rows = localCities(town) || [];
      var hit = rows[0] || null;
      if (geo.postcode) {
        rows.forEach(function (r) { if (String(r.post_code) === String(geo.postcode)) { hit = r; } });
      }
      if (!hit) { return; }
      pickCityFn(hit.name, hit.post_code, hit.name + (hit.post_code ? ' (' + hit.post_code + ')' : ''));
    }).always(function () { busyCity(false); });
  }

  /** Put the pin in the middle of the current view, for the customer to drag onto themselves. */
  function dropPin() {
    if (!map) { return; }
    var c = map.getCenter();
    origin = { lat: c.lat, lng: c.lng };
    save();
    if (meMarker) { meMarker.setLatLng(c); }
    else { placeMe([c.lat, c.lng]); }
    refreshNear();
    if (meMarker && meMarker.openTooltip) { meMarker.openTooltip(); }
  }

  /**
   * The customer's own pin. Kept in one place because two things put it on the map now: the locate
   * button, and dropping it manually when geolocation is refused or unavailable.
   */
  function placeMe(here) {
    if (!map) { return; }
    if (meMarker) { meMarker.setLatLng(here); return; }
    // A teardrop, not another dot. This used to be a filled circle in blue, which is the one shape
    // every courier pin on this map already has - so "where I am" was indistinguishable from "an
    // Econt office", and on a screen carrying nine hundred circles it simply disappeared. Telling
    // them apart by SHAPE survives any future courier colour; the ring underneath pulses, which
    // nothing else here does except the point already chosen.
    meMarker = L.marker(here, {
      // Draggable, because geolocation is often a street or two out and the customer knows better
      // than the browser where they are standing. Dragging it re-answers the whole question, which
      // costs nothing: every distance is worked out here, over points already loaded.
      draggable: true,
      zIndexOffset: 2000,          // above every courier pin, including a chosen one
      icon: L.divIcon({
        className: 'bgc-allmap-me',
        html: '<span class="bgc-me-ring"></span>'
      + '<svg viewBox="0 0 24 34" width="24" height="34" aria-hidden="true">'
      + '<path d="M12 0C5.4 0 0 5.4 0 12c0 8.4 12 22 12 22s12-13.6 12-22c0-6.6-5.4-12-12-12z"/>'
      + '<circle cx="12" cy="12" r="4.4"/></svg>',
        iconSize: [24, 34], iconAnchor: [12, 34]
      })
    }).addTo(map);
    meMarker.bindTooltip(I.near_drag || I.map_locate || '', { direction: 'top', offset: [0, -34] });
    meMarker.on('dragend', function () {
      var ll = meMarker.getLatLng();
      origin = { lat: ll.lat, lng: ll.lng };
      save();
      refreshNear();
    });
  }

  function showMe() {
    if (!navigator.geolocation) { dropPin(); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var here = [pos.coords.latitude, pos.coords.longitude];
      origin = { lat: here[0], lng: here[1] };
      save();
      if (!state.cityName || !map) { cityFromPosition(here); return; }
      placeMe(here);
      var visible = points.filter(function (p, i) {
        return markers[i] && layer && layer.hasLayer(markers[i]);
      });
      if (!visible.length) { map.setView(here, 14); return; }
      // Longitude degrees are shorter than latitude ones this far north; ~cos(42°) keeps "nearest"
      // honest without doing real geodesics for a map frame.
      var near = visible.map(function (p) {
        var dlat = Number(p.office.lat) - here[0], dlng = (Number(p.office.lng) - here[1]) * 0.74;
        return { ll: [Number(p.office.lat), Number(p.office.lng)], d: dlat * dlat + dlng * dlng };
      }).sort(function (a, b) { return a.d - b.d; }).slice(0, 6).map(function (x) { return x.ll; });
      map.fitBounds([here].concat(near), { padding: [45, 45], maxZoom: 16 });
      refreshNear();
    }, function () {
      // Refused, or no fix. Not a dead end: drop the pin in the middle of what they are looking at and
      // let them drag it where they live, which is the same answer by a different route.
      dropPin();
    }, { enableHighAccuracy: true, timeout: 8000 });
  }

  /**
   * Fetch the remembered place's points before anybody asks for them, so the dialog opens onto a map
   * rather than onto a wait.
   *
   * This is here instead of the staggered per-courier loading it replaces, because the measurements do
   * not support staggering. On the live shop: an action with NO handler answers in ~3-4s, ONE courier's
   * offices in ~4.2s, and ALL FOUR couriers in ~3.0s for 374KB. Asking for four costs no more than
   * asking for one - the entire wait is WordPress booting, which every request pays in full. Splitting
   * the request per courier would therefore show the first pins no sooner and multiply the server's
   * work by four; done one after another it would be several times slower.
   *
   * What removes the wait is not asking later. Only for a place the customer has already looked at -
   * that is both the strongest signal they will open the map again and exactly the case where the
   * dialog opens straight into a fetch - and only once the page has gone quiet, so it never competes
   * with the checkout's own recalculations.
   */
  function prefetch() {
    if (!window.BGCOURIERS || !BGCOURIERS.ajax) { return; }
    load();
    seedFromCheckout();   // same order of precedence as open(), so the RIGHT place is the warm one
    if (!state.cityName) { return; }
    var key = state.cityName + '|' + state.cityCode + '|both';
    if (cache[key]) { return; }
    $.get(BGCOURIERS.ajax, {
      action: 'bgcouriers_allmap_offices',
      name: state.cityName, post_code: state.cityCode, type: 'both'
    }, function (data) { cache[key] = data || {}; });
  }
  $(function () {
    if (window.requestIdleCallback) { window.requestIdleCallback(prefetch, { timeout: 5000 }); }
    else { setTimeout(prefetch, 3000); }
  });

  $(document).on('click', '.bgc-allmap-btn', function (e) { e.preventDefault(); open(); });
  window.BGCouriersAllMap = { open: open, close: close, points: function () { return points; } };
})(jQuery);
