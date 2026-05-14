/* global dexpressOnboarding, jQuery */
(function ($) {
    'use strict';

    var ob = dexpressOnboarding;

    ob.settingsSnapshot = $.extend(
        {
            clientIdInDb: false,
            shipmentPrefix: '',
            shipmentRangeStart: 0,
            shipmentRangeEnd: 0,
        },
        ob.settingsSnapshot || {},
    );

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
        step:  1,
        total: 6,
        connectionTested:     false,
        credentialsPersisted: false,
        clientIdSaved:        !!(ob.settingsSnapshot && ob.settingsSnapshot.clientIdInDb),
        stepDone: { 1: true, 2: false, 3: false, 4: false, 5: false, 6: true },
    };

    // If all three credentials are already saved in DB, treat Step 2 as pre-validated.
    (function () {
        var saved = ob.credentialsSaved || {};
        var snap = ob.settingsSnapshot || {};
        if (saved.username && saved.password && snap.clientIdInDb) {
            state.connectionTested     = true;
            state.credentialsPersisted = true;
            $('#dex-ob-panel-2 .dex-ob-next').prop('disabled', false);
        }
    }());

    function mergeSettingsSnapshotFromAjax(d) {
        d = d || {};
        ob.settingsSnapshot = ob.settingsSnapshot || {};
        if (typeof d.clientIdInDb === 'boolean') {
            ob.settingsSnapshot.clientIdInDb = d.clientIdInDb;
        } else if (typeof d.clientId !== 'undefined') {
            ob.settingsSnapshot.clientIdInDb = !!d.clientId;
        }
        if (typeof d.shipmentPrefix === 'string') {
            ob.settingsSnapshot.shipmentPrefix = d.shipmentPrefix;
            $('#dex-ob-shipment-prefix').val(d.shipmentPrefix);
        }
        if (typeof d.shipmentRangeStart === 'number') {
            ob.settingsSnapshot.shipmentRangeStart = d.shipmentRangeStart;
            $('#dex-ob-shipment-range-start').val(d.shipmentRangeStart > 0 ? String(d.shipmentRangeStart) : '');
        }
        if (typeof d.shipmentRangeEnd === 'number') {
            ob.settingsSnapshot.shipmentRangeEnd = d.shipmentRangeEnd;
            $('#dex-ob-shipment-range-end').val(d.shipmentRangeEnd > 0 ? String(d.shipmentRangeEnd) : '');
        }
    }

    function syncClientIdWarningOnStep6() {
        if (state.step !== 6) {
            return;
        }
        var snap = ob.settingsSnapshot || {};
        if (snap.clientIdInDb) {
            $('#dex-ob-clientid-warning').attr('hidden', true);
        } else {
            $('#dex-ob-clientid-warning').removeAttr('hidden');
        }
    }

    /** Korak 6 — vrati UI pre potvrde (ponovni ulazak ili greška pri završetku). */
    function resetFinishPanel() {
        var $pending = $('#dex-ob-finish-pending');
        var $outcome = $('#dex-ob-finish-outcome');
        var $links   = $('#dex-ob-finish-links');
        var $btn     = $('#dex-ob-complete');
        var $result  = $('#dex-ob-complete-result');
        var $saving  = $('#dex-ob-finish-saving');
        var $saved   = $('#dex-ob-finish-saved');
        if (!$pending.length) {
            return;
        }
        $pending.removeAttr('hidden');
        $outcome.attr('hidden', true);
        $links.attr('hidden', true);
        if ($saving.length) {
            $saving.attr('hidden', true);
        }
        if ($saved.length) {
            $saved.attr('hidden', true).removeClass('is-visible');
        }
        $result.attr('hidden', true);
        $btn.prop('disabled', false);
        clearResult($result);
    }

    // -----------------------------------------------------------------------
    // Koraci (paneli + stepper)
    // -----------------------------------------------------------------------

    function setStep(n) {
        // Hide current panel, show target panel
        $('.dex-ob-panel').attr('hidden', true);
        $('#dex-ob-panel-' + n).removeAttr('hidden');

        // Update step dots
        $('.dex-ob-step-dot').each(function () {
            var s = parseInt($(this).data('step'), 10);
            $(this).removeClass('is-active is-done dex-stepper__step--active dex-stepper__step--done');
            $(this).removeAttr('aria-current');
            if (s === n) {
                $(this).addClass('is-active dex-stepper__step--active');
                $(this).attr('aria-current', 'step');
            } else if (s < n) {
                $(this).addClass('is-done dex-stepper__step--done');
                $(this).find('.dex-ob-step-num').html(
                    '<span class="dashicons dashicons-yes" aria-hidden="true"></span>'
                );
            } else {
                $(this).find('.dex-ob-step-num').text(s);
            }
        });

        $('#dex-ob-step-badge').text(n + ' / ' + state.total);

        state.step = n;

        syncClientIdWarningOnStep6();

        if (n === 6) {
            resetFinishPanel();
        }

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

    function clearResult($el) {
        $el.text('').removeClass('is-success is-error');
    }

    // -----------------------------------------------------------------------
    // Navigation: Next / Back / Skip
    // -----------------------------------------------------------------------

    /**
     * Korak 2 — „Sačuvaj i nastavi“: provera polja + uspešan test, zatim čuvanje u iste opcije kao Podešavanja.
     */
    function handleStep2Next() {
        var $result = $('#dex-ob-connection-result');
        var saved   = ob.credentialsSaved || {};

        var hasUsername = $('#dex-ob-api-username').val().trim() !== '' || !!saved.username;
        var hasPassword = $('#dex-ob-api-password').val().trim() !== '' || !!saved.password;
        var hasClientId = $('#dex-ob-api-client-id').val().trim() !== '';

        var missing = [];
        if (!hasUsername) { missing.push('korisničko ime'); }
        if (!hasPassword) { missing.push('lozinka'); }
        if (!hasClientId) { missing.push('Client ID'); }

        if (missing.length) {
            setInlineResult($result, '✗ Popunite: ' + missing.join(', ') + '.', false);
            return;
        }

        if (!state.connectionTested) {
            setInlineResult($result, '✗ Prvo kliknite „Proveri povezivanje“ da sačuvamo nalog.', false);
            $('#dex-ob-test-connection').trigger('focus');
            return;
        }

        var $next = $('#dex-ob-panel-2 .dex-ob-next');
        var username = $('#dex-ob-api-username').val().trim();
        var password = $('#dex-ob-api-password').val().trim();
        var clientId = $('#dex-ob-api-client-id').val().trim();
        $next.prop('disabled', true);
        saveCredentials(username, password, clientId, $result, $next, { advanceToStep: 3 });
    }

    $(document).on('click', '.dex-ob-next', function () {
        var fromStep = parseInt($(this).data('step'), 10);
        if (fromStep === 2) {
            handleStep2Next();
            return;
        }
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
    // Step 2 — Field state management
    // -----------------------------------------------------------------------

    /**
     * Enables/disables „Sačuvaj i nastavi“ u koraku 2 kada su polja popunjena.
     */
    function syncStep2ButtonState() {
        var saved    = ob.credentialsSaved || {};
        var username = $('#dex-ob-api-username').val().trim();
        var password = $('#dex-ob-api-password').val().trim();
        var clientId = $('#dex-ob-api-client-id').val().trim();

        var hasUsername = username !== '' || !!saved.username;
        var hasPassword = password !== '' || !!saved.password;
        var hasClientId = clientId !== '';

        $('#dex-ob-panel-2 .dex-ob-next').prop('disabled', !(hasUsername && hasPassword && hasClientId));
    }

    // Typing a new password means the cached test result is stale — require re-test.
    $(document).on('input', '#dex-ob-api-password', function () {
        if ($(this).val().trim() !== '') {
            state.connectionTested     = false;
            state.credentialsPersisted = false;
        }
        syncStep2ButtonState();
    });

    // Any username edit invalidates the test (different credentials now).
    $(document).on('input', '#dex-ob-api-username', function () {
        state.connectionTested     = false;
        state.credentialsPersisted = false;
        syncStep2ButtonState();
    });

    // Shipment code fields: always persist on „Sačuvaj i nastavi“ — mark as stale until saved again.
    $(document).on('input change', '#dex-ob-shipment-prefix, #dex-ob-shipment-range-start, #dex-ob-shipment-range-end', function () {
        if ($(this).attr('id') === 'dex-ob-shipment-prefix') {
            var clean = $(this).val().replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 2);
            if ($(this).val() !== clean) {
                $(this).val(clean);
            }
        }
        state.credentialsPersisted = false;
    });

    // Client ID change only affects persistence, not the connection test itself.
    $(document).on('input', '#dex-ob-api-client-id', function () {
        state.credentialsPersisted = false;
        syncStep2ButtonState();
    });

    // -----------------------------------------------------------------------
    // Step 2 — Password: reveal saved-password input on request
    // -----------------------------------------------------------------------

    $(document).on('click', '#dex-ob-pw-change', function () {
        $('#dex-ob-pw-saved').attr('hidden', true);
        $('#dex-ob-pw-input-wrap').removeAttr('hidden');
        $('#dex-ob-api-password').trigger('focus');
    });

    // -----------------------------------------------------------------------
    // Step 2 — API credentials: show/hide password toggle
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

    function saveCredentials(username, password, clientId, $result, $next, opts) {
        opts = opts || {};
        var advanceToStep = opts.advanceToStep || 0;

        var prefix     = $('#dex-ob-shipment-prefix').val().trim().toUpperCase();
        var rsRaw      = $('#dex-ob-shipment-range-start').val();
        var reRaw      = $('#dex-ob-shipment-range-end').val();
        var rangeStart = (rsRaw === '' || typeof rsRaw === 'undefined') ? 0 : parseInt(rsRaw, 10);
        var rangeEnd   = (reRaw === '' || typeof reRaw === 'undefined') ? 0 : parseInt(reRaw, 10);
        if (isNaN(rangeStart)) {
            rangeStart = 0;
        }
        if (isNaN(rangeEnd)) {
            rangeEnd = 0;
        }

        setInlineResult($result, advanceToStep ? 'Čuvanje u podešavanja…' : 'Čuvanje kredencijala...', true);

        $.post(ob.ajaxUrl, {
            action:               'dexpress_onboarding_save_credentials',
            nonce:                ob.nonces.saveCredentials,
            username:             username,
            password:             password,
            client_id:            clientId,
            shipment_prefix:      prefix,
            shipment_range_start: rangeStart,
            shipment_range_end:   rangeEnd,
        })
        .done(function (response) {
            if (response.success) {
                var okMsg = advanceToStep
                    ? '✓ Podaci su sačuvani u podešavanjima.'
                    : '✓ Povezivanje je u redu i podaci su sačuvani u podešavanjima.';
                setInlineResult($result, okMsg, true);
                state.connectionTested     = true;
                state.credentialsPersisted = true;
                mergeSettingsSnapshotFromAjax(response.data || {});
                state.clientIdSaved = !!(ob.settingsSnapshot && ob.settingsSnapshot.clientIdInDb);
                if (!ob.credentialsSaved) {
                    ob.credentialsSaved = {};
                }
                ob.credentialsSaved.clientId = state.clientIdSaved;
                $next.prop('disabled', false);
                if (advanceToStep) {
                    setStep(advanceToStep);
                }
                syncClientIdWarningOnStep6();
                obLog('info', 'Korak 2 — kredencijali sačuvani u bazu, clientIdInDb: ' + (state.clientIdSaved ? 'da' : 'ne'));
            } else {
                var msg = response.data && response.data.message ? response.data.message : 'Greška pri čuvanju.';
                setInlineResult($result, '✗ Podaci nisu sačuvani. ' + msg, false);
                state.credentialsPersisted = false;
                if (advanceToStep) {
                    syncStep2ButtonState();
                }
                obLog('warning', 'Korak 2 — čuvanje kredencijala neuspešno: ' + msg);
            }
        })
        .fail(function () {
            setInlineResult($result, '✗ Nije moguće sačuvati. Proverite lozinku i pokušajte ponovo.', false);
            state.credentialsPersisted = false;
            if (advanceToStep) {
                syncStep2ButtonState();
            }
            obLog('warning', 'Korak 2 — AJAX greška pri čuvanju kredencijala');
        });
    }

    $('#dex-ob-test-connection').on('click', function () {
        var $btn     = $(this);
        var $result  = $('#dex-ob-connection-result');
        var $next    = $('#dex-ob-panel-2 .dex-ob-next');
        var username = $('#dex-ob-api-username').val().trim();
        var password = $('#dex-ob-api-password').val().trim();
        var clientId = $('#dex-ob-api-client-id').val().trim();

        clearResult($result);
        $btn.prop('disabled', true).text('Provera…');

        $.post(ob.ajaxUrl, {
            action:   'dexpress_test_connection',
            nonce:    ob.nonces.testConnection,
            username: username,
            password: password,
        })
        .done(function (response) {
            if (response.success) {
                state.connectionTested = true;
                obLog('info', 'Korak 2 — test konekcije uspešan, korisnik: ' + (username || '(sačuvan)'));
                // Test passed — immediately persist to DB so Step 3 sync can use them.
                saveCredentials(username, password, clientId, $result, $next);
            } else {
                var errMsg = response.data && response.data.message ? response.data.message : 'Neuspešna konekcija.';
                setInlineResult($result, '✗ ' + errMsg, false);
                obLog('warning', 'Korak 2 — test konekcije neuspešan: ' + errMsg);
            }
        })
        .fail(function () {
            setInlineResult($result, '✗ Greška pri slanju zahteva.', false);
            obLog('warning', 'Korak 2 — AJAX greška pri testu konekcije');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Proveri povezivanje');
        });
    });

    // -----------------------------------------------------------------------
    // Step 3 — Catalog sync
    // -----------------------------------------------------------------------

    var SYNC_ALL_ORDER = ob.syncSequence || [];

    var SYNC_CATALOG_LABELS = {
        municipalities: 'Opštine',
        centres:        'Centri isporuke',
        towns:          'Gradovi',
        streets:        'Ulice',
        status_codes:   'Statusi isporuke',
        dispensers:     'Paketomati',
        locations:      'Mesta preuzimanja',
        shops:          'Prodavnice / paket shop',
    };

    function appendObSyncLogRow($log, ok, title, detailText) {
        var cls = ok ? 'dex-ob-sync-log__item dex-ob-sync-log__item--ok' : 'dex-ob-sync-log__item dex-ob-sync-log__item--err';
        var badge = ok ? '✓' : '!';
        var $li = $('<li/>', { 'class': cls, role: 'status' });
        $li.append($('<span/>', { 'class': 'dex-ob-sync-log__badge', 'aria-hidden': 'true' }).text(badge));
        var $main = $('<div/>', { 'class': 'dex-ob-sync-log__main' });
        $main.append($('<span/>', { 'class': 'dex-ob-sync-log__title' }).text(title));
        if (detailText) {
            $main.append($('<span/>', { 'class': 'dex-ob-sync-log__meta' }).text(detailText));
        }
        $li.append($main);
        $log.append($li);
    }

    $('#dex-ob-sync').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#dex-ob-sync-spinner');
        var $log     = $('#dex-ob-sync-log');
        var $result  = $('#dex-ob-sync-result');
        var $next    = $('#dex-ob-panel-3 .dex-ob-next');
        var saved    = ob.credentialsSaved || {};

        if (!state.credentialsPersisted && (!saved.username || !saved.password)) {
            setInlineResult($result, '✗ Prvo završite korak „Povezivanje naloga“ i sačuvajte podatke.', false);
            obLog('warning', 'Korak 3 — blokirana sinhronizacija, kredencijali nisu konfigurisani');
            return;
        }

        clearResult($result);
        $log.empty();
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        obLog('info', 'Korak 3 — ažuriranje liste mesta pokrenuto');

        var i = 0;

        function step() {
            if (i >= SYNC_ALL_ORDER.length) {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                setInlineResult($result, '✓ Sve liste su ažurirane. Možete nastaviti.', true);
                $next.prop('disabled', false);
                obLog('info', 'Korak 3 — sinhronizacija završena uspešno (' + SYNC_ALL_ORDER.length + ' tipova)');
                return;
            }

            var currentType = SYNC_ALL_ORDER[i];
            var label = SYNC_CATALOG_LABELS[currentType] || currentType;

            $.post(ob.ajaxUrl, {
                action: 'dexpress_manual_sync',
                nonce:  ob.nonces.manualSync,
                type:   currentType,
            })
            .done(function (response) {
                if (response.success) {
                    var d = response.data || {};
                    var detail = (d.inserted || 0) + ' novih · ' + (d.updated || 0) + ' ažurirano';
                    appendObSyncLogRow($log, true, label, detail);
                    obLog('info', 'Korak 3 — sync ' + currentType + ': ' + (d.inserted || 0) + ' dodato, ' + (d.updated || 0) + ' ažurirano, ' + (d.deleted || 0) + ' obrisano');
                    i++;
                    step();
                } else {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    var msg = response.data && response.data.message ? response.data.message : 'Greška pri sinhronizaciji.';
                    appendObSyncLogRow($log, false, label, msg);
                    setInlineResult($result, '✗ Sinhronizacija neuspešna.', false);
                    obLog('warning', 'Korak 3 — sync ' + currentType + ' neuspešan: ' + msg);
                }
            })
            .fail(function () {
                $spinner.removeClass('is-active');
                $btn.prop('disabled', false);
                appendObSyncLogRow($log, false, label, 'Greška mreže');
                setInlineResult($result, '✗ Greška pri slanju zahteva.', false);
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
        function close()   { $box.removeClass('is-open').empty(); }

        function renderItems(items) {
            spinOff();
            $box.empty();
            if (!items.length) {
                $('<div class="dex-dropdown__empty">').text('Nema rezultata').appendTo($box);
            } else {
                $.each(items, function (i, item) {
                    $('<div class="dex-dropdown__item" role="option" tabindex="-1">')
                        .text(item.name)
                        .data('item', item)
                        .appendTo($box);
                });
            }
            $box.addClass('is-open');
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

        $(document).on('click', '#' + opts.suggestionsId + ' .dex-dropdown__item', function () {
            var item = $(this).data('item');
            if (item) { opts.onSelect(item); close(); }
        });

        $(document).on('keydown', '#' + opts.inputId, function (e) {
            var $items = $box.find('.dex-dropdown__item[tabindex]');
            if (!$items.length) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); $items.first().trigger('focus'); }
            else if (e.key === 'Escape') { close(); }
        });

        $(document).on('keydown', '#' + opts.suggestionsId + ' .dex-dropdown__item', function (e) {
            var $item = $(this);
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var item = $item.data('item');
                if (item) { opts.onSelect(item); close(); }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault(); $item.next('.dex-dropdown__item').trigger('focus');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var $prev = $item.prev('.dex-dropdown__item');
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
    var obTownAC = buildObAutocomplete({ // eslint-disable-line no-unused-vars
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

        if (!name)                    { setInlineResult($result, 'Naziv lokacije je obavezan.', false); return; }
        if (!townId)                  { setInlineResult($result, 'Izaberite grad iz liste.', false); return; }
        if (!streetId || !street)     { setInlineResult($result, 'Izaberite ulicu iz liste.', false); return; }
        if (!number)                  { setInlineResult($result, 'Kućni broj je obavezan.', false); return; }
        if (!contact)                 { setInlineResult($result, 'Kontakt osoba je obavezna.', false); return; }
        if (!phone)                   { setInlineResult($result, 'Telefon je obavezan.', false); return; }

        var phoneNorm = normalizePhone(phone);
        if (!PHONE_RE.test(phoneNorm)) {
            setInlineResult($result, 'Telefon nije u ispravnom formatu. Primer: 381641234567', false);
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
            address_desc:  $('#dex-ob-loc-addr-desc').val().trim(),
            contact_name:  contact,
            contact_phone: phoneNorm,
            bank_account:  $('#dex-ob-loc-bank-account').val().trim(),
        })
        .done(function (response) {
            if (response.success) {
                setInlineResult($result, '✓ Lokacija je sačuvana.', true);
                $next.prop('disabled', false);
                obLog('info', 'Korak 4 — lokacija pošiljaoca sačuvana: ' + name);
            } else {
                var msg = response.data && response.data.message ? response.data.message : 'Greška pri čuvanju.';
                setInlineResult($result, '✗ ' + msg, false);
                obLog('warning', 'Korak 4 — čuvanje lokacije neuspešno: ' + msg);
            }
        })
        .fail(function () {
            setInlineResult($result, '✗ Greška pri slanju zahteva.', false);
            obLog('warning', 'Korak 4 — AJAX greška pri čuvanju lokacije');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Sačuvaj lokaciju');
        });
    });

    // -----------------------------------------------------------------------
    // Step 5 — Method selection + apply to shipping zone
    // -----------------------------------------------------------------------

    var METHOD_LABELS = {
        'dexpress':              'D Express — kućna dostava',
        'dexpress_package_shop': 'D Express — paketomat / paket shop',
    };

    function getSelectedMethods() {
        var methods = [];
        $('#dex-ob-panel-5 input[type="checkbox"]').each(function () {
            if ($(this).is(':checked')) {
                methods.push($(this).val());
            }
        });
        return methods;
    }

    function renderZoneSummary(d) {
        var lines = [];

        var zoneInfo = d.zone_created
            ? 'Kreirana nova zona: <strong>' + d.zone_name + '</strong>'
            : 'Korišćena zona: <strong>' + d.zone_name + '</strong>';
        lines.push(zoneInfo);

        if (d.added && d.added.length) {
            var addedNames = d.added.map(function (id) { return METHOD_LABELS[id] || id; });
            lines.push('Dodato: ' + addedNames.join(', '));
        }

        if (d.skipped && d.skipped.length) {
            var skippedNames = d.skipped.map(function (id) { return METHOD_LABELS[id] || id; });
            lines.push('Već postoji: ' + skippedNames.join(', '));
        }

        return lines.join('<br>');
    }

    $('#dex-ob-create-zone').on('click', function () {
        var $btn      = $(this);
        var $result   = $('#dex-ob-zone-result');
        var $next     = $('#dex-ob-panel-5 .dex-ob-next');
        var $validMsg = $('#dex-ob-method-validation');
        var methods   = getSelectedMethods();

        clearResult($result);
        $validMsg.text('');

        if (!methods.length) {
            $validMsg.text('Izaberite barem jedan metod dostave pre primene.');
            return;
        }

        $btn.prop('disabled', true);

        $.post(ob.ajaxUrl, {
            action:  'dexpress_onboarding_create_zone',
            nonce:   ob.nonces.createZone,
            methods: methods,
        })
        .done(function (response) {
            if (response.success) {
                var d = response.data || {};
                $result
                    .html('✓ ' + renderZoneSummary(d))
                    .removeClass('is-success is-error')
                    .addClass('is-success');
                $next.prop('disabled', false);
                obLog('info', 'Korak 5 — metode primenjene. Zona: "' + d.zone_name + '", dodato: ' + JSON.stringify(d.added) + ', preskočeno: ' + JSON.stringify(d.skipped));
            } else {
                var errMsg = response.data && response.data.message ? response.data.message : 'Greška pri primeni metoda dostave.';
                setInlineResult($result, '✗ ' + errMsg, false);
                obLog('warning', 'Korak 5 — primena neuspešna: ' + errMsg);
            }
        })
        .fail(function (xhr) {
            var errMsg = 'HTTP ' + xhr.status + ' — proverite server log.';
            setInlineResult($result, '✗ ' + errMsg, false);
            obLog('warning', 'Korak 5 — AJAX greška: ' + errMsg);
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // -----------------------------------------------------------------------
    // Step 6 — Complete onboarding
    // -----------------------------------------------------------------------

    $('#dex-ob-complete').on('click', function () {
        var $btn     = $(this);
        var $pending = $('#dex-ob-finish-pending');
        var $outcome = $('#dex-ob-finish-outcome');
        var $links   = $('#dex-ob-finish-links');
        var $result  = $('#dex-ob-complete-result');
        var $saving  = $('#dex-ob-finish-saving');
        var $saved   = $('#dex-ob-finish-saved');

        clearResult($result);
        $result.attr('hidden', true);
        $pending.attr('hidden', true);
        $outcome.removeAttr('hidden');
        $links.attr('hidden', true);
        $saved.attr('hidden', true).removeClass('is-visible');
        $saving.removeAttr('hidden');
        $btn.prop('disabled', true);

        $.post(ob.ajaxUrl, {
            action: 'dexpress_onboarding_complete',
            nonce:  ob.nonces.complete,
        })
        .done(function (response) {
            $saving.attr('hidden', true);
            if (response.success) {
                $saved.removeAttr('hidden');
                window.requestAnimationFrame(function () {
                    $saved.addClass('is-visible');
                });
                setTimeout(function () {
                    $links.removeAttr('hidden');
                    $links.find('a.dex-ob-finish-link-card').first().focus();
                }, 420);
            } else {
                var msg = response.data && response.data.message ? response.data.message : 'Greška.';
                $result.removeAttr('hidden');
                setInlineResult($result, '✗ ' + msg, false);
                $pending.removeAttr('hidden');
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            $saving.attr('hidden', true);
            $result.removeAttr('hidden');
            setInlineResult($result, '✗ Greška pri slanju zahteva.', false);
            $pending.removeAttr('hidden');
            $btn.prop('disabled', false);
        });
    });

}(jQuery));
