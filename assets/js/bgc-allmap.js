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

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

  // One colour per courier, so the map answers "whose is that pin" without a click - which is the only
  // reason to put four couriers on one map at all. divIcon, not an image: nothing to fetch, and it
  // takes the muted treatment for an unavailable courier for free.
  var PIN_COLOURS = ['#2271b1', '#00814f', '#b26b00', '#8b5cf6', '#b32d2e'];
  var pinColour = {};
  function colourFor(courierId) {
    if (!pinColour[courierId]) {
      pinColour[courierId] = PIN_COLOURS[Object.keys(pinColour).length % PIN_COLOURS.length];
    }
    return pinColour[courierId];
  }
  /** The delivery-type glyph, from the same set the checkout's own tabs use (localised in PHP). */
  function typeGlyph(type) {
    var icons = (window.BGCOURIERS && BGCOURIERS.icons) || {};
    return '<span class="bgc-allmap-kind" title="' + esc(typeLabel(type)) + '">' + (icons[type] || '') + '</span>';
  }
  function typeLabel(type) { return (type === 'automat' ? I.automat : I.office) || ''; }

  function pinIcon(courierId, available) {
    return L.divIcon({
      className: 'bgc-allmap-pin' + (available ? '' : ' bgc-na'),
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
    layer = null; // the layer group is destroyed along with the map; just drop our reference to it
    markers = []; points = [];
    if ($dlg) { $dlg.remove(); $dlg = null; }
  }

  function open() {
    if ($dlg) { return; }
    load();
    $dlg = $('<div class="bgc-allmap-overlay"><div class="bgc-allmap-box">'
      + '<div class="bgc-allmap-head"><strong>' + esc(I.allmap_title || '') + '</strong>'
      + '<button type="button" class="bgc-allmap-close" aria-label="' + esc(I.close || '') + '">&times;</button></div>'
      + '<div class="bgc-allmap-form">'
      + '<label class="bgc-allmap-lbl">' + esc(I.city_label || '') + '</label>'
      + '<div class="bgc-allmap-cityrow">'
      + '<input type="text" class="bgc-allmap-cityinput" autocomplete="off" placeholder="' + esc(I.city_ph || '') + '">'
      + '<ul class="bgc-allmap-cityres" hidden></ul></div>'
      + '<button type="button" class="button bgc-allmap-show" disabled>' + esc(I.allmap_show || '') + '</button>'
      + '</div>'
      + '<div class="bgc-allmap-body" style="display:none;">'
      + '<div class="bgc-allmap-side"><ul class="bgc-allmap-list"></ul></div>'
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
      hideRes(); save(); refreshShow();
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

    if (state.cityLabel) { $input.val(state.cityLabel); }
    refreshShow();

    $dlg.on('click', '.bgc-allmap-close', close);
    $dlg.on('click', function (e) { if (e.target === $dlg[0]) { close(); } });
    $dlg.on('click', '.bgc-allmap-show', showOffices);
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

  /** Nothing is plotted until a place is chosen - the whole country at once would be unusable. */
  function refreshShow() {
    $dlg.find('.bgc-allmap-show').prop('disabled', !state.cityName);
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
    $.get(BGCOURIERS.ajax, {
      action: 'bgcouriers_allmap_offices',
      name: state.cityName, post_code: state.cityCode, type: 'both'
    }, function (data) { cache[key] = data || {}; render(cache[key]); });
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

    $dlg.find('.bgc-allmap-body').show();
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
      $list.append('<li class="bgc-allmap-item' + (p.available ? '' : ' bgc-na') + '" data-i="' + i + '"'
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
      var mk = L.marker([lat, lng], { icon: pinIcon(p.courier, p.available) }).addTo(layer);
      mk.bindPopup('<div class="bgc-allmap-pop"><strong>' + esc(p.office.name || '') + '</strong><br>'
        + esc(p.office.address || '') + '<br>' + esc(p.courierLabel) + ' - ' + esc(typeLabel(p.type))
        + (p.available && p.price ? ' - ' + esc(p.price) : '')
        + (p.available
            ? '<br><button type="button" class="button bgc-allmap-pick" data-i="' + i + '">' + esc(I.allmap_choose || '') + '</button>'
            : '<br><em>' + esc(I.allmap_na || '') + '</em>')
        + '</div>');
      markers[i] = mk; bounds.push([lat, lng]);
    });
    if (bounds.length) { map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 }); }
    else { map.setView([42.73, 25.3], 7); }
    map.invalidateSize();

    $list.off('click').on('click', '.bgc-allmap-item:not(.bgc-na)', function () {
      var mk = markers[+$(this).data('i')];
      if (!mk) { return; }
      $list.find('.active').removeClass('active');
      $(this).addClass('active');
      map.setView(mk.getLatLng(), Math.max(map.getZoom(), 15));
      mk.openPopup();
    });
  }

  $(document).on('click', '.bgc-allmap-btn', function (e) { e.preventDefault(); open(); });
  window.BGCouriersAllMap = { open: open, close: close, points: function () { return points; } };
})(jQuery);
