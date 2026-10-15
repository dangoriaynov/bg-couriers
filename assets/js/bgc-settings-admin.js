/* Static behaviours of the BG Couriers settings tab. */

/* Credential fields stay readonly until focused so the password manager does not autofill them. */
(function ($) {
    $(document).on('focus mousedown', 'input[data-bgc-nofill]', function () {
        var f = $(this);
        if (!f.prop('disabled')) { f.removeAttr('readonly'); }
    });
})(jQuery);

/* Turn each field description into a (i) on its label; descriptions with a link or <code> stay inline. */
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

/* Delivery-method sub-tabs: panel switching, drag-to-reorder, enable tinting, free-threshold greying. */
(function ($) {
    $(function () {
        var mn = $('.bgc-method-nav');
        function switchTo(t) {
            mn.find('.nav-tab').removeClass('nav-tab-active'); mn.find('[data-bgc-tab="' + t + '"]').addClass('nav-tab-active');
            $('.bgc-method-panel').hide().filter('[data-bgc-panel="' + t + '"]').show();
        }
        var dragged = false;
        // No sortable on a coarse pointer: jQuery UI never sees `stop` for a moved touch and the tabs stop switching.
        var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        if (mn.length && $.fn.sortable && !coarse) {
            mn.sortable({ items: '> .bgc-method-tab', distance: 6, tolerance: 'pointer', cursor: 'move', opacity: .85,
                start: function () { dragged = true; }, stop: function () { setTimeout(function () { dragged = false; }, 0); },
                update: function () {
                    var order = mn.children('.bgc-method-tab').map(function () { return $(this).data('bgc-tab'); }).get().join(',');
                    $.post(ajaxurl, { action: 'bgcouriers_save_order', nonce: mn.data('nonce'), courier: mn.data('courier'), order: order });
                }
            });
        }
        mn.on('click', '.nav-tab', function (e) { e.preventDefault(); if (dragged) { return; } switchTo($(this).data('bgc-tab')); });
        $(document).on('change', '.bgc-method-tab input[type=checkbox]', function () {
            var on = this.checked;
            $(this).closest('.bgc-method-tab').toggleClass('bgc-tab-on', on).toggleClass('bgc-tab-off', !on);
        });
        // readonly, not disabled, so the per-option values survive a save.
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

/* Unsaved-changes indicator: compares the form against a snapshot, so reverting edits clears it. */
(function ($) {
    $(function () {
        var C = (window.BGCOURIERS_SET || {}).i18n || {};
        var $form = $('form#mainform');
        if (!$form.length || !$('.bgc-settings').length) { return; }
        var $save = $form.find('.woocommerce-save-button, button[name="save"]').first();
        if (!$save.length) { return; }

        var base = $form.serialize();
        var dirty = false;
        var touched = false;
        var $pill = $('<span class="bgc-unsaved" aria-live="polite"></span>')
            .text(C.unsaved || 'Unsaved changes').insertBefore($save);

        /* WooCommerce sets window.onbeforeunload itself on any change, so our verdict is re-asserted after its handler. */
        function enforce() {
            setTimeout(function () { if (!dirty) { window.onbeforeunload = null; } }, 0);
        }

        function apply(now) {
            if (now === dirty) { return; }
            dirty = now;
            $save.toggleClass('bgc-dirty', dirty);
            if (dirty) { $save.removeAttr('disabled'); }
            $form.toggleClass('bgc-has-unsaved', dirty);
            window.onbeforeunload = dirty ? function () { return C.leave || 'You have unsaved changes.'; } : null;
        }

        function check() {
            // Before the first interaction, changes are the page setting itself up: re-baseline.
            if (!touched) { base = $form.serialize(); apply(false); return; }
            apply($form.serialize() !== base);
        }

        $(document).on('pointerdown keydown', function (e) {
            // select2 renders outside the form, so watch the document.
            if (e.type === 'keydown' && (e.key === 'Tab' || e.key === 'Shift')) { return; }
            touched = true;
        });
        /* A change with no originalEvent came from code, not the person: re-baseline instead of dirtying. */
        $form.on('change input', function (e) {
            if (!e.originalEvent) { base = $form.serialize(); apply(false); enforce(); return; }
            touched = true;
            check();
            enforce();
        });
        // select2's own events are the person, even though the underlying change is triggered in code.
        $(document).on('select2:select select2:unselect', function () { touched = true; check(); });
        $(document).on('click', '.iris-picker', function () { touched = true; setTimeout(check, 0); });

        // The save is AJAX, so take a fresh baseline when it succeeds.
        $(document).on('bgc:saved', function () { base = $form.serialize(); apply(false); });

        // Credential fields lock after load and disabled fields are not serialized: re-baseline after load unless really edited.
        var edited = false;
        $form.on('change input', function () { if (touched) { edited = true; } });
        $(window).on('load', function () {
            setTimeout(function () { if (!edited) { base = $form.serialize(); apply(false); } enforce(); }, 0);
        });

        $form.on('submit', function () { window.onbeforeunload = null; });
        $form.find('.submit :input, button[name="save"]').on('click', function () { window.onbeforeunload = null; });
    });
})(jQuery);
