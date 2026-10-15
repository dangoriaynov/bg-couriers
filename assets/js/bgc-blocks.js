/* Courier pickers on WooCommerce's block checkout: server-rendered markup placed in the block, then handed to bgc-checkout.js. */
(function ($, wp, wc) {
  if (!wp || !wp.element || !wp.plugins || !wc || !wc.blocksCheckout) { return; }
  var el = wp.element.createElement;
  var useState = wp.element.useState, useEffect = wp.element.useEffect, useRef = wp.element.useRef;
  var Slot = wc.blocksCheckout.ExperimentalOrderShippingPackages;
  if (!Slot) { return; }

  /* Push the block's customer data to the server so a cart update does not overwrite half-typed fields. */
  function persistCustomerData() {
    try {
      var sel = wp.data.select('wc/store/cart'), disp = wp.data.dispatch('wc/store/cart');
      if (!sel || !disp || !sel.getCustomerData || !disp.updateCustomerData) { return Promise.resolve(); }
      var cd = sel.getCustomerData();
      return Promise.resolve(disp.updateCustomerData({ billing_address: cd.billingAddress, shipping_address: cd.shippingAddress }, false)).catch(function () {});
    } catch (e) { return Promise.resolve(); }
  }

  function activePaymentMethod() {
    try {
      var store = wp.data && wp.data.select && wp.data.select('wc/store/payment');
      return store && store.getActivePaymentMethod ? String(store.getActivePaymentMethod() || '') : '';
    } catch (e) { return ''; }
  }

  function selectedRate() {
    var checked = document.querySelector('.wc-block-components-radio-control__input:checked');
    if (checked && checked.value && checked.value.indexOf('bgcouriers_') === 0) { return checked.value; }
    // Fallback scan: the class names are WooCommerce's, the rate VALUE is a Store API contract.
    var inputs = document.querySelectorAll('input[type="radio"]:checked');
    for (var i = 0; i < inputs.length; i++) {
      if (String(inputs[i].value).indexOf('bgcouriers_') === 0) { return inputs[i].value; }
    }
    return '';
  }

  function Fields() {
    var state = useState({ rate: '', html: '' });
    var data = state[0], setData = state[1];
    var box = useRef(null);
    var inflight = useRef('');

    // Read the selected rate from the DOM: the block re-renders the shipping step on every cart change.
    useEffect(function () {
      var poll = setInterval(function () {
        var rate = selectedRate();
        if (!rate || rate === inflight.current) { return; }
        inflight.current = rate;
        $.post(window.BGCOURIERS.ajax, { action: 'bgcouriers_blocks_fields', nonce: window.BGCOURIERS.nonce, rate: rate })
          .done(function (res) {
            if (res && res.success) { setData({ rate: rate, html: res.data.html || '' }); }
          });
      }, 400);
      return function () { clearInterval(poll); };
    }, []);

    // Fire updated_checkout only after the markup is in: the handler bails when there is no .bgc-fields.
    useEffect(function () {
      if (!data.html || !box.current) { return; }
      $(document.body).trigger('updated_checkout');
    }, [data.html]);

    // The block answers neither update_checkout nor updated_checkout, so re-price via the Store API and fire updated_checkout here.
    useEffect(function () {
      var busy = false, again = false;
      // Wait while a rate is being selected, or the recalculation answers from the older state and flips the courier back.
      function blockBusy() {
        try {
          var cart = wp.data.select('wc/store/cart');
          return !!(cart && cart.isShippingRateBeingSelected && cart.isShippingRateBeingSelected());
        } catch (e) { return false; }
      }
      function whenIdle(cb, tries) {
        if (!blockBusy() || tries <= 0) { cb(); return; }
        setTimeout(function () { whenIdle(cb, tries - 1); }, 100);
      }
      function refresh() {
        if (busy) { again = true; return; }
        busy = true;
        whenIdle(function () {
          // The payment method travels along: cash on delivery changes the price and the block keeps the choice client-side.
          var data = { payment_method: activePaymentMethod() };
          var done = function () {
            busy = false;
            $(document.body).trigger('updated_checkout');
            if (again) { again = false; refresh(); }
          };
          // Persist typed fields first, or the cart answer carries the empty server values and wipes them.
          persistCustomerData().then(function () {
            var p = (wc.blocksCheckout.extensionCartUpdate && wc.blocksCheckout.extensionCartUpdate({ namespace: 'bg-couriers', data: data })) || Promise.resolve();
            Promise.resolve(p).then(done, done);
          }, done);
        }, 50);
      }
      $(document.body).on('update_checkout.bgcblocks', refresh);
      var lastPm = null;
      var unsubscribe = (wp.data && wp.data.subscribe) ? wp.data.subscribe(function () {
        var pm = activePaymentMethod();
        if (!pm || pm === lastPm) { return; }
        var first = lastPm === null;
        lastPm = pm;
        if (!first || pm === 'cod') { refresh(); }
      }) : null;
      return function () {
        $(document.body).off('update_checkout.bgcblocks', refresh);
        if (unsubscribe) { unsubscribe(); }
      };
    }, []);

    if (!data.html) { return null; }
    // dangerouslySetInnerHTML on purpose: React leaves the nodes alone and jQuery owns them.
    return el('div', {
      className: 'bgc-blocks-fields',
      ref: box,
      dangerouslySetInnerHTML: { __html: data.html },
    });
  }

  // Validation waits for the selection flush: the Store API validates against the session, not the screen.
  var events = wc.blocksCheckoutEvents && wc.blocksCheckoutEvents.checkoutEvents;
  if (events && typeof events.onCheckoutValidation === 'function') {
    events.onCheckoutValidation(function () {
      return (window.BGCOURIERS && typeof window.BGCOURIERS.flushSelection === 'function')
        ? window.BGCOURIERS.flushSelection().then(function () { return true; }, function () { return true; })
        : true;
    }, 5);
  }

  wp.plugins.registerPlugin('bgcouriers-checkout', {
    scope: 'woocommerce-checkout',
    render: function () { return el(Slot, null, el(Fields, null)); },
  });
})(window.jQuery, window.wp, window.wc);
