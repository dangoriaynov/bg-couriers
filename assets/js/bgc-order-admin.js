/* On-order delivery editor + shipment-panel actions (WooCommerce order edit screen). */
(function ($) {
  var C = window.BGCOURIERS_ED || {};
  var I = C.i18n || {};

  // admin-ajax answers a bare 0 (truthy) when the action is missing, so only a real array passes
  function bgcList(rows) { return Array.isArray(rows) ? rows : []; }

  // Hide WooCommerce's native shipping address editor: delivery is edited through our panel.
  (function () {
    var $panel = $('.bgc-order-panel');
    if (!$panel.length) { return; }
    var $col = $panel.closest('.order_data_column');
    if ($col.length) { $col.find('.edit_address').hide(); }
  })();

  // small UI helpers: toast + custom confirm dialog
  var $toast;
  function toast(msg) {
    if (!$toast) { $toast = $('<div class="bgc-toast"></div>').appendTo('body'); }
    $toast.stop(true, true).text(msg);
    var e = toast._e;
    if (e) { $toast.css({ left: Math.min(e.clientX + 12, window.innerWidth - 180) + 'px', top: (e.clientY + 14) + 'px', right: 'auto', bottom: 'auto' }); }
    else { $toast.css({ left: '50%', bottom: '32px', top: 'auto', right: 'auto', transform: 'translateX(-50%)' }); }
    $toast.addClass('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { $toast.removeClass('show'); }, 1400);
  }
  $(document).on('mousedown', '.bgc-wb-copy,.bgc-cancel,.bgc-regen', function (e) { toast._e = e; });

  function confirmDialog(opts) {
    var $ov = $('<div class="bgc-modal-ov"></div>');
    var $m = $(
      '<div class="bgc-modal" role="dialog" aria-modal="true">' +
      '<h3><span class="dashicons dashicons-warning"></span><span class="bgc-m-title"></span></h3>' +
      '<p class="bgc-m-body"></p>' +
      '<div class="bgc-modal-actions">' +
      '<button type="button" class="button bgc-m-no"></button>' +
      '<button type="button" class="button bgc-btn-danger bgc-m-yes"></button>' +
      '</div></div>'
    );
    $m.find('.bgc-m-title').text(opts.title || 'Are you sure?');
    $m.find('.bgc-m-body').text(opts.body || '');
    $m.find('.bgc-m-yes').text(opts.yes || 'Confirm');
    $m.find('.bgc-m-no').text(opts.no || 'Cancel');
    $ov.append($m).appendTo('body');
    // eslint-disable-next-line no-unused-expressions
    $ov[0].offsetWidth; // force reflow so the transition runs
    $ov.addClass('show');
    function close() { $ov.removeClass('show'); setTimeout(function () { $ov.remove(); }, 180); }
    $m.find('.bgc-m-no').on('click', close);
    $ov.on('click', function (e) { if (e.target === $ov[0]) { close(); } });
    $(document).on('keydown.bgcmodal', function (e) { if (e.key === 'Escape') { close(); $(document).off('keydown.bgcmodal'); } });
    $m.find('.bgc-m-yes').on('click', function () { close(); $(document).off('keydown.bgcmodal'); if (opts.onYes) { opts.onYes(); } });
    $m.find('.bgc-m-yes').focus();
  }

  $(document).on('click', '.bgc-order-panel .bgc-primary.bgc-unprinted', function () {
    $(this).removeClass('bgc-unprinted');
    if (I.print) { $(this).attr({ 'data-tip': I.print, 'aria-label': I.print }); }
  });

  $(document).on('click', '.bgc-wb-copy', function (e) {
    e.preventDefault();
    var $b = $(this), wb = String($b.data('wb') || '');
    var ok = function () { $b.addClass('copied'); toast(I.copied || 'Copied to clipboard'); setTimeout(function () { $b.removeClass('copied'); }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(wb).then(ok, function () {}); }
    else { var $t = $('<input>').val(wb).appendTo('body'); $t[0].select(); try { document.execCommand('copy'); ok(); } catch (x) {} $t.remove(); }
  });

  $(document).on('click', '.bgc-cancel', function (e) {
    e.preventDefault();
    var url = String($(this).data('cancel-url') || '');
    if (!url) { return; }
    confirmDialog({
      title: I.cancelTitle || 'Cancel this waybill?',
      body: I.cancelBody || 'This voids the shipment label with the courier.',
      yes: I.cancelYes || 'Yes, cancel it',
      no: I.cancelNo || 'Keep it',
      onYes: function () { window.location.href = url; }
    });
  });

  $(document).on('click', '.bgc-regen', function (e) {
    e.preventDefault();
    var url = String($(this).data('regen-url') || '');
    if (!url) { return; }
    confirmDialog({
      title: I.regenTitle || 'Re-issue this waybill?',
      body: I.regenBody || 'The current waybill is voided and a new one is issued.',
      yes: I.regenYes || 'Yes, re-issue it',
      no: I.cancelNo || 'Keep it',
      onYes: function () { window.location.href = url; }
    });
  });

  $(document).on('click', '.bgc-ed-toggle', function (e) {
    e.preventDefault();
    var $form = $('.bgc-ed-form');
    $form.toggle();
    if ($form.is(':visible')) { $form[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  });

  if (window.location.hash === '#bgc-edit') {
    var $auto = $('.bgc-ed-form');
    if ($auto.length) {
      $auto.show();
      setTimeout(function () { $auto[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 150);
    }
  }

  // the editor itself
  var $panel = $('.bgc-ed');
  if (!$panel.length) { return; }
  var LOCKED = $panel.find('.bgc-ed-form').hasClass('bgc-ed-locked');
  if (LOCKED) {
    $panel.on('click', 'button, .button', function (e) { e.preventDefault(); e.stopImmediatePropagation(); });
  }
  // select2 is skipped while locked: its own span ignores the disabled attribute
  function sel2($el, opts) { if (LOCKED) { return; } if ($.fn.selectWoo) { $el.selectWoo(opts); } else if ($.fn.select2) { $el.select2(opts); } }

  var $courier = $panel.find('.bgc-ed-courier'),
      $method  = $panel.find('.bgc-ed-method'),
      $city    = $panel.find('.bgc-ed-city'),
      $office  = $panel.find('.bgc-ed-office'),
      $street  = $panel.find('.bgc-ed-street');

  function courier() { return $courier.val(); }
  function country() { return C.country || 'BG'; }
  function resetOffice() { $office.val(null).trigger('change'); }
  function resetStreet() { $street.val(null).trigger('change'); setStreetPick(null); }
  function setStreetPick(d) {
    $panel.find('.bgc-ed-street-id').val((d && d.sid) || 0);
    $panel.find('.bgc-ed-street-type').val((d && d.stype) || '');
  }

  function fillMethods() {
    var c = courier(), cur = $method.val() || $method.data('current'), ms = (C.caps[c] || []);
    $method.empty();
    ms.forEach(function (m) { $method.append('<option value="' + m + '">' + (C.methodLabels[m] || m) + '</option>'); });
    if (ms.indexOf(cur) >= 0) { $method.val(cur); }
    updateMode();
  }
  function updateMode() {
    var m = $method.val();
    var $facts = $panel.find('.bgc-ed-shipfacts');
    $facts.toggle(($facts.attr('data-couriers') || '').split(',').indexOf(courier()) !== -1);
    $panel.find('.bgc-ed-city-row').show();
    $panel.find('.bgc-ed-office-row').toggle(m !== 'address');
    $panel.find('.bgc-ed-address').toggle(m === 'address');
  }

  sel2($city, { width: '100%', allowClear: true, placeholder: I.city, minimumInputLength: 0,
    ajax: { url: C.ajax, dataType: 'json', delay: 250,
      data: function (params) { return { action: 'bgcouriers_search_cities', courier: courier(), country: country(), term: params.term || '' }; },
      processResults: function (rows) { return { results: bgcList(rows).map(function (r) {
        return { id: r.city_id, text: r.name + (r.post_code ? ' (' + r.post_code + ')' : ''), post_code: r.post_code }; }) }; }
    }
  });
  $city.on('select2:select', function (e) { var d = e.params.data; if (d && d.post_code) { $panel.find('.bgc-ed-postcode').val(d.post_code); } });

  sel2($office, { width: '100%', allowClear: true, placeholder: I.office });
  var officeRows = [];
  function updateAvail() {
    // never touch Save while locked: this runs on every load and would re-enable it
    if (LOCKED) { return; }
    var m = $method.val(), city = parseInt($city.val() || 0, 10);
    var needs = (m === 'office' || m === 'automat');
    var none = needs && city > 0 && officeRows.length === 0;
    $panel.find('.bgc-ed-avail').text(none ? (m === 'automat' ? I.no_automat : I.no_office) : '').toggle(none);
    $panel.find('.bgc-ed-save').prop('disabled', none);
  }
  function loadOffices() {
    var c = courier(), city = $city.val() || 0, m = $method.val();
    if (LOCKED) { return; }
    if (!city || m === 'address') { officeRows = []; updateAvail(); return; }
    $.get(C.ajax, { action: 'bgcouriers_offices', courier: c, country: country(), city_id: city, type: m, all: 1 }, function (rows) {
      officeRows = bgcList(rows);
      var cur = $office.val();
      $office.empty().append('<option></option>');
      officeRows.forEach(function (o) {
        $office.append($('<option>').val(o.office_id || o.id).text((o.name || '') + (o.address ? ' - ' + o.address : '')));
      });
      if (cur && $office.find('option[value="' + cur + '"]').length) { $office.val(cur); }
      $office.trigger('change');
      updateAvail();
    }, 'json');
  }

  sel2($street, { width: '100%', allowClear: true, tags: true, placeholder: I.street, minimumInputLength: 0,
    ajax: { url: C.ajax, dataType: 'json', delay: 250,
      data: function (params) { return { action: 'bgcouriers_streets', courier: courier(), city_id: $city.val() || 0, term: params.term || '' }; },
      // id is the courier's label, not the bare name: "бул. X" and "ул. X" must stay two rows
      processResults: function (rows) { return { results: bgcList(rows).map(function (s) { return { id: s.label || s.name, text: s.label || s.name, sname: s.name, sid: s.id || 0, stype: s.type || '' }; }) }; }
    }
  });
  $street.on('select2:select', function (e) {
    var d = (e.params && e.params.data) || {};
    $street.find('option').not('[value=""]').remove();
    $street.append(new Option(d.text || '', d.sname || d.id || '', true, true)).trigger('change.select2');
    setStreetPick(d);
  });
  $street.on('select2:unselect', function () { setStreetPick(null); });

  // a city id belongs to one courier's nomenclature; look the same name up again for the new courier
  function cityName() {
    var t = ($city.find('option:selected').text() || '').trim();
    return t.replace(/\s*\(\d+\)\s*$/, '');
  }
  function reResolveCity() {
    var name = cityName();
    if (!name) { loadOffices(); return; }
    $.get(C.ajax, { action: 'bgcouriers_search_cities', courier: courier(), country: country(), term: name }, function (rows) {
      rows = bgcList(rows);
      var lc = name.toLowerCase();
      var m = null, i;
      for (i = 0; i < rows.length; i++) { if ((rows[i].name || '').toLowerCase() === lc) { m = rows[i]; break; } }
      if (!m) { m = rows[0]; }
      if (m) {
        var text = m.name + (m.post_code ? ' (' + m.post_code + ')' : '');
        $city.empty().append(new Option(text, m.city_id, true, true)).trigger('change.select2');
        if (m.post_code) { $panel.find('.bgc-ed-postcode').val(m.post_code); }
      } else {
        $city.val(null).trigger('change.select2');
      }
      loadOffices();
    }, 'json');
  }

  $city.on('change', function () { resetOffice(); resetStreet(); loadOffices(); });
  $courier.on('change', function () { fillMethods(); resetOffice(); resetStreet(); reResolveCity(); });
  $method.on('change', function () { updateMode(); resetOffice(); loadOffices(); });
  fillMethods();
  loadOffices();

  // Map picker (office/APS + address) - same UX as checkout, adapted to the editor fields
  var mapIconsSet = false, edMap = null;
  function escM(s) { return $('<i>').text(s == null ? '' : s).html(); }
  function setMapIcons() {
    if (mapIconsSet || !window.L) { return; }
    var base = C.leaflet_images || '';
    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({ iconRetinaUrl: base + 'marker-icon-2x.png', iconUrl: base + 'marker-icon.png', shadowUrl: base + 'marker-shadow.png' });
    mapIconsSet = true;
  }
  function closeMap() { $('#bgc-map-overlay').remove(); if (edMap) { edMap.remove(); edMap = null; } }
  function pickOffice(o) {
    var id = String(o.office_id || o.id), text = (o.name || '') + (o.address ? ' - ' + o.address : '');
    $office.append(new Option(text, id, true, true)).val(id).trigger('change');
  }
  function officesFor(cb) {
    var c = courier(), city = $city.val() || 0, m = $method.val();
    if (!city || m === 'address') { cb([]); return; }
    $.get(C.ajax, { action: 'bgcouriers_offices', courier: c, country: country(), city_id: city, type: m, all: 1 }, function (rows) { cb(bgcList(rows)); }, 'json');
  }
  function openMap() {
    if (!window.L) { return; }
    if (officeRows && officeRows.length) { renderOfficeMap(officeRows); } else { officesFor(renderOfficeMap); }
  }
  function renderOfficeMap(rows) {
      var pts = bgcList(rows).filter(function (o) { return Number(o.lat) !== 0 || Number(o.lng) !== 0; });
      var $ov = $('<div id="bgc-map-overlay" class="bgc-map-overlay"><div class="bgc-map-box bgc-map-box-wide">'
        + '<div class="bgc-map-head"><strong>' + escM(I.map_title || 'Map') + '</strong>'
        + '<button type="button" class="bgc-map-close" aria-label="' + escM(I.close || 'Close') + '">×</button></div>'
        + '<div class="bgc-map-body"><div class="bgc-map-side">'
        + '<div class="bgc-map-searchrow">'
        + '<input type="text" class="bgc-map-search" placeholder="' + escM(I.office_ph || '') + '">'
        + '<button type="button" class="bgc-map-locate" title="' + escM(I.map_locate || '')
        + '" aria-label="' + escM(I.map_locate || '') + '"></button>'
        + '</div>'
        + '<ul class="bgc-map-list"></ul></div>'
        + '<div class="bgc-map-canvas" id="bgc-map"></div></div>'
        + '<div class="bgc-map-actions"><span class="bgc-map-hint">'
        + (pts.length ? '' : escM(I.map_none || '')) + '</span></div></div></div>');
      $('body').append($ov);
      setMapIcons();
      edMap = L.map('bgc-map', { scrollWheelZoom: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(edMap);
      var bounds = [], markers = [], $list = $ov.find('.bgc-map-list');
      pts.forEach(function (o, i) {
        var lat = Number(o.lat), lng = Number(o.lng);
        var mk = L.marker([lat, lng]).addTo(edMap);
        mk.bindPopup('<div class="bgc-map-pop"><strong>' + escM(o.name || '') + '</strong><br>' + escM(o.address || '')
          + '<br><button type="button" class="button bgc-map-choose">' + escM(I.map_choose || 'Choose') + '</button></div>');
        mk.on('popupopen', function () { $('.bgc-map-choose').off('click').on('click', function () { pickOffice(o); closeMap(); }); });
        markers.push(mk); bounds.push([lat, lng]);
        $('<li class="bgc-map-item" data-i="' + i + '"><strong>' + escM(o.name || '') + '</strong><span>' + escM(o.address || '') + '</span></li>').appendTo($list);
      });
      $list.on('click', '.bgc-map-item', function () {
        var mk = markers[+$(this).data('i')]; if (!mk) { return; }
        $list.find('.active').removeClass('active'); $(this).addClass('active');
        edMap.setView(mk.getLatLng(), Math.max(edMap.getZoom(), 15)); mk.openPopup();
      });
      $ov.find('.bgc-map-search').on('input', function () {
        var t = this.value.toLowerCase();
        $list.find('.bgc-map-item').each(function () {
          var o = pts[+$(this).data('i')] || {};
          $(this).toggle(!t || (o.name || '').toLowerCase().indexOf(t) !== -1 || (o.address || '').toLowerCase().indexOf(t) !== -1);
        });
      });
      if (bounds.length) { edMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 }); } else { edMap.setView([42.73, 25.3], 7); }
      var meMarker = null;
      function showMe() {
        if (!navigator.geolocation) { return; }
        navigator.geolocation.getCurrentPosition(function (pos) {
          var here = [pos.coords.latitude, pos.coords.longitude];
          if (meMarker) { meMarker.setLatLng(here); } else { meMarker = L.circleMarker(here, { radius: 7, color: '#fff', weight: 3, fillColor: '#2271b1', fillOpacity: 1 }).addTo(edMap); }
          if (pts.length) {
            var near = pts.map(function (o) { var dlat = Number(o.lat) - here[0], dlng = (Number(o.lng) - here[1]) * 0.74; return { ll: [Number(o.lat), Number(o.lng)], d: dlat * dlat + dlng * dlng }; })
              .sort(function (a, b) { return a.d - b.d; }).slice(0, 6).map(function (x) { return x.ll; });
            edMap.fitBounds([here].concat(near), { padding: [45, 45], maxZoom: 15 });
          } else { edMap.setView(here, 14); }
        });
      }
      $ov.find('.bgc-map-locate').on('click', showMe);
      showMe();
      setTimeout(function () { if (edMap) { edMap.invalidateSize(); } }, 60);
  }
  var geoT;
  function reverseGeocode(lat, lng, cb) { clearTimeout(geoT); geoT = setTimeout(function () { $.get(C.ajax, { action: 'bgcouriers_geocode', lat: lat, lng: lng }, function (r) { cb(r || {}); }); }, 350); }
  function fillEditorAddress(geo) {
    function fields(pc) {
      if (pc) { $panel.find('.bgc-ed-postcode').val(pc); }
      if (geo.number) { $panel.find('.bgc-ed-streetno').val(geo.number); }
      if (!geo.street) { return; }
      $.get(C.ajax, { action: 'bgcouriers_streets', courier: courier(), country: country(), city_id: $city.val() || 0, term: geo.street, exact: 1 })
        .always(function (rows) {
          var hits = bgcList(rows);
          $street.find('option').not('[value=""]').remove();
          if (hits.length === 1) {
            $street.append(new Option(hits[0].label || hits[0].name, hits[0].name, true, true));
            setStreetPick({ sid: hits[0].id, stype: hits[0].type });
          } else {
            $street.append(new Option(geo.street, geo.street, true, true));
            setStreetPick(null);
          }
          $street.trigger('change');
        });
    }
    function pick(r) { $city.empty().append(new Option(r.name + (r.post_code ? ' (' + r.post_code + ')' : ''), r.city_id, true, true)).trigger('change.select2'); }
    function find(term, cb) { if (!term) { cb(null); return; } $.get(C.ajax, { action: 'bgcouriers_search_cities', courier: courier(), country: country(), term: term }, function (rows) { cb((rows && rows.length) ? rows[0] : null); }); }
    find(geo.city, function (r) {
      if (r) { pick(r); fields(geo.postcode || r.post_code || ''); return; }
      find(geo.postcode, function (r2) { if (r2) { pick(r2); fields(geo.postcode || r2.post_code || ''); } else { $city.val(null).trigger('change.select2'); fields(geo.postcode || ''); } });
    });
  }
  function openAddressMap() {
    if (!window.L) { return; }
    var $ov = $('<div id="bgc-map-overlay" class="bgc-map-overlay"><div class="bgc-map-box">'
      + '<div class="bgc-map-head"><strong>' + escM(I.addr_map_title || 'Map') + '</strong>'
      + '<button type="button" class="bgc-map-close" aria-label="' + escM(I.close || 'Close') + '">×</button></div>'
      + '<div class="bgc-map-canvas" id="bgc-map"></div>'
      + '<div class="bgc-map-actions"><button type="button" class="button bgc-map-locate">' + escM(I.map_locate || 'My location') + '</button>'
      + '<span class="bgc-map-hint bgc-addr-preview">' + escM(I.addr_map_hint || '') + '</span>'
      + '<button type="button" class="button button-primary bgc-addr-use" disabled>' + escM(I.addr_use || 'Use') + '</button></div></div></div>');
    $('body').append($ov);
    setMapIcons();
    edMap = L.map('bgc-map', { scrollWheelZoom: true }).setView([42.7, 25.3], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(edMap);
    var pin = null, current = {};
    function place(ll) {
      if (pin) { pin.setLatLng(ll); } else { pin = L.marker(ll, { draggable: true }).addTo(edMap); pin.on('dragend', function () { place(pin.getLatLng()); }); }
      $ov.find('.bgc-addr-preview').text('…'); $ov.find('.bgc-addr-use').prop('disabled', true);
      reverseGeocode(ll.lat, ll.lng, function (geo) {
        current = geo;
        var txt = [geo.street, geo.number].filter(Boolean).join(' ') + (geo.city ? ((geo.street ? ', ' : '') + geo.city) : '');
        $ov.find('.bgc-addr-preview').text(txt || (I.addr_none || ''));
        $ov.find('.bgc-addr-use').prop('disabled', !(geo.city || geo.street));
      });
    }
    edMap.on('click', function (e) { place(e.latlng); });
    function locate() { if (navigator.geolocation) { navigator.geolocation.getCurrentPosition(function (pos) { var here = L.latLng(pos.coords.latitude, pos.coords.longitude); edMap.setView(here, 15); place(here); }); } }
    $ov.find('.bgc-map-locate').on('click', locate);
    locate();
    $ov.find('.bgc-addr-use').on('click', function () { fillEditorAddress(current); closeMap(); });
    setTimeout(function () { if (edMap) { edMap.invalidateSize(); } }, 60);
  }
  $panel.on('click', '.bgc-ed-map', function (e) { e.preventDefault(); openMap(); });
  $panel.on('click', '.bgc-ed-addr-map', function (e) { e.preventDefault(); openAddressMap(); });
  $(document).on('click', '.bgc-map-close', function () { closeMap(); });
  $(document).on('click', '#bgc-map-overlay', function (e) { if (e.target === this) { closeMap(); } });
  $(document).on('keydown.bgcedmap', function (e) { if ((e.key === 'Escape' || e.keyCode === 27) && $('#bgc-map-overlay').length) { closeMap(); } });

  $panel.on('click', '.bgc-ed-save', function (e) {
    e.preventDefault();
    var $b = $(this), $msg = $panel.find('.bgc-ed-msg');
    $b.prop('disabled', true); $msg.text('').css('color', '#50575e').text(I.saving);
    var data = {
      action: 'bgcouriers_order_save_delivery', nonce: C.nonce, order_id: C.orderId,
      courier: courier(), method: $method.val(),
      site_id: $city.val() || 0, office_id: $office.val() || 0, post_code: $panel.find('.bgc-ed-postcode').val() || '',
      street_name: $street.val() || '', street_no: $panel.find('.bgc-ed-streetno').val() || '',
      street_id: $panel.find('.bgc-ed-street-id').val() || 0, street_type: $panel.find('.bgc-ed-street-type').val() || '',
      complex: $panel.find('.bgc-ed-complex').val() || '', block: $panel.find('.bgc-ed-block').val() || '',
      entrance: $panel.find('.bgc-ed-entrance').val() || '', floor: $panel.find('.bgc-ed-floor').val() || '',
      apartment: $panel.find('.bgc-ed-apartment').val() || '', address_note: $panel.find('.bgc-ed-note').val() || '',
      parcels: $panel.find('.bgc-ed-parcels').val() || 1,
      insurance: $panel.find('.bgc-ed-insurance').val() || 0
    };
    $.post(C.ajax, data).done(function (r) {
      if (r && r.success) { $msg.css('color', '#1a7f37').text((r.data && r.data.msg) || I.saved); setTimeout(function () { location.reload(); }, 800); }
      else { $msg.css('color', '#b32d2e').text((r && r.data && r.data.msg) || I.err); $b.prop('disabled', false); }
    }).fail(function () { $msg.css('color', '#b32d2e').text(I.err); $b.prop('disabled', false); });
  });
})(jQuery);

jQuery(function ($) {
    $(document).on('click', '.bgc-ship-refresh', function () {
        var b = $(this), id = b.data('id');
        if (b.hasClass('bgc-busy') || !id) { return; }
        b.addClass('bgc-busy').prop('disabled', true).attr('data-tip', BGCOURIERS_ED.i18n.trackRefreshing);
        $.post(BGCOURIERS_ED.ajax, {
            action: 'bgcouriers_poll_now',
            nonce: BGCOURIERS_ED.adminNonce,
            order_id: id
        }).done(function (r) {
            if (r && r.success) { window.location.reload(); return; }
            b.removeClass('bgc-busy').prop('disabled', false);
            window.alert((r && r.data && r.data.msg) || BGCOURIERS_ED.i18n.trackFailed);
        }).fail(function () {
            b.removeClass('bgc-busy').prop('disabled', false);
            window.alert(BGCOURIERS_ED.i18n.trackFailed);
        });
    });
});

  // a .bgc-off control is refused server-side; stop the click too, in the capture phase
  document.addEventListener('click', function (e) {
    var off = e.target && e.target.closest ? e.target.closest('.bgc-off') : null;
    if (off) { e.preventDefault(); e.stopPropagation(); }
  }, true);
