(function ($) {
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function sel2($el, opts) { return ($.fn.selectWoo ? $el.selectWoo(opts) : $el.select2(opts)); }
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
  function officeLabel(m) { return m === 'automat' ? (BGC.i18n.automat_label || 'Automat') : (BGC.i18n.office_label || 'Office'); }

  // Delivery-type tabs ------------------------------------------------------
  function renderTabs($wrap) {
    var types = caps($wrap);
    if (!$wrap.attr('data-method')) { $wrap.attr('data-method', types[0]); }
    var sel = method($wrap);
    if ($wrap.find('.bgc-tab').length) { syncMethodUI($wrap); return; }
    var html = types.map(function (t) {
      return '<button type="button" class="bgc-tab' + (t === sel ? ' active' : '') + '" data-method="' + t + '">' + esc(BGC.i18n[t]) + '</button>';
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
  function applyAvail($wrap) {
    var city = $wrap.find('.bgc-city').val() || 0;
    if (!city) { $wrap.find('.bgc-tab').removeClass('bgc-tab-na').prop('disabled', false).attr('title', ''); return; }
    var key = courier($wrap) + ':' + city;
    if (availCache[key] === undefined) {
      $.get(BGC.ajax, { action: 'bgc_city_avail', courier: courier($wrap), city_id: city }, function (res) {
        availCache[key] = { office: !!(res && res.office), automat: !!(res && res.automat) };
        applyAvail($wrap);
      });
      return;
    }
    var av = availCache[key], firstOk = null;
    $wrap.find('.bgc-tab').each(function () {
      var $t = $(this), m = $t.data('method'), ok = methodOk(av, m);
      $t.toggleClass('bgc-tab-na', !ok).prop('disabled', !ok).attr('title', ok ? '' : (BGC.i18n.na_city || ''));
      if (ok && firstOk === null) { firstOk = m; }
    });
    if (!methodOk(av, method($wrap)) && firstOk) { setMethod($wrap, firstOk); } // active option unavailable -> switch
  }

  // City (searchable, server-limited) --------------------------------------
  function initCity($wrap) {
    var $city = $wrap.find('.bgc-city');
    if ($city.hasClass('select2-hidden-accessible')) { return; }
    sel2($city, {
      width: '100%', allowClear: true, placeholder: (BGC.i18n && BGC.i18n.city_ph) || '', minimumInputLength: 0,
      ajax: {
        url: BGC.ajax, dataType: 'json', delay: 250, transport: noAbortTransport,
        data: function (params) { return { action: 'bgc_search_cities', courier: courier($wrap), term: params.term || '' }; },
        processResults: function (rows) {
          var counts = {};
          rows.forEach(function (r) { counts[r.name] = (counts[r.name] || 0) + 1; });
          return { results: rows.map(function (r) {
            var text = r.name;
            if (counts[r.name] > 1) { text += ' — ' + (r.region || r.post_code || ''); }
            return { id: r.city_id, text: text, post_code: r.post_code };
          }) };
        }
      }
    });
    $city.on('select2:select', function (e) {
      var d = e.params && e.params.data; if (d && d.post_code) { $wrap.find('.bgc-postcode').val(d.post_code); }
      resetOffice($wrap); resetStreet($wrap); showLoader($wrap); pushSelection($wrap); preloadOffices($wrap);
    });
    // Clearing the city must re-run availability (re-enable the greyed options) + recalc.
    $city.on('select2:clear', function () {
      $wrap.find('.bgc-postcode').val('');
      resetOffice($wrap); resetStreet($wrap); showLoader($wrap); pushSelection($wrap); preloadOffices($wrap);
    });
  }

  // Office / automat — preloaded per city+method, cached client-side until refresh; search is then local.
  var officeCache = {}; // 'courier:city:type' -> all office rows for that city+type
  function preloadOffices($wrap) {
    var city = $wrap.find('.bgc-city').val() || 0, m = method($wrap);
    if (!city || m === 'address') { return; }
    var key = courier($wrap) + ':' + city + ':' + m;
    if (officeCache[key] !== undefined) { return; }
    $.get(BGC.ajax, { action: 'bgc_offices', courier: courier($wrap), city_id: city, type: m, all: 1 },
      function (rows) { officeCache[key] = rows || []; });
  }

  function initOffice($wrap) {
    var $office = $wrap.find('.bgc-office');
    if ($office.hasClass('select2-hidden-accessible')) { return; }
    var hasCity = !!$wrap.find('.bgc-city').val();
    $office.prop('disabled', !hasCity); // no office search until a city is chosen
    sel2($office, {
      width: '100%', allowClear: true, minimumInputLength: 0, placeholder: hasCity ? ((BGC.i18n && BGC.i18n.office_ph) || '') : ((BGC.i18n && BGC.i18n.office_need_city) || ''),
      ajax: {
        delay: 0,
        transport: function (params, success, failure) {
          var d = params.data, key = d.courier + ':' + d.city_id + ':' + d.type, term = (d.term || '').toLowerCase();
          function done(rows) {
            success(term ? rows.filter(function (o) {
              return ((o.name || '').toLowerCase().indexOf(term) !== -1) || (String(o.office_id).indexOf(term) !== -1) || ((o.address || '').toLowerCase().indexOf(term) !== -1);
            }) : rows);
          }
          if (officeCache[key] !== undefined) { done(officeCache[key]); return { abort: function () {} }; } // local
          var req = $.get(BGC.ajax, { action: 'bgc_offices', courier: d.courier, city_id: d.city_id, type: d.type, all: 1 });
          req.done(function (rows) { officeCache[key] = rows || []; done(officeCache[key]); });
          req.fail(function (x, status) { if (status !== 'abort') { failure(); } });
          return req;
        },
        data: function (params) {
          return { courier: courier($wrap), city_id: $wrap.find('.bgc-city').val() || 0, type: method($wrap), term: params.term || '' };
        },
        processResults: function (rows) {
          return { results: rows.map(function (o) { return { id: o.office_id, text: o.name + ' — ' + o.address }; }) };
        }
      }
    });
    $office.on('select2:select', function () { pushSelection($wrap); });
    $office.on('select2:clear', function () { showLoader($wrap); pushSelection($wrap); }); // clearing resets the saved office
  }

  // ── Office / APS map picker (Leaflet, bundled locally) ──────────────────────
  var mapIconsSet = false, bgcMap = null;
  function setMapIcons() {
    if (mapIconsSet || !window.L) { return; }
    var base = BGC.leaflet_images || '';
    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({ iconRetinaUrl: base + 'marker-icon-2x.png', iconUrl: base + 'marker-icon.png', shadowUrl: base + 'marker-shadow.png' });
    mapIconsSet = true;
  }
  function officesFor($wrap, cb) {
    var city = $wrap.find('.bgc-city').val() || 0, m = method($wrap);
    if (!city || m === 'address') { cb([]); return; }
    var key = courier($wrap) + ':' + city + ':' + m;
    if (officeCache[key] !== undefined) { cb(officeCache[key]); return; }
    $.get(BGC.ajax, { action: 'bgc_offices', courier: courier($wrap), city_id: city, type: m, all: 1 },
      function (rows) { officeCache[key] = rows || []; cb(officeCache[key]); });
  }
  function pickMapOffice($wrap, o) {
    var $office = $wrap.find('.bgc-office'), text = o.name + (o.address ? ' — ' + o.address : '');
    $office.append(new Option(text, o.office_id, true, true)).val(String(o.office_id)).trigger('change');
    pushSelection($wrap); // recalc + save the chosen office
  }
  function closeMap() { $('#bgc-map-overlay').remove(); if (bgcMap) { bgcMap.remove(); bgcMap = null; } }
  function openMap($wrap) {
    if (!window.L) { return; }
    officesFor($wrap, function (rows) {
      var i18n = BGC.i18n || {};
      var pts = (rows || []).filter(function (o) { return Number(o.lat) !== 0 || Number(o.lng) !== 0; });
      var $ov = $('<div id="bgc-map-overlay" class="bgc-map-overlay"><div class="bgc-map-box">'
        + '<div class="bgc-map-head"><strong>' + esc(i18n.map_title || 'Map') + '</strong>'
        + '<button type="button" class="bgc-map-close" aria-label="' + esc(i18n.close || 'Close') + '">×</button></div>'
        + '<div class="bgc-map-canvas" id="bgc-map"></div>'
        + '<div class="bgc-map-actions"><button type="button" class="button bgc-map-locate">' + esc(i18n.map_locate || 'My location') + '</button>'
        + '<span class="bgc-map-hint">' + (pts.length ? '' : esc(i18n.map_none || '')) + '</span></div></div></div>');
      $('body').append($ov);
      setMapIcons();
      bgcMap = L.map('bgc-map', { scrollWheelZoom: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(bgcMap);
      var bounds = [];
      pts.forEach(function (o) {
        var lat = Number(o.lat), lng = Number(o.lng);
        var mk = L.marker([lat, lng]).addTo(bgcMap);
        mk.bindPopup('<div class="bgc-map-pop"><strong>' + esc(o.name || '') + '</strong><br>' + esc(o.address || '')
          + '<br><button type="button" class="button bgc-map-choose">' + esc(i18n.map_choose || 'Choose') + '</button></div>');
        mk.on('popupopen', function () { $('.bgc-map-choose').off('click').on('click', function () { pickMapOffice($wrap, o); closeMap(); }); });
        bounds.push([lat, lng]);
      });
      if (bounds.length) { bgcMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 }); } else { bgcMap.setView([42.73, 25.3], 7); }
      $ov.find('.bgc-map-locate').on('click', function () {
        if (!navigator.geolocation) { return; }
        navigator.geolocation.getCurrentPosition(function (pos) {
          var here = [pos.coords.latitude, pos.coords.longitude];
          L.circleMarker(here, { radius: 7, color: '#2271b1', fillColor: '#2271b1', fillOpacity: 0.85 }).addTo(bgcMap);
          bgcMap.setView(here, 13);
        });
      });
      setTimeout(function () { if (bgcMap) { bgcMap.invalidateSize(); } }, 60); // the modal was just inserted
    });
  }
  $(document).on('click', '.bgc-map-btn', function (e) { e.preventDefault(); openMap($(this).closest('.bgc-fields')); });
  $(document).on('click', '.bgc-map-close', function () { closeMap(); });
  $(document).on('click', '#bgc-map-overlay', function (e) { if (e.target === this) { closeMap(); } });

  // Street (autocomplete via /location/street; tags:true keeps free-typed streets working).
  function initStreet($wrap) {
    var $street = $wrap.find('.bgc-street');
    if (!$street.length || $street[0].tagName !== 'SELECT' || $street.hasClass('select2-hidden-accessible')) { return; }
    sel2($street, {
      width: '100%', tags: true, allowClear: true, minimumInputLength: 2, placeholder: (BGC.i18n && BGC.i18n.street_ph) || '',
      ajax: {
        url: BGC.ajax, dataType: 'json', delay: 250, transport: noAbortTransport,
        data: function (params) { return { action: 'bgc_streets', courier: courier($wrap), city_id: $wrap.find('.bgc-city').val() || 0, term: params.term || '' }; },
        processResults: function (rows) { return { results: rows.map(function (s) { return { id: s.name, text: s.label || s.name }; }) }; }
      },
      createTag: function (params) { var t = (params.term || '').trim(); return t ? { id: t, text: t } : null; }
    });
    $street.on('select2:select', function () { saveSelection($wrap); });
  }

  // Save the selection ------------------------------------------------------
  function selectionData($wrap) {
    return {
      action: 'bgc_set_selection', nonce: BGC.nonce, courier: courier($wrap), method: method($wrap),
      site_id: $wrap.find('.bgc-city').val() || 0,
      office_id: $wrap.find('.bgc-office').val() || 0,
      post_code: $wrap.find('.bgc-postcode').val() || '',
      street_name: $wrap.find('.bgc-street').val() || '', street_no: $wrap.find('.bgc-street-no').val() || '',
      complex: $wrap.find('.bgc-complex').val() || '', block: $wrap.find('.bgc-block').val() || '',
      entrance: $wrap.find('.bgc-entrance').val() || '', floor: $wrap.find('.bgc-floor').val() || '',
      apartment: $wrap.find('.bgc-apartment').val() || '', address_note: $wrap.find('.bgc-note').val() || ''
    };
  }
  function pushSelection($wrap) { showLoader($wrap); $.post(BGC.ajax, selectionData($wrap), function () { $(document.body).trigger('update_checkout'); }); }
  function saveSelection($wrap) { $.post(BGC.ajax, selectionData($wrap)); } // save without recalc (address details don't change price)

  // BOX NOW locker picker — the official map widget (built-in GPS "nearest to me") ---------
  function boxnowUrl() {
    var c = BGC.boxnow || {}, p = [];
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
      + '<button type="button" class="bgc-boxnow-close" aria-label="' + esc(BGC.i18n && BGC.i18n.close) + '">×</button>'
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
    $.post(BGC.ajax, { action: 'bgc_set_selection', nonce: BGC.nonce, courier: 'boxnow', method: 'automat', office_id: d.boxnowLockerId, boxnow_name: name, boxnow_addr: addr },
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
  // The chosen bgc_<id> shipping method's courier id (each courier renders its own .bgc-fields).
  function chosenCourier() {
    var v = $('input[name^="shipping_method"]:checked').val()
         || $('input[name^="shipping_method"][type="hidden"]').val() || '';
    var m = String(v).match(/^bgc_([a-z0-9]+)/);
    return m ? m[1] : '';
  }

  // Dim the label + price of the shipping methods that aren't selected (you only pay for the chosen one).
  function dimRates() {
    $('input[name^="shipping_method"]').each(function () {
      $('label[for="' + this.id + '"]').toggleClass('bgc-rate-inactive', !this.checked);
    });
  }

  $(document.body).on('updated_checkout', function () {
    dimRates();
    if (!$('.bgc-fields').length) return;
    var chosen = chosenCourier();
    $('.bgc-fields').each(function () {
      var $wrap = $(this);
      var mine = $wrap.attr('data-courier') === chosen;
      $wrap.toggle(mine); // show only the chosen courier's fields (multiple couriers can share a zone)
      if (!mine) return;
      if ($wrap.hasClass('bgc-boxnow')) { hideLoader($wrap); return; } // locker picked via the map widget — nothing to init
      renderTabs($wrap); initCity($wrap); initOffice($wrap); initStreet($wrap); syncMethodUI($wrap); applyAvail($wrap); hideLoader($wrap);
    });
  });

  $(document.body).on('click', '.bgc-tab', function (e) {
    e.preventDefault();
    if ($(this).hasClass('bgc-tab-na') || this.disabled) { return; } // unavailable for this city
    setMethod($(this).closest('.bgc-fields'), $(this).data('method'));
  });

  $(document.body).on('change', 'input[name^="shipping_method"]', dimRates);

  $(document.body).on('input', '.bgc-postcode', function () {
    var $wrap = $(this).closest('.bgc-fields'), code = this.value.trim(); if (code.length < 4) { return; }
    $.get(BGC.ajax, { action: 'bgc_search_cities', courier: courier($wrap), term: code }, function (rows) {
      if (rows && rows.length === 1) {
        var $city = $wrap.find('.bgc-city'); var r = rows[0];
        $city.append(new Option(r.name, r.city_id, true, true)).trigger('change');
        resetOffice($wrap); resetStreet($wrap); showLoader($wrap); pushSelection($wrap); preloadOffices($wrap);
      }
    });
  });

  var addrT;
  $(document.body).on('input', '.bgc-address-rows input', function () {
    var $wrap = $(this).closest('.bgc-fields');
    clearTimeout(addrT); addrT = setTimeout(function () { saveSelection($wrap); }, 600);
  });

  // Emergency help: after repeated checkout failures, show a one-time help box with a phone link.
  (function () {
    var e = (window.BGC && BGC.emergency) || {};
    if (!e.phone) { return; }
    var THRESH = 2, SHOWN = 'bgc_emerg_shown', CNT = 'bgc_fail_count';
    $(document.body).on('checkout_error', function () {
      try { if (localStorage.getItem(SHOWN)) { return; } } catch (x) {}
      var n = (parseInt(sessionStorage.getItem(CNT) || '0', 10) || 0) + 1;
      try { sessionStorage.setItem(CNT, n); } catch (x) {}
      if (n >= THRESH) { showEmergency(); try { localStorage.setItem(SHOWN, '1'); } catch (x) {} }
    });
    function showEmergency() {
      if ($('#bgc-emergency').length) { return; }
      var msg = e.message || (BGC.i18n && BGC.i18n.emerg_default) || '';
      var tel = String(e.phone).replace(/[^\d+]/g, '');
      $('body').append(
        '<div id="bgc-emergency" class="bgc-emerg-overlay"><div class="bgc-emerg-box">' +
        '<button type="button" class="bgc-emerg-close" aria-label="' + esc(BGC.i18n && BGC.i18n.close) + '">×</button>' +
        '<p class="bgc-emerg-msg">' + esc(msg) + '</p>' +
        '<a class="bgc-emerg-tel" href="tel:' + esc(tel) + '">' + esc(e.phone) + '</a>' +
        '</div></div>'
      );
      $('#bgc-emergency').on('click', function (ev) { if (ev.target === this) { $(this).remove(); } });
      $('#bgc-emergency .bgc-emerg-close').on('click', function () { $('#bgc-emergency').remove(); });
    }
  })();
})(jQuery);
