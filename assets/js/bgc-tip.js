/* One hover hint for every [data-tip] this plugin prints - in wp-admin, and beside the courier rates.
 *
 * It used to be a CSS ::after on the hovered control, and a bubble that lives INSIDE the control it
 * describes cannot be made to behave: it is clipped by whatever the panel sits in, it inherits the
 * control's opacity (a blocked action is dimmed, so its explanation came out grey on grey), it inherits
 * the control's text-align (a <button> centres its text, an <a> does not, so the same hint read
 * differently on two buttons in one row), and its position had to be patched per control, per end of
 * row, per screen - four generations of edge rules that each missed the next case.
 *
 * One element on <body>, position:fixed, measured and clamped to the viewport, fixes the whole class:
 * nothing can clip it, nothing can dim it, and no control needs to know where it sits.
 *
 * The markup does not change - every hint is still a data-tip attribute with a matching aria-label, so
 * with this file missing the hints are still read out, just not drawn.
 */
(function () {
	'use strict';

	// OURS ONLY. WooCommerce puts data-tip on its own elements (order status, item meta) and shows them
	// with tipTip - an unscoped listener here would draw a second bubble on top of theirs.
	// The rate list is the shop's side of it: the (i) beside each courier that says who the delivery is
	// paid to. Its bubble was a CSS ::after on the dot, and on a phone the dot sits near the LEFT edge
	// of the screen while the bubble grew to the left - so its first words were cut off. This bubble is
	// clamped to the window, whatever the screen.
	var SCOPE = '.bgc-order-panel, .bgc-cell, ul#shipping_method';
	var GAP = 9;    // between the control and the bubble
	var EDGE = 8;   // smallest gap the bubble keeps from the window edge

	var box = null, body = null, arrow = null, host = null;
	// Opened by a tap rather than a hover: the pointer wandering off no longer closes it, the next tap
	// anywhere else does. Only where there is no hover to speak of - with a mouse, moving away still
	// closes a hint the way it always has.
	var pinned = false;
	var NO_HOVER = !!(window.matchMedia && window.matchMedia('(hover: none)').matches);

	function build() {
		if (box) { return; }
		box = document.createElement('div');
		box.className = 'bgc-tipbox';
		box.setAttribute('aria-hidden', 'true'); // the control's aria-label already carries the text
		body = document.createElement('span');
		body.className = 'bgc-tipbox-txt';
		arrow = document.createElement('span');
		arrow.className = 'bgc-tipbox-arr';
		box.appendChild(body);
		box.appendChild(arrow);
		document.body.appendChild(box);
	}

	function place() {
		var r = host.getBoundingClientRect();
		var w = box.offsetWidth, h = box.offsetHeight;
		var vw = document.documentElement.clientWidth;
		var vh = document.documentElement.clientHeight;

		// Centred on the control, then pushed back inside the window - a hint on a control at the edge
		// of a narrow sidebar would otherwise hang outside it, which is exactly where it got cut off.
		var left = Math.round(r.left + r.width / 2 - w / 2);
		if (left + w > vw - EDGE) { left = vw - EDGE - w; }
		if (left < EDGE) { left = EDGE; }

		// Above by default, below when the control sits too close to the top of the window.
		var top = Math.round(r.top - h - GAP);
		var below = top < EDGE;
		if (below) { top = Math.round(r.bottom + GAP); }
		if (below && top + h > vh - EDGE) { top = Math.max(EDGE, vh - EDGE - h); }
		box.classList.toggle('bgc-tip-below', below);

		box.style.left = left + 'px';
		box.style.top = top + 'px';

		// The arrow keeps pointing at the control even when the bubble slid sideways to stay in view.
		var ax = Math.round(r.left + r.width / 2 - left);
		arrow.style.left = Math.max(11, Math.min(w - 11, ax)) + 'px';
	}

	function show(el) {
		var tip = el.getAttribute('data-tip');
		if (!tip) { return hide(); }
		build();
		host = el;
		body.textContent = tip;
		box.classList.add('bgc-tip-on');
		place();
	}

	function hide() {
		host = null;
		pinned = false;
		if (box) { box.classList.remove('bgc-tip-on'); }
	}

	// One delegated listener. closest() resolves to the INNERMOST hint under the pointer, so a lock
	// inside the status row explains itself instead of both bubbles firing at once.
	function target(e) {
		var el = e.target;
		if (!el || typeof el.closest !== 'function') { return null; }
		el = el.closest('[data-tip]');
		return el && el.closest(SCOPE) ? el : null;
	}

	function over(e) {
		var el = target(e);
		if (!el) { if (!pinned) { hide(); } return; }
		if (el !== host) { pinned = false; show(el); }
	}

	// A control acts on its click, so the click is where its explanation stops. A hint that is nothing
	// BUT an explanation - the (i) beside a courier rate, an image role with no action of its own - is
	// the opposite case: on a touch screen the tap is the only way to it. So a click on one of those
	// shows it, and the next tap anywhere else hides it through the same listener.
	// preventDefault is what keeps it shown: the (i) sits inside the rate's <label>, and a label answers
	// a click by activating its radio - which took the focus off the (i), and the focusout hid the
	// bubble in the same instant the tap had opened it. Measured on an emulated iPhone: the tap fired
	// focusin on the (i), then focusout, then focusin on the radio. The (i) explains the rate; it is not
	// a second way of choosing it.
	function click(e) {
		var el = target(e);
		if (el && el.getAttribute('role') === 'img' && !el.closest('a, button')) { e.preventDefault(); show(el); pinned = NO_HOVER; return; }
		hide();
	}

	document.addEventListener('mouseover', over);
	document.addEventListener('focusin', over);
	document.addEventListener('mouseleave', hide);   // pointer left the document entirely
	document.addEventListener('focusout', hide);
	document.addEventListener('click', click);
	document.addEventListener('scroll', hide, true); // fixed bubble, moving control
	window.addEventListener('resize', hide);
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { hide(); }
	});
})();
