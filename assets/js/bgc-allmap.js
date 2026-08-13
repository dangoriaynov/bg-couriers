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

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

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
    if ($dlg) { $dlg.remove(); $dlg = null; }
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
    $dlg = $('<div class="bgc-allmap-overlay"><div class="bgc-allmap-box">'
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
      + '</div></div></div>');
    $('body').append($dlg);

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
    $input.on('input', function () {
      var term = $.trim($input.val());
      clearTimeout(searchT);
      if (term.length < 2) { hideRes(); return; }
      searchT = setTimeout(function () {
        $.get(BGCOURIERS.ajax, { action: 'bgcouriers_allmap_cities', term: term }, function (rows) {
          $res.empty();
          (rows || []).forEach(function (r) {
            var label = r.name + (r.post_code ? ' (' + r.post_code + ')' : '');
            $('<li class="bgc-allmap-cityopt"></li>').text(label)
              .attr({ 'data-name': r.name, 'data-code': r.post_code || '' }).appendTo($res);
          });
          $res.attr('hidden', !$res.children().length);
        });
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
    // The popup's Choose is the only crossing into bgc-checkout.js: hand over the point and let the
    // ordinary flow pick the rate, switch the tab, set city and office, save, recalculate. Delegated
    // on $dlg (not $list) because the popup's markup lives in Leaflet's map pane, not the sidebar.
    $dlg.on('click', '.bgc-allmap-pick', function () {
      var p = points[+$(this).data('i')];
      if (!p || !window.BGCouriersCheckout) { return; }
      var ok = window.BGCouriersCheckout.applyPick({
        courier: p.courier, method: p.type,
        cityId: p.cityId, cityLabel: state.cityLabel,
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
    $dlg.find('.bgc-allmap-list .bgc-allmap-item').each(function () {
      $(this).toggle(shown(points[+$(this).data('i')]));
    });
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

  /** The plugin's standard spinner, filling the map area while the couriers are being asked. */
  function busy(on) {
    if (!$dlg) { return; }
    $dlg.find('.bgc-allmap-body').toggle(true);
    $dlg.addClass('bgc-has-map');
    $dlg.find('.bgc-allmap-busy').remove();
    if (on) {
      $dlg.find('.bgc-allmap-body').append('<div class="bgc-allmap-busy"><span class="bgc-spinner"></span></div>');
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
    // Wipe the PREVIOUS run's pins before plotting new ones. `points`/`markers` below get reassigned to a
    // fresh array on every render, but Leaflet does not know that - a marker it already placed stays on
    // the map with a popup whose Choose button still carries the index into the array it was built
    // against. Left in place, clicking that leftover pin would resolve the NEW `points` array at the OLD
    // index and apply a different courier/city/office than the pin the customer actually clicked.
    if (layer) { layer.clearLayers(); }
    var live = couriersOnPage();
    points = []; markers = [];
    Object.keys(data).forEach(function (cid) {
      var c = live[cid] || { available: false, price: '', logo: '', label: cid };
      (data[cid].offices || []).forEach(function (o) {
        points.push({
          courier: cid, courierLabel: c.label || cid, logo: c.logo || '',
          available: !!c.available, price: c.price || '',
          cityId: data[cid].city_id,          // that courier's OWN id
          // Per POINT, not per dialog: office and locker share one map, and this is what the
          // checkout's delivery type must be set to when this particular point is chosen.
          type: o.type === 'automat' ? 'automat' : 'office',
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
        + (p.available && p.price ? '<span class="p">' + esc(p.price) + '</span>' : '')
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
      mk.bindPopup('<div class="bgc-allmap-pop">'
        + (p.available && p.price ? '<span class="bgc-allmap-pop-price">' + esc(p.price) + '</span>' : '')
        + '<div class="bgc-allmap-pop-c">'
        + (p.logo ? '<img src="' + esc(p.logo) + '" alt="' + esc(p.courierLabel) + '">' : '')
        + '<span class="c">' + esc(p.courierLabel) + '</span>'
        + '</div>'
        + '<div class="bgc-allmap-pop-n">' + typeGlyph(p.type)
        + '<span class="t">' + esc(typeLabel(p.type)) + '</span>'
        + esc(p.office.name || '') + '</div>'
        + '<div class="bgc-allmap-pop-a">' + esc(p.office.address || '') + '</div>'
        + (p.available
            ? '<button type="button" class="button bgc-allmap-pick" data-i="' + i + '">' + esc(I.allmap_choose || '') + '</button>'
            : '<em class="bgc-allmap-pop-na">' + esc(I.allmap_na || '') + '</em>')
        + '</div>');
      markers[i] = mk; bounds.push([lat, lng]);
    });
    if (bounds.length) {
      map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
      // Two steps closer than "everything fits". A city fitted whole is a shape, not a place: the
      // customer is looking for a corner they recognise, and street names only start being readable
      // about here. The outlying points that fall outside the first view are all still in the list
      // beside the map, and the map can be dragged.
      map.setZoom(Math.min(map.getZoom() + 2, 17));
    }
    else { map.setView([42.73, 25.3], 7); }
    map.invalidateSize();

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

  $(document).on('click', '.bgc-allmap-btn', function (e) { e.preventDefault(); open(); });
  window.BGCouriersAllMap = { open: open, close: close, points: function () { return points; } };
})(jQuery);
