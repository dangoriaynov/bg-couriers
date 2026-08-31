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
  // Where the parcel is going, read off the checkout when the dialog opens. The preloaded city index
  // is built for ONE country, so this is what decides whether it may be consulted at all - see
  // localCities(). Fixed at open: the checkout cannot change country underneath an open dialog.
  var dest = '';

  // Distance from `origin` to each point, by the same index as `points`, and the list row for that
  // same index. Both are caches with one job: a legend click must not re-measure anything and must not
  // go looking through the DOM for anything. See measureNear()/pickNear().
  var dists = [], rowEls = [];

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

  /**
   * Is the "closest to you" comparison switched on for this shop?
   *
   * It is the one part of the map that asks the browser for a location, so a merchant who would rather
   * not ask can switch it off without losing the map. Absent (an older page still in a cache) counts as
   * on, which is the default the setting itself carries - hence 'no' rather than a falsy test, because
   * wp_localize_script() hands a PHP false over as '' and an absent key is undefined, and only one of
   * those two means the merchant switched anything off.
   */
  function nearOn() { return !window.BGCOURIERS || BGCOURIERS.allmapNearest !== 'no'; }

  /** Run after the browser has had its chance to paint. */
  function nextFrame(fn) {
    if (window.requestAnimationFrame) { window.requestAnimationFrame(fn); } else { setTimeout(fn, 16); }
  }

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

  /** The one country BGCOURIERS.cityIndex was built for. */
  function homeIndexCountry() {
    var B = window.BGCOURIERS || {};
    return String(B.cityIndexCountry || B.homeCountry || 'BG');
  }
  /**
   * Where the parcel is going, as the checkout has it at this moment. Read from the courier block the
   * dialog was opened from when there is one, otherwise from whichever block is on screen - the same
   * fallback open() uses to find the point already chosen.
   */
  function checkoutCountry($wrap) {
    var $w = ($wrap && $wrap.length) ? $wrap : $('.bgc-fields[data-courier]:visible').first();
    if (!$w.length) { return homeIndexCountry(); }
    var $sel = $w.find('.bgc-country');
    return String(($sel.length && $sel.val()) || $w.attr('data-country') || homeIndexCountry());
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
    // Abroad the index is not merely short of a few towns, it holds none of that country at all, so
    // every lookup in it comes back "no such place" - which is indistinguishable from an answer. The
    // server endpoint knows where the parcel is going and is asked instead. Same rule, same reason, as
    // indexUsable() in bgc-checkout.js.
    if (dest && dest !== homeIndexCountry()) { return null; }
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
    sameday: '#00838F',
    // Express One's mark is orange (#E47800) and graphite - and the orange IS Pigeon's pin: ΔE 2.9, the
    // same colour to anyone reading a legend. The graphite is the grey this palette rules out.
    //
    // The first attempt kept the brand hue and only darkened it (#904E00, ΔE 25 from Pigeon). On paper
    // that beat every pair already on this map; in front of the owner's eyes it was unusable, and he
    // was right - at pin size hue is what the eye sorts by, and two pins of one hue are one colour with
    // a lighting difference. Lightness is not separation.
    //
    // So this follows what Sameday's line above already decided: a courier whose brand colour is taken
    // gets a colour that is NOT in its logo. The hue wheel had exactly two free arcs - green/yellow
    // (which the rule below rules out, it is what OSM paints parks with) and the magenta between BOX
    // NOW's violet and Speedy's crimson. Magenta it is: ΔE 24 from the nearest pin against 15.8 for the
    // closest pair already here, and nothing on an OpenStreetMap tile is this colour.
    expressone: '#E0189B',
    // The seventh courier arrives at a full wheel. The six hues above sit at roughly 29 (Pigeon), 185
    // (Sameday), 222 (Econt), 257 (BOX NOW), 322 (Express One) and 345 (Speedy) degrees, and the one
    // wide gap left - 29 to 185 - is the green/yellow band this palette rules out, because it is what
    // OpenStreetMap paints parks and motorways with. So the widest USABLE gap is 257 to 322, and this
    // sits in the middle of it. Европът's own mark could not be sampled: evropat.com serves its
    // application shell for every path, /favicon.png included, so there is no logo file to read a brand
    // colour out of. PROVISIONAL - Express One's pin was computed the same way and the owner's eye
    // overruled the first attempt; this one has not been through that yet.
    evropat: '#9B26B6'
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
      // The POSITION is deliberately not restored - see save(). A version that did paint the pin and
      // every distance on a freshly loaded page, before the browser had asked anything, which is
      // exactly as unsettling as it sounds. Anything an earlier version left behind is wiped here.
      if (v && v.origin) { save(); }
    } catch (e) { /* private mode, or a value from an older version - start fresh */ }
  }
  /**
   * The remembered TOWN, and nothing else.
   *
   * Where the customer is lives in memory for the life of the page - it survives changing the town,
   * switching courier and closing and reopening the dialog, which is every case it is actually needed
   * for - and goes when the page does. Keeping it in localStorage meant a reload showed somebody their
   * own position and the distance to their nearest office having asked nobody, and went on doing it
   * after the browser's permission had been reset. A permission that can be taken back is not a
   * permission if the answer is kept anyway.
   */
  function save() {
    try {
      window.localStorage.setItem(STORE, JSON.stringify({
        cityName: state.cityName, cityCode: state.cityCode, cityLabel: state.cityLabel
      }));
    } catch (e) {}
  }

  function close() {
    if (map) { map.remove(); map = null; }
    meMarker = null;
    layer = null; // the layer group is destroyed along with the map; just drop our reference to it
    markers = []; points = []; rowEls = []; dists = [];
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
    dest = checkoutCountry(opts.wrap);
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
      + '<input type="text" class="bgc-allmap-cityinput" autocomplete="off" role="combobox"'
      + ' aria-expanded="false" aria-autocomplete="list" aria-controls="bgc-allmap-cityres"'
      + ' placeholder="' + esc(I.allmap_city_ph || '') + '">'
      // The arrow says this is a list to open, not a box to guess into. It shares its slot with the ×
      // below: an empty field has nothing to clear and a whole list to offer, a filled one the other
      // way about. Same label as the field, so no new string to translate for it.
      + '<button type="button" class="bgc-allmap-citycaret" tabindex="-1" aria-label="'
      + esc(I.allmap_city_ph || '') + '"></button>'
      + '<button type="button" class="bgc-allmap-cityclear" hidden aria-label="' + esc(I.clear || '') + '"'
      + ' title="' + esc(I.clear || '') + '">&times;</button>'
      // The same "find me" control, for the state where the other one does not exist yet: the search
      // row lives inside the map's own panel, which is not on screen until there is a town to draw.
      // That is exactly the moment this button is most useful, and it was unreachable.
      // Both of them go when the shop has switched the comparison off: nothing else here asks the
      // browser where the customer is, so leaving the button would be an offer with nothing behind it.
      + (nearOn()
          ? '<button type="button" class="bgc-map-locate bgc-allmap-citylocate" data-tip="' + esc(I.map_locate || '')
            + '" title="' + esc(I.map_locate || '') + '" aria-label="' + esc(I.map_locate || '') + '"></button>'
          : '')
      // The id is fixed rather than generated: open() returns early while a dialog is up, so there is
      // never a second one of these on the page to collide with.
      + '<ul class="bgc-allmap-cityres" id="bgc-allmap-cityres" role="listbox" hidden></ul></div>'
      // The legend and the answer share a column beside the town field. The answer used to be a
      // full-width band between the form and the map; the chips only ever fill the top of this
      // space, and a band cost another 41px of a dialog whose whole job is showing a map.
      + '<div class="bgc-allmap-legendcol"><div class="bgc-allmap-legend" hidden></div></div>'
      + '</div>'
      + '<div class="bgc-allmap-body" style="display:none;">'
      + '<div class="bgc-allmap-side">'
      + '<div class="bgc-allmap-searchrow">'
      + '<input type="text" class="bgc-allmap-search" autocomplete="off" placeholder="' + esc(I.office_ph || '') + '">'
      + (nearOn()
          ? '<button type="button" class="bgc-map-locate" data-tip="' + esc(I.map_locate || '')
            + '" aria-label="' + esc(I.map_locate || '') + '"></button>'
          : '')
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
    var searchT = null, blurT = null;

    function resOpen() { return !$res.is('[hidden]') && !!$res.children().length; }
    function hideRes() {
      $res.attr('hidden', true).empty();
      $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
    }
    // The × and the arrow share one slot at the end of the field, and never both at once.
    function syncClear() {
      var empty = $.trim($input.val()) === '';
      $dlg.find('.bgc-allmap-cityclear').attr('hidden', empty ? true : null);
      $dlg.find('.bgc-allmap-citycaret').attr('hidden', empty ? null : true);
    }
    /** Back to the one question this dialog starts with: no place, so nothing to plot. */
    function clearCity() {
      state.cityName = state.cityCode = state.cityLabel = '';
      save();
      $input.val('').focus();
      hideRes(); syncClear(); busyCity(false);
      // meMarker must go with the map it was on. Without this the reference survived, the next locate
      // took the "already have one" branch and moved a marker that belonged to a destroyed map - so
      // pressing "show my location" after changing the town did nothing at all, silently.
      if (map) { map.remove(); map = null; layer = null; meMarker = null; }
      markers = []; points = []; rowEls = []; dists = [];
      $dlg.removeClass('bgc-has-map');
      $dlg.find('.bgc-allmap-body').hide().find('.bgc-allmap-list').empty();
      $dlg.find('.bgc-allmap-legend').attr('hidden', true).empty();
      // The answer goes with the points it was about. The position itself is kept - the customer has
      // not moved - so naming another town brings the distances straight back.
      $dlg.find('.bgc-allmap-near').remove();
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
      (rows || []).forEach(function (r, i) {
        var label = r.name + (r.post_code ? ' (' + r.post_code + ')' : '');
        // Every row needs an id of its own for aria-activedescendant to be able to name one.
        $('<li class="bgc-allmap-cityopt" role="option"></li>').text(label)
          .attr({ id: 'bgc-allmap-cityopt-' + i, 'data-name': r.name, 'data-code': r.post_code || '' })
          .appendTo($res);
      });
      var any = !!$res.children().length;
      $res.attr('hidden', !any);
      $input.attr('aria-expanded', any ? 'true' : 'false');
      // The place already chosen is marked where the list opens on it, so pressing the arrow says
      // "this one" rather than dropping the customer at the top of a few thousand towns.
      if (any && state.cityLabel) {
        setActive($res.children().filter(function () { return $(this).text() === state.cityLabel; }).first());
      }
      scrollActive();
    }
    /**
     * The highlight, wherever it comes from - the mouse, the arrow keys, or the town already chosen.
     * The class and aria-activedescendant are one thing and not two: a reader announces the row the
     * input POINTS at, so a highlight the attribute did not follow is one only sighted people get.
     */
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
    /**
     * The places on offer for `term`, which may be empty - an empty term is the OPENED DROPDOWN, and
     * both paths answer it with the first towns on the list rather than with nothing.
     */
    function search(term) {
      clearTimeout(searchT);

      // Local first, and normally the only path. Asking the server costs a whole admin-ajax boot -
      // measured at ~5s on the live shop for a request that returns 150 bytes, and the same 5s for an
      // action with no handler at all, so it is the boot and not this lookup. The courier's own city
      // box has always felt instant for exactly this reason: it filters a list the page already has.
      var local = localCities(term);
      if (local) { busyCity(false); fill(local); return; }

      // No preloaded index on this shop, or the parcel is going abroad, where the index holds nothing:
      // the round trip is unavoidable, so at least say it is happening. Silence for five seconds is
      // indistinguishable from a broken field. A single letter is not worth a trip for a list that
      // would come back thousands long and cut to 30; opening the box with nothing typed IS, because
      // that list is the answer to "what can I choose from".
      if (term.length === 1) { hideRes(); busyCity(false); return; }
      busyCity(true);
      searchT = setTimeout(function () {
        $.get(BGCOURIERS.ajax, { action: 'bgcouriers_allmap_cities', term: term }, function (rows) {
          fill(rows);
        }).always(function () { busyCity(false); });
      }, 220);
    }
    /**
     * Open it as a dropdown - everything on offer, whatever is in the box. The chosen name is selected
     * with it, so typing replaces the town instead of appending to "СОФИЯ (1000)" and finding nothing.
     */
    function openList() {
      // A close left over from the press that opened this. Clearing the town takes the focus to the ×
      // and back, and the arrow is a button of its own: either can leave a blur timer in flight that
      // would wipe, a moment later, the list this call is about to put up.
      clearTimeout(blurT);
      if (!$input.is(':focus')) { $input.trigger('focus'); }
      if ($input.val()) { try { $input[0].select(); } catch (e) {} }
      search('');
    }
    $input.on('input', function () { syncClear(); search($.trim($input.val())); });
    // A press on the field opens the list, exactly as pressing the courier's own city select does.
    // This box used to answer only to typing, which asked the customer to name a town before they had
    // been shown that there was a list of them at all.
    $input.on('click', function () { if (!resOpen()) { openList(); } });
    // mousedown with the default prevented, not click: the arrow would otherwise take the focus off the
    // input on the way down, whose blur closes the list - so the press would open a list and shut it
    // again in one go.
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
        // Only when one is picked out: Enter on a list nobody has moved through must not choose the
        // first town in the country, and it must not submit the checkout behind the dialog either.
        if ($a.length) { e.preventDefault(); $a.trigger('click'); }
      }
    });
    $res.on('mouseenter', '.bgc-allmap-cityopt', function () { setActive($(this)); });
    $res.on('click', '.bgc-allmap-cityopt', function () {
      pickCity($(this).attr('data-name'), $(this).attr('data-code'), $(this).text());
    });
    // Typing something new and walking away must not leave a stale place selected. Held in a variable
    // so openList() can call it off - see there.
    $input.on('blur', function () { clearTimeout(blurT); blurT = setTimeout(hideRes, 150); });

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
    $dlg.on('input', '.bgc-allmap-search', scheduleFilter);
    $dlg.on('click', '.bgc-allmap-near', function () { focusPoint(+$(this).attr('data-i')); });
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
  function refreshNear() { measureNear(); pickNear(); }

  /**
   * The half that costs something: a distance for every point, written onto every row, and the list
   * put in order of it. Runs when the ORIGIN moves or the points change - never on a filter change,
   * because switching a courier off in the legend does not move the customer and cannot change a
   * single one of these numbers.
   */
  function measureNear() {
    if (!$dlg) { return; }
    var $list = $dlg.find('.bgc-allmap-list');
    var on = !!origin && nearOn();
    $dlg.toggleClass('bgc-has-origin', on);
    dists = [];
    if (!on) {
      $list.find('.bgc-allmap-dist').remove();
      $dlg.find('.bgc-allmap-near').remove();
      $dlg.find('.bgc-allmap-chip .bgc-chip-d').remove();
      return;
    }

    points.forEach(function (p, i) {
      var d = distOf(p);
      dists[i] = d;
      // Scoped to this one row: the version that searched the whole list per point was the quadratic
      // half of what made a few hundred offices feel heavy.
      var $a = rowEls[i] ? $(rowEls[i]).find('.a') : $();
      $a.find('.bgc-allmap-dist').remove();
      if (d != null) { $a.append('<span class="bgc-allmap-dist">' + esc(fmtDist(d)) + '</span>'); }
    });

    // Nearest first. The rows keep their data-i, so only their ORDER changes - the array behind them
    // is untouched, and every index the markers and the popups carry stays valid. Moved through a
    // fragment so the list is detached, reordered and reinserted once instead of a few hundred times.
    var order = [];
    for (var i = 0; i < rowEls.length; i++) { if (rowEls[i]) { order.push(i); } }
    order.sort(function (a, b) {
      var da = dists[a], db = dists[b];
      if (da == null) { return db == null ? a - b : 1; }   // no coordinates: keep them together, at the end
      if (db == null) { return -1; }
      return da - db;
    });
    var frag = document.createDocumentFragment();
    order.forEach(function (i) { frag.appendChild(rowEls[i]); });
    if ($list[0]) { $list[0].appendChild(frag); }
  }

  /**
   * The half that must be instant: which point is the answer, given what is switched on right now.
   *
   * Reads the cached distances and the courier flags - no measuring, no walking the DOM, no touching a
   * pin - so it can run on the same tick as a legend click. That split is the whole reason switching a
   * courier off no longer waits: the sentence and the chips change with the click, while the sweep
   * over every row and every pin goes to the next frame.
   */
  function pickNear() {
    if (!$dlg || !origin || !nearOn()) { return; }
    var best = {}, overall = null, term = searchTerm();
    points.forEach(function (p, i) {
      var d = dists[i];
      // Exactly what applyFilter() shows, not a subset of it. Reading only the legend meant that after
      // typing a street the sentence still named the closest point in the whole town - a point whose
      // row and whose pin were both hidden by then, so pressing it opened a bubble over nothing.
      if (d == null || !p.available || !shown(p, term)) { return; }   // not on offer: cannot be "nearest"
      if (!best[p.courier] || d < best[p.courier].d) { best[p.courier] = { d: d, i: i }; }
      if (!overall || d < overall.d) { overall = { d: d, i: i, p: p }; }
    });

    // Each courier's own nearest, on its legend chip: on a map carrying four couriers the comparison
    // IS the answer, and the chips are already the place the eye goes to compare them.
    $dlg.find('.bgc-allmap-chip').each(function () {
      var cid = $(this).attr('data-c');
      $(this).find('.bgc-chip-d').remove();
      if (best[cid]) { $(this).append('<span class="bgc-chip-d">' + esc(fmtDist(best[cid].d)) + '</span>'); }
    });

    renderNearLine(overall);
  }

  /**
   * Put a particular point in front of the customer: the map, centred on it, its bubble open, its row
   * marked and scrolled to, and the pin itself given a moment of its own.
   *
   * This is the answer to "which office IS that?" - the question a distance raises and cannot answer.
   * Reported exactly that way: the line said Speedy, 410 m, and there was no way to tell which of two
   * hundred identical dots it meant.
   */
  function focusPoint(i) {
    var mk = markers[i], el = rowEls[i];
    if (!$dlg) { return; }
    var $list = $dlg.find('.bgc-allmap-list');
    if (el) {
      $list.find('.active').removeClass('active');
      $(el).addClass('active');
      // Contained on purpose: scrollIntoView() would be allowed to scroll the checkout behind the
      // dialog as well, and this only ever needs to move the sidebar. Measured against the list's own
      // box rather than by offsetTop, which is relative to the nearest POSITIONED ancestor - not
      // necessarily this list, and a wrong answer here scrolls to the wrong row.
      if ($list[0]) {
        var top = el.getBoundingClientRect().top - $list[0].getBoundingClientRect().top;
        $list[0].scrollTop = Math.max(0, $list[0].scrollTop + top - $list[0].clientHeight / 2);
      }
    }
    if (!mk || !map) { return; }
    // On a phone the list is covering the map, so this has to switch over before it centres anything.
    setMode('map');
    // The pane's height changes whenever the header does - the answer strip appearing is itself such a
    // change - and Leaflet centres against whatever it last measured. A stale size puts the point
    // somewhere other than the middle, and on a short pane that can be off the screen altogether.
    map.invalidateSize();
    // animate:false, and this is the whole bug it fixes. A setView that changes the zoom ANIMATES, and
    // openPopup() on the next line starts Leaflet's own auto-pan while that animation is still running -
    // so the pan is computed against an in-flight view and lands somewhere else, leaving the bubble at
    // the edge of a map showing a different part of town. It depends on how fast the device draws,
    // which is why it showed on a phone and never once under a headless desktop browser.
    map.setView(mk.getLatLng(), Math.max(map.getZoom(), 15), { animate: false });
    mk.openPopup();
    if (mk._icon) {
      var icon = mk._icon;
      icon.classList.remove('bgc-pin-flash');
      void icon.offsetWidth;          // restart the animation when the same pin is asked for twice
      icon.classList.add('bgc-pin-flash');
      // Taken off again when it finishes: a class that stays on says "this one" about a pin nobody
      // asked about any more, and it would still be there the next time this pin is the answer.
      $(icon).one('animationend', function () { icon.classList.remove('bgc-pin-flash'); });
    }
  }

  /** The sentence the whole feature exists for: how far, how much, and what it saves. */
  function renderNearLine(overall) {
    $dlg.find('.bgc-allmap-near').remove();
    if (!overall) { return; }
    var p = overall.p;
    var addr = (p.addressPrice || '');
    // The whole strip is the button, not a phrase inside it. "Speedy · locker · 410 m · ~1,52 €" is what
    // raises "which one, though?", so all of it - and the comparison beside it - answers that on a
    // press (see focusPoint()). One element rather than a nested one because of the phone: there the
    // strip wraps to two short lines, and those two lines together are the 44px of tappable height a
    // finger needs. Split in two, the target would be a 20px line.
    // Not a <div> with a <button> inside for the same reason - one focusable thing, one hit area.
    var html = '<button type="button" class="bgc-allmap-near" data-i="' + overall.i + '"'
      + ' title="' + esc(I.near_which || '') + '">'
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
    html += '</button>';
    $dlg.find('.bgc-allmap-legendcol').append(html);
  }

  /**
   * One filter, two conditions - which couriers are switched on, and what has been typed in the
   * search. Both apply to the list AND the map: a row the map does not show, or a pin the list does
   * not have, is the two halves telling the customer different things.
   */
  function searchTerm() {
    return $dlg ? String($dlg.find('.bgc-allmap-search').val() || '').toLowerCase() : '';
  }
  /**
   * Is this point on offer right now - both conditions, the legend AND the search.
   *
   * The term is passed in rather than read here: this is asked once per point, and reading the input
   * inside it would put a DOM lookup back on every one of a town's few hundred offices - the exact cost
   * the split between measureNear() and pickNear() exists to remove.
   */
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
    // The count rides on the List button because on a phone the list is behind the map: "List (3)"
    // after typing a street is the only way to know the search found anything without switching over.
    var n = 0, term = searchTerm();
    points.forEach(function (p, i) {
      var on = shown(p, term);
      if (on) { n++; }
      // Plain style writes on cached elements. Wrapping each row in jQuery and looking it up by
      // selector, per point, per keystroke, is most of what a few hundred offices used to cost.
      var el = rowEls[i];
      if (el) { el.style.display = on ? '' : 'none'; }
      var mk = markers[i];
      if (!mk) { return; }
      if (mk._icon) {
        // Hidden by style rather than taken out of the layer: removing a Leaflet marker tears its icon
        // and its handlers down and re-adding builds them again, which is the expensive half of a
        // legend click. The pin stays exactly where it is and simply stops being painted.
        mk._icon.style.display = on ? '' : 'none';
        if (!on && mk.isPopupOpen && mk.isPopupOpen()) { mk.closePopup(); }
      } else if (layer && on && !layer.hasLayer(mk)) {
        layer.addLayer(mk);   // never plotted yet (or previously removed): it needs a real icon first
      }
    });
    $dlg.find('.bgc-allmap-n').text('(' + n + ')');
    // "Nearest" has to follow the filter: a courier the customer just switched off must stop being
    // recommended, and the one behind it becomes the answer. Cheap - it re-reads cached distances.
    pickNear();
  }

  /**
   * One filter sweep on the next frame, however many times it was asked for.
   *
   * A legend click changes two things a person can see immediately (the chip, and the sentence above
   * the map) and one thing that takes a pass over every row and every pin. Running both inline meant
   * the browser could not paint the first until the second had finished, and switching a courier off
   * read as the dialog hanging. The visible half now happens on the click; this is the rest.
   */
  var filterQueued = false;
  function scheduleFilter() {
    if (filterQueued) { return; }
    filterQueued = true;
    nextFrame(function () { filterQueued = false; applyFilter(); });
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

  /**
   * A point's bubble: three rows, in the order a person reads them - WHOSE it is and HOW it is
   * collected (with the price, which belongs to that pair), then WHICH one it is, then WHERE it is.
   *
   * Built on open rather than on plot, so it can carry how far this particular point is from the
   * customer. That is the question a pin raises once one distance is on screen: the line above the map
   * names the closest, and every OTHER pin then has to be worth comparing against it.
   */
  function popupHtml(p, i) {
    var lat = Number(p.office.lat), lng = Number(p.office.lng);
    var d = nearOn() ? distOf(p) : null;
    return '<div class="bgc-allmap-pop">'
      + '<div class="bgc-allmap-pop-c">'
      + (p.logo ? '<img src="' + esc(p.logo) + '" alt="' + esc(p.courierLabel) + '">' : '')
      + '<span class="c">' + esc(p.courierLabel) + '</span>'
      + typeGlyph(p.type)
      + '<span class="t">' + esc(typeLabel(p.type)) + '</span>'
      + (p.available && p.price ? '<span class="bgc-allmap-pop-price">' + esc(priceLabel(p)) + '</span>' : '')
      + '</div>'
      + '<div class="bgc-allmap-pop-n">' + esc(p.office.name || '') + '</div>'
      + '<div class="bgc-allmap-pop-a"><span class="bgc-pop-addr">' + esc(p.office.address || '') + '</span>'
      // How far it is and how to get there, in one group: two small things about the same question,
      // and a group is what keeps them from being split across a wrap. The arrow used to be pushed
      // onto a line of its own by a long address with a distance beside it.
      + '<span class="bgc-pop-meta">'
      // Only once the customer has actually given a position - by pressing "find me" or dragging the
      // pin. Nothing is asked for on our own account to print this line.
      + (d != null
          ? '<span class="bgc-allmap-pop-d" title="' + esc(I.near_straight || '') + '">'
            + esc(fmtDist(d)) + ' ' + esc(I.near_from_you || '') + '</span>'
          : '')
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
      + '</span></div>'
      + (p.available
          ? '<button type="button" class="button bgc-allmap-pick" data-i="' + i + '">' + esc(I.allmap_choose || '') + '</button>'
          : '<em class="bgc-allmap-pop-na">' + esc(I.allmap_na || '') + '</em>')
      + '</div>';
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
    var bounds = [], chosenAt = null, rowHtml = [];
    points.forEach(function (p, i) {
      // Inline style, not a class: the colour is assigned at runtime (first-seen-courier order), so
      // there is no fixed set of classes to put in a stylesheet. This markup is built here in JS and
      // printed straight into the DOM, not passed through wp_kses, so the attribute is fine as-is.
      rowHtml.push('<li class="bgc-allmap-item' + (p.available ? '' : ' bgc-na')
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
      // Contents in popupHtml(), built when the bubble OPENS - the geometry it is framed by stays here,
      // because it is about this map's furniture rather than about the point.
      mk.bindPopup(function () { return popupHtml(p, i); }, {
          // Leaflet pans until the popup fits the CONTAINER, and knows nothing about the Map/List pill
          // floating over the bottom of it. Measured on a 390x844 screen without this: tapping the
          // lowest pin put the Choose button 49px UNDERNEATH the pill - painted, and impossible to
          // press. The pill occupies 51px (37 tall, 14 up from the bottom); 78 leaves ~25px of daylight
          // for a popup made taller by a two-line office name.
          // Wide enough for "Sameday \u00b7 \u0414\u043e \u0430\u0432\u0442\u043e\u043c\u0430\u0442 \u00b7 ~ 1,57 \u20ac" to stay on one line, and no wider: the
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
    // One write for the whole sidebar. Appending per point made the browser lay the list out again for
    // every office in the town; the elements are then kept BY POINT INDEX, so nothing after this - the
    // distances, the filter, the sort - ever has to look a row up by selector again.
    $list.html(rowHtml.join(''));
    rowEls = $list.children('.bgc-allmap-item').get();
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
      // The chip and the sentence above the map both change on this tick - neither needs more than
      // the flags and the cached distances. The sweep over every row and every pin is the slow half
      // and goes to the next frame, so the click is painted first.
      pickNear();
      scheduleFilter();
    });
    applyFilter();

    /*
     * The real prices, fetched per courier once the map is already on screen.
     *
     * The map opens on the cached reference so it appears at once; each courier's live figure for THIS
     * town replaces it as the answer lands. Quoting before the first paint made a new town wait on
     * several courier calls with nothing drawn - correct numbers on a map that had not arrived. Same
     * shape as the distances: show what is known, refine what arrives.
     *
     * Guarded on the render that started it: a customer who names another town mid-flight must not have
     * the previous town's prices land on the new map.
     */
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
              // Live now, so it is no longer an estimate and loses the "~".
              p.price = d.prices[p.type];
              p.estimated = false;
              var el = rowEls[i];
              if (el) { $(el).children('.p').text(priceLabel(p)); }
            }
            if (d.prices.address) { p.addressPrice = d.prices.address; }
            if (d.saves && d.saves[p.type]) { p.savesVsAddress = d.saves[p.type]; }
          });
          // An open bubble is REFRESHED, never closed. Closing it was my first attempt and it is worse
          // than a stale price: the customer opens a point, and a second later it shuts by itself with
          // no explanation. Its content is built by a function, so re-running update() re-reads the
          // point that just changed.
          markers.forEach(function (mk) {
            if (mk && mk.isPopupOpen && mk.isPopupOpen() && mk.getPopup()) { mk.getPopup().update(); }
          });
          pickNear();
        });
    });

    // The customer has already said where they are, and looking at another town does not move them:
    // put the pin back so the distances are simply there, rather than asking them to locate again.
    // Unless the shop has since switched the comparison off - a position remembered in this browser
    // from before that must not put a pin on the map now.
    if (origin && nearOn()) { placeMe([origin.lat, origin.lng]); }
    refreshNear();

    // A row and the answer strip mean the same thing - "show me this one" - so they go through the same
    // code now. Each used to centre the map by hand, which put the animation race above in two places
    // where it could only ever be fixed in one.
    $list.off('click').on('click', '.bgc-allmap-item:not(.bgc-na)', function () {
      focusPoint(+$(this).data('i'));
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

  /**
   * Is this point's pin painted right now?
   *
   * Not "is it in the layer" any more: a pin switched off in the legend STAYS in the layer and is
   * hidden by style, because taking a few hundred markers out and putting them back is what made a
   * legend click feel stuck. Anything asking "what can the customer see" has to ask this instead.
   */
  function pinShown(i) {
    var mk = markers[i];
    if (!mk || !layer || !layer.hasLayer(mk)) { return false; }
    return !(mk._icon && mk._icon.style.display === 'none');
  }

  function showMe() {
    if (!nearOn()) { return; }
    if (!navigator.geolocation) { dropPin(); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var here = [pos.coords.latitude, pos.coords.longitude];
      origin = { lat: here[0], lng: here[1] };
      save();
      if (!state.cityName || !map) { cityFromPosition(here); return; }
      placeMe(here);
      var visible = points.filter(function (p, i) { return pinShown(i); });
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
