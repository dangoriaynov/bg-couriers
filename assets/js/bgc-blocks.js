/**
 * The courier pickers, on WooCommerce's BLOCK checkout.
 *
 * The block checkout renders in React and fires none of the template hooks this plugin is built on, so
 * none of its markup ever reached the page: a customer could choose Speedy and had nowhere to say which
 * office. See includes/Checkout/class-bgcouriers-blocks.php for the server half.
 *
 * What this does NOT do is reimplement the pickers. They are a few hundred lines of behaviour - per-city
 * availability, the office preload, the street search, the interactive map, the per-courier memory - and
 * a second copy in React would drift from the first within a release. The server renders exactly the
 * markup the classic checkout gets; this finds it a place to live inside the block, and then hands over
 * to bgc-checkout.js by firing the event that checkout already re-initialises on.
 *
 * No user-facing text lives here.
 */
(function ($, wp, wc) {
  if (!wp || !wp.element || !wp.plugins || !wc || !wc.blocksCheckout) { return; }
  var el = wp.element.createElement;
  var useState = wp.element.useState, useEffect = wp.element.useEffect, useRef = wp.element.useRef;
  var Slot = wc.blocksCheckout.ExperimentalOrderShippingPackages;
  if (!Slot) { return; }

  /** The payment method the customer has picked in the block, '' before there is one. */
  function activePaymentMethod() {
    try {
      var store = wp.data && wp.data.select && wp.data.select('wc/store/payment');
      return store && store.getActivePaymentMethod ? String(store.getActivePaymentMethod() || '') : '';
    } catch (e) { return ''; }
  }

  /** The rate the customer has selected, read from the block's own radio inputs. */
  function selectedRate() {
    var checked = document.querySelector('.wc-block-components-radio-control__input:checked');
    if (checked && checked.value && checked.value.indexOf('bgcouriers_') === 0) { return checked.value; }
    // Fall back to scanning: the class names above are WooCommerce's and could be renamed under us,
    // while the VALUE of a shipping rate is a Store API contract and will not be.
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

    // Follow the customer's choice of courier. The block re-renders the shipping step on every cart
    // change, so this reads the DOM rather than holding its own copy - there is exactly one truth about
    // which rate is selected and it is the one the customer can see.
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

    // The markup is inserted, and only then does bgc-checkout.js hear about it. That order matters: its
    // handler bails immediately when there is no .bgc-fields on the page, so firing first does nothing
    // at all and the pickers stay inert.
    useEffect(function () {
      if (!data.html || !box.current) { return; }
      $(document.body).trigger('updated_checkout');
    }, [data.html]);

    // "Recalculate" on this checkout. bgc-checkout.js saves every delivery change to the session and
    // fires `update_checkout`, which the classic checkout answers by re-rendering the order review and
    // firing `updated_checkout` - the event that hides the pickers' loading state and re-initialises
    // them. The block answers neither: the rates stayed priced for the old selection and the pickers
    // stayed greyed out and dead from the first tab click (measured 2026-09-13). So here it is asked of
    // the Store API - the cart/extensions endpoint recalculates the cart, shipping rates included, and
    // the block re-renders from the answer - and `updated_checkout` is fired when it is done, whichever
    // way it ended: a refresh that failed is no reason to leave the customer with dead fields.
    useEffect(function () {
      var busy = false, again = false;
      // Is the block itself in the middle of telling the server something - a rate being selected, the
      // customer's address on its way? A recalculation asked for in that moment could be answered from
      // the state BEFORE that request landed, and the block adopts whatever answer comes last: the
      // courier the customer had just clicked would flip back to the one before it. Seen once on
      // 2026-09-14 (Express One chosen, its locker tab clicked at once, the block came back showing
      // Speedy) and not reproduced in eight tries after; the wait takes the interleaving away.
      function blockBusy() {
        try {
          var cart = wp.data.select('wc/store/cart');
          return !!(cart && ((cart.isShippingRateBeingSelected && cart.isShippingRateBeingSelected())
            || (cart.isCustomerDataUpdating && cart.isCustomerDataUpdating())
            || (cart.hasPendingItemsOperations && cart.hasPendingItemsOperations())));
        } catch (e) { return false; }
      }
      function whenIdle(cb, tries) {
        if (!blockBusy() || tries <= 0) { cb(); return; }
        setTimeout(function () { whenIdle(cb, tries - 1); }, 100);
      }
      function refresh() {
        // One at a time; a change made while one is running is answered by the next, not lost.
        if (busy) { again = true; return; }
        busy = true;
        whenIdle(function () {
          // The payment method travels with the request. Cash on delivery costs the courier a
          // collection fee that is in the price it quotes, and the classic checkout re-prices the
          // rates the moment the customer picks it; the block keeps its choice in the browser until
          // the order is placed, so the session - where the rates read it - never heard of it in
          // time and the row showed the prepaid price (measured 2026-09-14: 2,12 € shown, 2,41 €
          // charged).
          var data = { payment_method: activePaymentMethod() };
          var p = (wc.blocksCheckout.extensionCartUpdate && wc.blocksCheckout.extensionCartUpdate({ namespace: 'bg-couriers', data: data })) || Promise.resolve();
          var done = function () {
            busy = false;
            $(document.body).trigger('updated_checkout');
            if (again) { again = false; refresh(); }
          };
          Promise.resolve(p).then(done, done);
        }, 50); // five seconds at most; a block that never settles must not leave the pickers dead
      }
      $(document.body).on('update_checkout.bgcblocks', refresh);
      // ...and a change of payment method is a reason to re-price on its own.
      var lastPm = null;
      var unsubscribe = (wp.data && wp.data.subscribe) ? wp.data.subscribe(function () {
        var pm = activePaymentMethod();
        if (!pm || pm === lastPm) { return; }
        var first = lastPm === null;
        lastPm = pm;
        if (!first || pm === 'cod') { refresh(); } // the very first reading only matters when it is cash on delivery
      }) : null;
      return function () {
        $(document.body).off('update_checkout.bgcblocks', refresh);
        if (unsubscribe) { unsubscribe(); }
      };
    }, []);

    if (!data.html) { return null; }
    // dangerouslySetInnerHTML is the point, not a shortcut: React then treats these nodes as opaque and
    // leaves them alone, which is what lets jQuery own them the way it does on the classic checkout.
    return el('div', {
      className: 'bgc-blocks-fields',
      ref: box,
      dangerouslySetInnerHTML: { __html: data.html },
    });
  }

  // Nothing is ordered until what the customer typed has reached the session - the block's half of what
  // bgc-checkout.js does on the classic form's submit. Our fields are saved by a fire-and-forget POST
  // and the Store API validates the order against the SESSION; a house number typed and the button
  // pressed straight after was refused for leaving blank the very thing on the screen (measured
  // 2026-09-13: 70 ms between the two, "Моля, въведете улица и номер"). The block's checkout
  // validation waits for observers, so this one hands back a promise that settles when the flush does.
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
