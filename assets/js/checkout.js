/* global jQuery, dexpress_checkout */
(function ($) {
    'use strict';

    if (typeof dexpress_checkout === 'undefined') {
        return;
    }

    var DEBOUNCE_MS = 300;
    var MIN_CHARS   = 2;

    // Block checkout detected server-side via has_block() — JS runs before React renders the DOM
    var IS_BLOCK = !!dexpress_checkout.is_block;

    // Build a field selector appropriate for the checkout type
    function fieldSel(section, field) {
        return IS_BLOCK
            ? '#' + section + '-' + field    // block:   #shipping-city, #shipping-address_1
            : '#' + section + '_' + field;   // classic: #shipping_city, #shipping_address_1
    }

    // ---------------------------------------------------------------------------
    // React-compatible value setter.
    //
    // Problem: jQuery .val() bypasses React's internal state tracker.
    // React re-renders the component on the next state change and restores
    // the previous value, wiping whatever we set — that is the "grad blokira" bug.
    //
    // Fix: use the native HTMLInputElement value setter and dispatch a synthetic
    // input event so React updates its own state to match what we wrote.
    // ---------------------------------------------------------------------------
    var _nativeInputSetter = Object.getOwnPropertyDescriptor(
        window.HTMLInputElement.prototype, 'value'
    );

    function setInputValue(selector, value) {
        var $el = $(selector);
        if (!$el.length) { return; }
        if (!IS_BLOCK) {
            $el.val(value);
            return;
        }
        var el = $el[0];
        if (_nativeInputSetter && _nativeInputSetter.set) {
            _nativeInputSetter.set.call(el, value);
        } else {
            el.value = value;
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // ---------------------------------------------------------------------------
    // Per-section autocomplete factory
    // ---------------------------------------------------------------------------
    function createSection(section) {
        var townSel     = fieldSel(section, 'city');
        var streetSel   = fieldSel(section, 'address_1');
        var postcodeSel = fieldSel(section, 'postcode');

        // Classic: PHP-rendered hidden inputs.
        // Block: registered additional fields hidden via CSS (dexpress/town-id → #*-dexpress-town-id)
        var townIdSel   = IS_BLOCK
            ? '#' + section + '-dexpress-town-id'
            : '#' + section + '_dexpress_town_id';
        var streetIdSel = IS_BLOCK
            ? '#' + section + '-dexpress-street-id'
            : '#' + section + '_dexpress_street_id';

        var townDropId   = 'dx-' + section + '-town-dd';
        var streetDropId = 'dx-' + section + '-street-dd';

        var selectedTownId   = 0;
        var selectedStreetId = 0;
        var townDebounce     = null;
        var streetDebounce   = null;

        // Prevent our own setInputValue calls from re-triggering event handlers.
        // The dispatch inside setInputValue is synchronous, so a boolean flag is safe.
        var isProgrammatic = false;

        // ---- Helpers ----

        function updateStreetState() {
            var $s = $(streetSel);
            if (!$s.length) { return; }
            var enabled = selectedTownId > 0;

            if (IS_BLOCK) {
                // Never set .prop('disabled') on React-controlled inputs — React resets it
                // on re-render. Use a CSS class on the wrapper for visual feedback.
                $s.closest('.wc-block-components-text-input')
                  .toggleClass('dx-field-disabled', !enabled);
            } else {
                $s.prop('disabled', !enabled);
            }

            $s.attr('placeholder', enabled
                ? dexpress_checkout.i18n.street_placeholder
                : dexpress_checkout.i18n.select_town_first);
        }

        function hideDropdown(id) {
            $('#' + id).hide().empty();
        }

        function positionDropdown($dd, $input) {
            if (!IS_BLOCK || !$input.length) { return; }
            var rect      = $input[0].getBoundingClientRect();
            var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            $dd.css({
                position: 'absolute',
                top     : (rect.bottom + scrollTop) + 'px',
                left    : rect.left + 'px',
                width   : rect.width + 'px',
            });
        }

        function showDropdown(id, items, onSelect) {
            var isTown = (id === townDropId);
            var $input = $(isTown ? townSel : streetSel);
            var $dd    = $('#' + id);
            $dd.empty();

            if (!items || !items.length) {
                $dd.append('<li class="dx-dd-empty">' + escHtml(dexpress_checkout.i18n.no_results) + '</li>');
            } else {
                $.each(items, function (i, item) {
                    var label = item.display_name || item.name;
                    var $li   = $('<li class="dx-dd-item"></li>').text(label);

                    $li.on('click.dexpress', function (e) {
                        // Stop click from reaching React's root event delegator;
                        // otherwise React may re-render and wipe values we are about to set.
                        e.stopPropagation();
                        onSelect(item);
                        $dd.hide().empty();
                    });

                    $dd.append($li);
                });
            }

            positionDropdown($dd, $input);
            $dd.show();
        }

        function fetchTowns(q) {
            $.ajax({
                url:    dexpress_checkout.ajax_url,
                method: 'GET',
                data:   { action: 'dexpress_search_towns', q: q, nonce: dexpress_checkout.nonce },
                success: function (res) {
                    if (!res.success) { return; }

                    showDropdown(townDropId, res.data, function (town) {
                        // Set all values under isProgrammatic so our input handlers
                        // ignore the resulting synthetic events.
                        isProgrammatic = true;
                        setInputValue(townSel, town.name);
                        setInputValue(townIdSel, String(town.id));
                        if (town.postal_code) {
                            setInputValue(postcodeSel, String(town.postal_code));
                        }
                        setInputValue(streetSel, '');
                        setInputValue(streetIdSel, '0');
                        isProgrammatic = false;

                        selectedTownId   = town.id;
                        selectedStreetId = 0;

                        hideDropdown(streetDropId);
                        updateStreetState();

                        // Classic only: move focus to street field.
                        // In block checkout, trigger('focus') causes React re-render that
                        // would restore the old city value before our React state update settles.
                        if (!IS_BLOCK) {
                            $(streetSel).trigger('focus');
                        }
                    });
                },
            });
        }

        function fetchStreets(q, townId) {
            $.ajax({
                url:    dexpress_checkout.ajax_url,
                method: 'GET',
                data:   { action: 'dexpress_search_streets', q: q, town_id: townId, nonce: dexpress_checkout.nonce },
                success: function (res) {
                    if (!res.success) { return; }

                    showDropdown(streetDropId, res.data, function (street) {
                        isProgrammatic = true;
                        setInputValue(streetSel, street.name);
                        setInputValue(streetIdSel, String(street.id));
                        isProgrammatic = false;

                        selectedStreetId = street.id;
                    });
                },
            });
        }

        function onTownInput() {
            if (isProgrammatic) { return; }
            var q = $(this).val();

            selectedTownId   = 0;
            selectedStreetId = 0;

            isProgrammatic = true;
            setInputValue(townIdSel, '0');
            setInputValue(streetSel, '');
            setInputValue(streetIdSel, '0');
            setInputValue(postcodeSel, '');
            isProgrammatic = false;

            updateStreetState();
            hideDropdown(streetDropId);
            clearTimeout(townDebounce);
            if (q.length < MIN_CHARS) { hideDropdown(townDropId); return; }
            townDebounce = setTimeout(function () { fetchTowns(q); }, DEBOUNCE_MS);
        }

        function onStreetInput() {
            if (isProgrammatic) { return; }
            var q = $(this).val();

            selectedStreetId = 0;
            isProgrammatic = true;
            setInputValue(streetIdSel, '0');
            isProgrammatic = false;

            clearTimeout(streetDebounce);
            if (q.length < MIN_CHARS || selectedTownId <= 0) { hideDropdown(streetDropId); return; }
            streetDebounce = setTimeout(function () { fetchStreets(q, selectedTownId); }, DEBOUNCE_MS);
        }

        function restoreState() {
            if (selectedTownId > 0)   { setInputValue(townIdSel, String(selectedTownId)); }
            if (selectedStreetId > 0) { setInputValue(streetIdSel, String(selectedStreetId)); }
            updateStreetState();
        }

        // ---- Classic init: wrap inputs, direct event listeners ----

        function initClassic() {
            var $town   = $(townSel);
            var $street = $(streetSel);
            if (!$town.length) { return; }
            if ($town.parent().hasClass('dexpress-ac-wrap')) { restoreState(); return; }

            $town.wrap('<div class="dexpress-ac-wrap"></div>');
            $street.wrap('<div class="dexpress-ac-wrap"></div>');
            $town.after('<ul class="dexpress-dropdown" id="' + townDropId + '"></ul>');
            $street.after('<ul class="dexpress-dropdown" id="' + streetDropId + '"></ul>');

            $town.off('input.dexpress').on('input.dexpress', onTownInput);
            $street.off('input.dexpress').on('input.dexpress', onStreetInput);

            updateStreetState();
            restoreState();
        }

        // ---- Block init: event delegation (survives React re-renders), body dropdowns ----

        function initBlock() {
            if (!$('#' + townDropId).length) {
                $('<ul class="dexpress-dropdown" id="' + townDropId + '"></ul>').appendTo('body');
                $('<ul class="dexpress-dropdown" id="' + streetDropId + '"></ul>').appendTo('body');
            }

            var ns = '.dexpress-' + section;
            $(document)
                .off('input' + ns + '-town', townSel)
                .on('input' + ns + '-town', townSel, onTownInput);
            $(document)
                .off('input' + ns + '-street', streetSel)
                .on('input' + ns + '-street', streetSel, onStreetInput);
        }

        return {
            init:         IS_BLOCK ? initBlock : initClassic,
            restoreState: restoreState,
        };
    }

    // ---------------------------------------------------------------------------
    // Autofill: force autocomplete="off" — Chrome restores it after React re-renders
    // so we override on every focusin as well.
    // ---------------------------------------------------------------------------
    var CHECKOUT_INPUT_SEL = '.woocommerce-checkout input:not([type=hidden]):not([type=checkbox]):not([type=radio]),'
                           + '.wc-block-checkout__form input:not([type=hidden]):not([type=checkbox]):not([type=radio])';

    function disableAutofill() {
        $('.woocommerce-checkout, .wc-block-checkout__form').attr('autocomplete', 'off');
        $(CHECKOUT_INPUT_SEL).attr('autocomplete', 'off');
    }

    $(document).on('focusin.dexpress-autofill', CHECKOUT_INPUT_SEL, function () {
        $(this).attr('autocomplete', 'off');
    });

    // ---------------------------------------------------------------------------
    // Phone: strip non-digit chars on input, show inline format hint on blur
    // ---------------------------------------------------------------------------
    var PHONE_SEL = '#billing_phone, #billing-phone, #shipping-phone';
    var PHONE_RE  = /^(\+381|0381|381|0)[1-9]\d{7,8}$/;

    function initPhone() {
        $(document)
            .off('input.dexpress-phone', PHONE_SEL)
            .on('input.dexpress-phone', PHONE_SEL, function () {
                var v = $(this).val();
                var c = v.replace(/[^\d+\s\-\/()]/g, '');
                if (v !== c) { $(this).val(c); }
            })
            .off('blur.dexpress-phone', PHONE_SEL)
            .on('blur.dexpress-phone', PHONE_SEL, function () {
                validatePhoneInline($(this));
            })
            .off('focus.dexpress-phone', PHONE_SEL)
            .on('focus.dexpress-phone', PHONE_SEL, function () {
                $(this).siblings('.dexpress-phone-hint').remove();
            });
    }

    function validatePhoneInline($input) {
        $input.siblings('.dexpress-phone-hint').remove();
        var $wrapper = $input.closest('.form-row, .wc-block-components-text-input');
        var val      = $.trim($input.val()).replace(/[\s\-\/()]/g, '');
        if (val === '') { $wrapper.removeClass('woocommerce-invalid'); return; }
        if (PHONE_RE.test(val)) {
            $wrapper.removeClass('woocommerce-invalid');
        } else {
            $('<span class="dexpress-phone-hint"></span>')
                .text(dexpress_checkout.i18n.phone_hint)
                .insertAfter($input);
            $wrapper.addClass('woocommerce-invalid');
        }
    }

    function escHtml(str) {
        return $('<div>').text(String(str)).html();
    }

    // ---------------------------------------------------------------------------
    // Bootstrap
    // ---------------------------------------------------------------------------
    var billing  = createSection('billing');
    var shipping = createSection('shipping');

    function boot() {
        billing.init();
        if (IS_BLOCK || $('#ship-to-different-address-checkbox').is(':checked')) {
            shipping.init();
        }
        initPhone();
        disableAutofill();
    }

    $(document).off('change.dexpress-ship', '#ship-to-different-address-checkbox')
               .on('change.dexpress-ship',  '#ship-to-different-address-checkbox', function () {
                   if ($(this).is(':checked')) { shipping.init(); }
               });

    $(document).off('click.dexpress-global').on('click.dexpress-global', function (e) {
        if (!$(e.target).closest('.dexpress-ac-wrap, .dexpress-dropdown').length) {
            $('.dexpress-dropdown').hide().empty();
        }
    });

    $(document).ready(boot);

    $(document.body).on('updated_checkout', function () {
        if (!IS_BLOCK) {
            billing.init();
            if ($('#ship-to-different-address-checkbox').is(':checked')) { shipping.init(); }
        }
        billing.restoreState();
        shipping.restoreState();
        disableAutofill();
    });

})(jQuery);
