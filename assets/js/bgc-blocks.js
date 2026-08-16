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

    if (!data.html) { return null; }
    // dangerouslySetInnerHTML is the point, not a shortcut: React then treats these nodes as opaque and
    // leaves them alone, which is what lets jQuery own them the way it does on the classic checkout.
    return el('div', {
      className: 'bgc-blocks-fields',
      ref: box,
      dangerouslySetInnerHTML: { __html: data.html },
    });
  }

  wp.plugins.registerPlugin('bgcouriers-checkout', {
    scope: 'woocommerce-checkout',
    render: function () { return el(Slot, null, el(Fields, null)); },
  });
})(window.jQuery, window.wp, window.wc);
