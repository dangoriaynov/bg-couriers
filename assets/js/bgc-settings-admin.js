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

/* Delivery-method sub-tabs: JS-switched panels, drag-to-reorder (saves via bgcouriers_save_order with the
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
                    $.post(ajaxurl, { action: 'bgcouriers_save_order', nonce: mn.data('nonce'), courier: mn.data('courier'), order: order });
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

/* Unsaved-changes indicator for the settings form.
   WooCommerce only un-disables its Save button on the first change and then latches a leave-page warning
   that never clears - undo every edit and it still nags, and the button gives no real signal that
   something is pending. This compares the form against a snapshot instead, so the state is the TRUTH:
   revert your edits and the highlight and the warning both go away. */
(function ($) {
    $(function () {
        var C = (window.BGCOURIERS_SET || {}).i18n || {};
        var $form = $('form#mainform');
        if (!$form.length || !$('.bgc-settings').length) { return; } // only our settings tab
        var $save = $form.find('.woocommerce-save-button, button[name="save"]').first();
        if (!$save.length) { return; }

        var base = $form.serialize();
        var dirty = false;
        var touched = false; // has the merchant actually interacted yet?
        var $pill = $('<span class="bgc-unsaved" aria-live="polite"></span>')
            .text(C.unsaved || 'Unsaved changes').insertBefore($save);

        function apply(now) {
            if (now === dirty) { return; }
            dirty = now;
            $save.toggleClass('bgc-dirty', dirty);
            if (dirty) { $save.removeAttr('disabled'); }
            $form.toggleClass('bgc-has-unsaved', dirty);
            // Same property WooCommerce uses, so there is one prompt, not two - and clearing it here is
            // what lets a reverted form stop warning.
            window.onbeforeunload = dirty ? function () { return C.leave || 'You have unsaved changes.'; } : null;
        }

        function check() {
            // Before the first real interaction, any change is the page setting itself up (select2
            // pre-selects, the pickup-office loader filling in its value) - re-baseline instead of
            // reporting unsaved changes on a form nobody has touched.
            if (!touched) { base = $form.serialize(); apply(false); return; }
            apply($form.serialize() !== base);
        }

        $(document).on('pointerdown keydown', function (e) {
            // select2 renders its dropdown outside the form, so watch the document, not just the form.
            if (e.type === 'keydown' && (e.key === 'Tab' || e.key === 'Shift')) { return; }
            touched = true;
        });
        $form.on('change input', check);
        // Colour pickers and select2 fire late / outside the normal flow.
        $(document).on('select2:select select2:unselect', check);
        $(document).on('click', '.iris-picker', function () { touched = true; setTimeout(check, 0); });

        // The save is AJAX: the page never reloads, so the baseline taken at load stays behind and every
        // saved value keeps comparing unequal - the pill sat there for good after the first save. Take a
        // fresh baseline from the form the moment the save reports success.
        // `touched` is deliberately NOT reset: the merchant has been editing, and the next change after a
        // save is a real change, not the page settling in.
        $(document).on('bgc:saved', function () { base = $form.serialize(); apply(false); });

        // Saving (or any submit) is leaving on purpose - never prompt for that.
        $form.on('submit', function () { window.onbeforeunload = null; });
        $form.find('.submit :input, button[name="save"]').on('click', function () { window.onbeforeunload = null; });
    });
})(jQuery);
