/* global dexpressOnboarding, jQuery */
(function ($) {
    'use strict';

    var ob = dexpressOnboarding;

    // -----------------------------------------------------------------------
    // Logging helper — sends to server via dexpress_onboarding_log AJAX
    // -----------------------------------------------------------------------

    function obLog(level, message) {
        $.post(ob.ajaxUrl, {
            action:  'dexpress_onboarding_log',
            nonce:   ob.nonces.log,
            level:   level,
            message: message,
        });
    }

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    var state = {
        step: 1,
        total: 6,
        credentials: { username: '', password: '', client_id: '' },
        stepDone: { 1: true, 2: false, 3: false, 4: false, 5: false, 6: true },
    };

    // -----------------------------------------------------------------------
    // Progress & step indicator
    // -----------------------------------------------------------------------

    function setStep(n) {
        // Hide current panel, show target panel
        $('.dex-ob-panel').attr('hidden', true);
        $('#dex-ob-panel-' + n).removeAttr('hidden');

        // Update step dots
        $('.dex-ob-step-dot').each(function () {
            var s = parseInt($(this).data('step'), 10);
            $(this).removeClass('is-active is-done');
            if (s === n) {
                $(this).addClass('is-active');
            } else if (s < n) {
                $(this).addClass('is-done');
                $(this).find('.dex-ob-step-num').html(
                    '<span class="dashicons dashicons-yes" aria-hidden="true"></span>'
                );
            } else {
                $(this).find('.dex-ob-step-num').text(s);
            }
        });

        // Progress bar: step n fills (n-1)/(total-1) of the bar so step 1=0%, step 6=100%
        var pct = ((n - 1) / (state.total - 1)) * 100;
        $('#dex-ob-progress').css('width', pct.toFixed(1) + '%');
        $('#dex-ob-progress').closest('.dex-ob-progress-track').attr('aria-valuenow', Math.round(pct));

        state.step = n;

        // Scroll to top of wrap
        $('html, body').animate({ scrollTop: $('.dex-ob-wrap').offset().top - 32 }, 200);
    }

    // -----------------------------------------------------------------------
    // Result helpers
    // -----------------------------------------------------------------------

    function setInlineResult($el, msg, ok) {
        $el.text(msg)
            .removeClass('is-success is-error')
            .addClass(ok ? 'is-success' : 'is-error');
    }

    function setBlockResult($el, msg, ok) {
        $el.text(msg)
            .removeClass('is-success is-error')
            .addClass(ok ? 'is-success' : 'is-error');
    }

    function clearResult($el) {
        $el.text('').removeClass('is-success is-error');
    }

    // -----------------------------------------------------------------------
    // Navigation: Next / Back / Skip
    // -----------------------------------------------------------------------

    $(document).on('click', '.dex-ob-next', function () {
        var fromStep = parseInt($(this).data('step'), 10);
        if (fromStep < state.total) {
            setStep(fromStep + 1);
        }
    });

    $(document).on('click', '.dex-ob-back', function () {
        var fromStep = parseInt($(this).data('step'), 10);
        if (fromStep > 1) {
            setStep(fromStep - 1);
        }
    });

    $(document).on('click', '.dex-ob-skip', function () {
        var fromStep = parseInt($(this).data('step'), 10);
        if (fromStep < state.total) {
            setStep(fromStep + 1);
        }
    });

    // Initialize progress bar on load
    setStep(1);

    // -----------------------------------------------------------------------
    // Step 2 — API credentials: show/hide password
    // -----------------------------------------------------------------------

    $(document).on('click', '.dex-ob-toggle-pass', function () {
        var $input  = $('#' + $(this).data('target'));
        var $icon   = $(this).find('.dashicons');
        var $srText = $(this).find('.screen-reader-text');
        var isPass  = $input.attr('type') === 'password';

        $input.attr('type', isPass ? 'text' : 'password');
        $icon.toggleClass('dashicons-visibility', !isPass)
             .toggleClass('dashicons-hidden', isPass);
        $(this).attr('aria-pressed', isPass ? 'true' : 'false');
        $srText.text(isPass ? 'Sakrij lozinku' : 'Prikaži lozinku');
    });

    // -----------------------------------------------------------------------
    // Step 2 — API credentials: test connection
    // -----------------------------------------------------------------------

    $('#dex-ob-test-connection').on('click', function () {
        var $btn    = $(this);
        var $result = $('#dex-ob-connection-result');
        var $next   = $('#dex-ob-panel-2 .dex-ob-next');
        var username = $('#dex-ob-api-username').val().trim();
        var password = $('#dex-ob-api-password').val().trim();
        var clientId = $('#dex-ob-api-client-id').val().trim();

        clearResult($result);
        $btn.prop('disabled', true).text('Testiranje...');

        $.post(ob.ajaxUrl, {
            action:   'dexpress_test_connection',
            nonce:    ob.nonces.testConnection,
            username: username,
            password: password,
        })
        .done(function (response) {
            if (response.success) {
                setInlineResult($result, '✓ ' + response.data.message, true);
                // Store credentials in state for handleComplete
                state.credentials.username = username;
                state.credentials.password = password;
                state.credentials.client_id = clientId;
                $next.prop('disabled', false);
                obLog('info', 'Korak 2 — test konekcije uspešan, korisnik: ' + (username || '(sačuvan)'));
            } else {
                setInlineResult($result, '✗ ' + response.data.message, false);
                obLog('warning', 'Korak 2 — test konekcije neuspešan: ' + (response.data && response.data.message ? response.data.message : ''));
            }
        })
        .fail(function () {
            setInlineResult($result, '✗ Greška pri slanju zahteva.', false);
            obLog('warning', 'Korak 2 — AJAX greška pri testu konekcije');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Testiraj konekciju');
        });
    });

    // -----------------------------------------------------------------------
    // Step 3 — Catalog sync
    // -----------------------------------------------------------------------

    var SYNC_ALL_ORDER = ['towns', 'streets', 'municipalities', 'status_codes', 'dispensers', 'locations', 'shops', 'centres'];

    $('#dex-ob-sync').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#dex-ob-sync-spinner');
        var $result  = $('#dex-ob-sync-result');
        var $next    = $('#dex-ob-panel-3 .dex-ob-next');

        clearResult($result);
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        obLog('info', 'Korak 3 — sinhronizacija šifarnika pokrenuta');

        var i = 0;

        function step() {
            if (i >= SYNC_ALL_ORDER.length) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                setBlockResult($result, '✓ Svi šifarnici su uspešno sinhronizovani.', true);
                $next.prop('disabled', false);
                obLog('info', 'Korak 3 — sinhronizacija završena uspešno (' + SYNC_ALL_ORDER.length + ' tipova)');
                return;
            }

            var currentType = SYNC_ALL_ORDER[i];
            $.post(ob.ajaxUrl, {
                action: 'dexpress_manual_sync',
                nonce:  ob.nonces.manualSync,
                type:   currentType,
            })
            .done(function (response) {
                if (response.success) {
                    var d = response.data || {};
                    obLog('info', 'Korak 3 — sync ' + currentType + ': ' + (d.inserted || 0) + ' dodato, ' + (d.updated || 0) + ' ažurirano, ' + (d.deleted || 0) + ' obrisano');
                    i++;
                    step();
                } else {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    var msg = response.data && response.data.message ? response.data.message : 'Greška pri sinhronizaciji.';
                    setBlockResult($result, '✗ ' + msg, false);
                    obLog('warning', 'Korak 3 — sync ' + currentType + ' neuspešan: ' + msg);
                }
            })
            .fail(function () {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                setBlockResult($result, '✗ Greška pri slanju zahteva.', false);
                obLog('warning', 'Korak 3 — AJAX greška pri sync ' + currentType);
            });
        }

        step();
    });

    // -----------------------------------------------------------------------
    // Step 4 — Sender location: autocomplete helpers
    // -----------------------------------------------------------------------

    var PHONE_RE = /^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$/;

    function normalizePhone(raw) {
        raw = raw.replace(/[\s\-\(\)]/g, '').replace(/^\+/, '');
        if (/^00381/.test(raw)) { raw = '381' + raw.slice(5); }
        else if (/^00/.test(raw)) { raw = raw.slice(2); }
        else if (/^0/.test(raw)) { raw = '381' + raw.slice(1); }
        return raw;
    }

    function buildObAutocomplete(opts) {
        var timer  = null;
        var $input = $('#' + opts.inputId);
        var $box   = $('#' + opts.suggestionsId);
        var $spin  = opts.spinnerId ? $('#' + opts.spinnerId) : null;

        function spinOn()  { if ($spin) $spin.addClass('is-active'); }
        function spinOff() { if ($spin) $spin.removeClass('is-active'); }
        function close()   { $box.attr('hidden', true).empty(); }

        function renderItems(items) {
            spinOff();
            $box.empty();
            if (!items.length) {
                $('<div class="dex-ob-suggestion-item dex-ob-suggestion-empty">').text('Nema rezultata').appendTo($box);
            } else {
                $.each(items, function (i, item) {
                    $('<div class="dex-ob-suggestion-item" role="option" tabindex="-1">')
                        .text(item.name)
                        .data('item', item)
                        .appendTo($box);
                });
            }
            $box.removeAttr('hidden');
        }

        $(document).on('input', '#' + opts.inputId, function () {
            var q = $(this).val().trim();
            clearTimeout(timer);
            if (q.length < 2) { close(); spinOff(); return; }
            timer = setTimeout(function () {
                spinOn();
                opts.searchFn(q, renderItems);
            }, 280);
        });

        $(document).on('click', '#' + opts.suggestionsId + ' .dex-ob-suggestion-item', function () {
            var item = $(this).data('item');
            if (item) { opts.onSelect(item); close(); }
        });

        $(document).on('keydown', '#' + opts.inputId, function (e) {
            var $items = $box.find('.dex-ob-suggestion-item[tabindex]');
            if (!$items.length) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); $items.first().trigger('focus'); }
            else if (e.key === 'Escape') { close(); }
        });

        $(document).on('keydown', '#' + opts.suggestionsId + ' .dex-ob-suggestion-item', function (e) {
            var $item = $(this);
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var item = $item.data('item');
                if (item) { opts.onSelect(item); close(); }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault(); $item.next('.dex-ob-suggestion-item').trigger('focus');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var $prev = $item.prev('.dex-ob-suggestion-item');
                if ($prev.length) { $prev.trigger('focus'); } else { $input.trigger('focus'); }
            } else if (e.key === 'Escape') {
                close(); $input.trigger('focus');
            }
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.dex-ob-autocomplete-wrap').length) { close(); }
        });

        return { close: close };
    }

    // Town autocomplete
    var obStreetAC; // forward reference
    var obTownAC = buildObAutocomplete({
        inputId:       'dex-ob-loc-town-name',
        suggestionsId: 'dex-ob-town-suggestions',
        spinnerId:     'dex-ob-town-spinner',
        searchFn: function (q, cb) {
            $.get(ob.ajaxUrl, {
                action: 'dexpress_admin_search_towns',
                nonce:  ob.nonces.searchTowns,
                q:      q,
            })
            .done(function (r) { cb(r.success ? r.data : []); })
            .fail(function ()  { cb([]); });
        },
        onSelect: function (town) {
            $('#dex-ob-loc-town-id').val(town.id);
            $('#dex-ob-loc-town-name').val(town.name);
            // Reset street when town changes
            $('#dex-ob-loc-street-name').val('');
            $('#dex-ob-loc-street-id').val('');
            if (obStreetAC) { obStreetAC.close(); }
        },
    });

    // Clear town-id when user edits town text directly
    $(document).on('input', '#dex-ob-loc-town-name', function () {
        $('#dex-ob-loc-town-id').val('');
        $('#dex-ob-loc-street-name').val('');
        $('#dex-ob-loc-street-id').val('');
    });

    // Street autocomplete
    obStreetAC = buildObAutocomplete({
        inputId:       'dex-ob-loc-street-name',
        suggestionsId: 'dex-ob-street-suggestions',
        spinnerId:     'dex-ob-street-spinner',
        searchFn: function (q, cb) {
            var townId = $('#dex-ob-loc-town-id').val();
            if (!townId) { cb([]); return; }
            $.get(ob.ajaxUrl, {
                action:  'dexpress_admin_search_streets',
                nonce:   ob.nonces.searchStreets,
                town_id: townId,
                q:       q,
            })
            .done(function (r) { cb(r.success ? r.data : []); })
            .fail(function ()  { cb([]); });
        },
        onSelect: function (street) {
            $('#dex-ob-loc-street-id').val(street.id);
            $('#dex-ob-loc-street-name').val(street.name);
        },
    });

    // Phone normalize on blur
    $(document).on('blur', '#dex-ob-loc-contact-phone', function () {
        var raw  = $(this).val().trim();
        var $err = $('#dex-ob-phone-error');
        if (raw === '') { $err.text(''); return; }
        var norm = normalizePhone(raw);
        if (!PHONE_RE.test(norm)) {
            $err.text('Format nije ispravan. Primer: 381641234567');
        } else {
            $(this).val(norm);
            $err.text('');
        }
    });

    // Save location
    $('#dex-ob-save-location').on('click', function () {
        var $btn     = $(this);
        var $result  = $('#dex-ob-location-result');
        var $next    = $('#dex-ob-panel-4 .dex-ob-next');
        var name     = $('#dex-ob-loc-name').val().trim();
        var townId   = $('#dex-ob-loc-town-id').val();
        var streetId = $('#dex-ob-loc-street-id').val();
        var street   = $('#dex-ob-loc-street-name').val().trim();
        var number   = $('#dex-ob-loc-number').val().trim();
        var contact  = $('#dex-ob-loc-contact-name').val().trim();
        var phone    = $('#dex-ob-loc-contact-phone').val().trim();

        clearResult($result);

        if (!name)     { setBlockResult($result, 'Naziv lokacije je obavezan.', false); return; }
        if (!townId)   { setBlockResult($result, 'Izaberite grad iz liste.', false); return; }
        if (!streetId || !street) { setBlockResult($result, 'Izaberite ulicu iz liste.', false); return; }
        if (!number)   { setBlockResult($result, 'Kućni broj je obavezan.', false); return; }
        if (!contact)  { setBlockResult($result, 'Kontakt osoba je obavezna.', false); return; }
        if (!phone)    { setBlockResult($result, 'Telefon je obavezan.', false); return; }

        var phoneNorm = normalizePhone(phone);
        if (!PHONE_RE.test(phoneNorm)) {
            setBlockResult($result, 'Telefon nije u ispravnom formatu. Primer: 381641234567', false);
            return;
        }

        $btn.prop('disabled', true).text('Čuvanje...');

        $.post(ob.ajaxUrl, {
            action:        'dexpress_save_sender_location',
            nonce:         ob.nonces.saveSenderLocation,
            id:            0,
            name:          name,
            street_id:     streetId,
            street_name:   street,
            street_number: number,
            town_id:       townId,
            address_desc:  '',
            contact_name:  contact,
            contact_phone: phoneNorm,
            bank_account:  $('#dex-ob-loc-bank-account').val().trim(),
        })
        .done(function (response) {
            if (response.success) {
                setBlockResult($result, '✓ Lokacija je sačuvana.', true);
                $next.prop('disabled', false);
                obLog('info', 'Korak 4 — lokacija pošiljaoca sačuvana: ' + name);
            } else {
                var msg = response.data && response.data.message ? response.data.message : 'Greška pri čuvanju.';
                setBlockResult($result, '✗ ' + msg, false);
                obLog('warning', 'Korak 4 — čuvanje lokacije neuspešno: ' + msg);
            }
        })
        .fail(function () {
            setBlockResult($result, '✗ Greška pri slanju zahteva.', false);
            obLog('warning', 'Korak 4 — AJAX greška pri čuvanju lokacije');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Sačuvaj lokaciju');
        });
    });

    // -----------------------------------------------------------------------
    // Step 5 — Create shipping zone
    // -----------------------------------------------------------------------

    $('#dex-ob-create-zone').on('click', function () {
        var $btn    = $(this);
        var $result = $('#dex-ob-zone-result');
        var $next   = $('#dex-ob-panel-5 .dex-ob-next');

        clearResult($result);
        $btn.prop('disabled', true).text('Kreiranje...');

        $.post(ob.ajaxUrl, {
            action: 'dexpress_onboarding_create_zone',
            nonce:  ob.nonces.createZone,
        })
        .done(function (response) {
            if (response.success) {
                setBlockResult($result, '✓ ' + response.data.message, true);
                if (ob.shippingSettingsUrl) {
                    $result.append(
                        $('<span>').text(' — ').add(
                            $('<a>', { href: ob.shippingSettingsUrl, target: '_blank', rel: 'noopener' })
                                .text('Proveri u WooCommerce → Dostava')
                        )
                    );
                }
                $next.prop('disabled', false);
                obLog('info', 'Korak 5 — shipping zona kreirana uspešno');
            } else {
                var msg = response.data && response.data.message ? response.data.message : 'Greška pri kreiranju zone.';
                setBlockResult($result, '✗ ' + msg, false);
                obLog('warning', 'Korak 5 — kreiranje zone neuspešno: ' + msg);
            }
        })
        .fail(function (xhr) {
            var errMsg = 'HTTP ' + xhr.status + ' — proverite log za detalje.';
            setBlockResult($result, '✗ ' + errMsg, false);
            obLog('warning', 'Korak 5 — AJAX greška pri kreiranju zone: ' + errMsg);
        })
        .always(function () {
            $btn.prop('disabled', false);
            $btn.find('.dashicons').remove();
            $btn.prepend('<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span> ');
            $btn.contents().filter(function () { return this.nodeType === 3; }).last().replaceWith('Kreiraj zonu dostave');
        });
    });

    // -----------------------------------------------------------------------
    // Step 6 — Complete onboarding
    // -----------------------------------------------------------------------

    $('#dex-ob-complete').on('click', function () {
        var $btn    = $(this);
        var $result = $('#dex-ob-complete-result');

        clearResult($result);
        $btn.prop('disabled', true);

        $.post(ob.ajaxUrl, {
            action:    'dexpress_onboarding_complete',
            nonce:     ob.nonces.complete,
            username:  state.credentials.username,
            password:  state.credentials.password,
            client_id: state.credentials.client_id,
        })
        .done(function (response) {
            if (response.success) {
                setBlockResult($result, '✓ Podešavanje završeno! Preusmeravanje...', true);
                setTimeout(function () {
                    window.location.href = response.data.redirect || ob.dashboardUrl;
                }, 800);
            } else {
                var msg = response.data && response.data.message ? response.data.message : 'Greška.';
                setBlockResult($result, '✗ ' + msg, false);
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            setBlockResult($result, '✗ Greška pri slanju zahteva.', false);
            $btn.prop('disabled', false);
        });
    });

}(jQuery));
