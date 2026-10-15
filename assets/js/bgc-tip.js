/* One hover bubble for every [data-tip] this plugin prints, in wp-admin and beside the courier rates. */
(function () {
	'use strict';

	// Ours only: WooCommerce shows its own data-tip elements with tipTip.
	var SCOPE = '.bgc-order-panel, .bgc-cell, ul#shipping_method';
	var GAP = 9;
	var EDGE = 8;

	var box = null, body = null, arrow = null, host = null;
	// Opened by a tap on a touch screen: the next tap closes it, not the pointer leaving.
	var pinned = false;
	var NO_HOVER = !!(window.matchMedia && window.matchMedia('(hover: none)').matches);

	function build() {
		if (box) { return; }
		box = document.createElement('div');
		box.className = 'bgc-tipbox';
		box.setAttribute('aria-hidden', 'true');
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

		var left = Math.round(r.left + r.width / 2 - w / 2);
		if (left + w > vw - EDGE) { left = vw - EDGE - w; }
		if (left < EDGE) { left = EDGE; }

		var top = Math.round(r.top - h - GAP);
		var below = top < EDGE;
		if (below) { top = Math.round(r.bottom + GAP); }
		if (below && top + h > vh - EDGE) { top = Math.max(EDGE, vh - EDGE - h); }
		box.classList.toggle('bgc-tip-below', below);

		box.style.left = left + 'px';
		box.style.top = top + 'px';

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

	// closest() resolves to the innermost hint, so nested hints do not both fire.
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

	// preventDefault: the (i) sits inside the rate label, whose radio would take focus and hide the bubble at once.
	function click(e) {
		var el = target(e);
		if (el && el.getAttribute('role') === 'img' && !el.closest('a, button')) { e.preventDefault(); show(el); pinned = NO_HOVER; return; }
		hide();
	}

	document.addEventListener('mouseover', over);
	document.addEventListener('focusin', over);
	document.addEventListener('mouseleave', hide);
	document.addEventListener('focusout', hide);
	document.addEventListener('click', click);
	document.addEventListener('scroll', hide, true);
	window.addEventListener('resize', hide);
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { hide(); }
	});
})();
