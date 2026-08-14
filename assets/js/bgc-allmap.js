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
    } catch (e) { /* private mode, or a value from an older version - start fresh */ }
  }
  function save() { try { window.localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) {} }

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
    function pickCity(name, code, label) {
      state.cityName = name; state.cityCode = code; state.cityLabel = label;
      $input.val(label);
      hideRes(); save();
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
    $input.on('input', function () {
      var term = $.trim($input.val());
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

    // Seed from the courier block the customer is already using, when the dialog has nothing
    // remembered: they have told the checkout where they are once, and being asked again is the kind
    // of small rudeness that makes a dialog feel like a form.
    if (!state.cityName) { seedFromCheckout(opts.wrap); }
    if (state.cityLabel) { $input.val(state.cityLabel); showOffices(); }

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
    var bounds = [];
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
        + '<div class="bgc-allmap-pop-a">' + esc(p.office.address || '') + '</div>'
        + (p.available
            ? '<button type="button" class="button bgc-allmap-pick" data-i="' + i + '">' + esc(I.allmap_choose || '') + '</button>'
            : '<em class="bgc-allmap-pop-na">' + esc(I.allmap_na || '') + '</em>')
        + '</div>', {
          // Leaflet pans until the popup fits the CONTAINER, and knows nothing about the Map/List pill
          // floating over the bottom of it. Measured on a 390x844 screen without this: tapping the
          // lowest pin put the Choose button 49px UNDERNEATH the pill - painted, and impossible to
          // press. The pill occupies 51px (37 tall, 14 up from the bottom); 78 leaves ~25px of daylight
          // for a popup made taller by a two-line office name.
          autoPanPaddingBottomRight: L.point(12, 78),
          // ...and the zoom buttons occupy the opposite corner: 10px in from the edge, two 30px
          // squares tall. Without this the +/- printed straight across the courier and office names.
          autoPanPaddingTopLeft: L.point(58, 82)
        });
      markers[i] = mk; bounds.push([lat, lng]);
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
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
      // Two steps closer than "everything fits". A city fitted whole is a shape, not a place: the
      // customer is looking for a corner they recognise, and street names only start being readable
      // about here. The outlying points that fall outside the first view are all still in the list
      // beside the map, and the map can be dragged.
      map.setZoom(Math.min(map.getZoom() + 2, 17));
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
  var meMarker = null;
  function showMe() {
    if (!navigator.geolocation || !map) { return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var here = [pos.coords.latitude, pos.coords.longitude];
      if (meMarker) { meMarker.setLatLng(here); }
      else {
        meMarker = L.circleMarker(here, { radius: 7, color: '#fff', weight: 3,
          fillColor: '#2271b1', fillOpacity: 1 }).addTo(map);
      }
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
    }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
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
