/* Static behaviors of the BG Couriers settings tab. Dynamic per-render config (nonces, courier ids,
   current values) stays in small wp_add_inline_script snippets; everything here is self-contained. */

/* Turn every field description into a small (i) that sits inline right after the field label. Text /
   select / number fields print their description as a <span class="description"> in the value cell; a
   checkbox prints it as a raw text node inside its <label>. Pull that text out into a (i) on the label
   and drop the inline copy. Descriptions with a link or <code> (e.g. the webhook URL) are left inline. */
(function ($) {
    $(function () {
        $('#wpbody .bgc-settings table.form-table > tbody > tr').each(function () {
            var tr = $(this), th = tr.children('th').first(), td = tr.children('td').first();
            if (!th.length || !td.length || $.trim(th.text()) === '') { return; }
            var text = '', label = null;
            if (td.hasClass('forminp-checkbox')) {
                label = td.find('label').first(); if (!label.length) { return; }
                text = $.trim(label.text());
            } else {
                var d = td.find('.description').first();
                if (!d.length || d.find('code,a').length) { return; }
                text = $.trim(d.text());
            }
            if (!text) { return; }
            var tip = $('<span class="bgc-help" tabindex="0" role="img"></span>').attr('data-tip', text).attr('aria-label', text);
            var thl = th.find('label').first();
            (thl.length ? thl : th).append(tip);
            if (label) { label.contents().filter(function () { return this.nodeType === 3; }).remove(); }
            else { td.find('.description').first().remove(); }
        });
    });
})(jQuery);

/* Delivery-method sub-tabs: JS-switched panels, drag-to-reorder (saves via bgc_save_order with the
   nonce/courier carried on the nav's data- attributes), per-method enable toggle tinting, and the
   courier-level free threshold greying out the per-option ones. */
(function ($) {
    $(function () {
        var mn = $('.bgc-method-nav');
        function switchTo(t) {
            mn.find('.nav-tab').removeClass('nav-tab-active'); mn.find('[data-bgc-tab="' + t + '"]').addClass('nav-tab-active');
            $('.bgc-method-panel').hide().filter('[data-bgc-panel="' + t + '"]').show();
        }
        var dragged = false;
        if (mn.length && $.fn.sortable) {
            mn.sortable({ items: '> .bgc-method-tab', distance: 6, tolerance: 'pointer', cursor: 'move', opacity: .85,
                start: function () { dragged = true; }, stop: function () { setTimeout(function () { dragged = false; }, 0); },
                update: function () {
                    var order = mn.children('.bgc-method-tab').map(function () { return $(this).data('bgc-tab'); }).get().join(',');
                    $.post(ajaxurl, { action: 'bgc_save_order', nonce: mn.data('nonce'), courier: mn.data('courier'), order: order });
                }
            });
        }
        mn.on('click', '.nav-tab', function (e) { e.preventDefault(); if (dragged) { return; } switchTo($(this).data('bgc-tab')); }); // a drag isn't a tab switch
        $(document).on('change', '.bgc-method-tab input[type=checkbox]', function () {
            var on = this.checked;
            $(this).closest('.bgc-method-tab').toggleClass('bgc-tab-on', on).toggleClass('bgc-tab-off', !on);
        });
        // A courier-level free-shipping threshold overrides the per-option ones: while it holds a positive
        // value, grey the per-option fields out (readonly, not disabled, so their saved values survive a save).
        function bgcFreeSync() {
            var lvl = $('input[id$="_free_threshold"]').not('.bgc-method-free').first();
            if (!lvl.length) { return; }
            var v = parseFloat(String(lvl.val()).replace(',', '.'));
            var on = !isNaN(v) && v > 0;
            $('.bgc-method-free').each(function () {
                $(this).prop('readonly', on).closest('tr').css({ opacity: on ? 0.45 : 1 });
            });
        }
        $(document).on('input change', 'input[id$="_free_threshold"]', bgcFreeSync);
        bgcFreeSync();
    });
})(jQuery);
