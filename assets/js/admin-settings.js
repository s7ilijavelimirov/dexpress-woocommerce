/* global dexpressAdmin, jQuery */
(function ($) {
    'use strict';

    var admin = dexpressAdmin;

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------

    function setResult($el, message, isSuccess) {
        $el.text(message)
            .removeClass('is-success is-error')
            .addClass(isSuccess ? 'is-success' : 'is-error');
    }

    function clearResult($el) {
        $el.text('').removeClass('is-success is-error');
    }

    // -----------------------------------------------------------------------
    // Show / hide password toggle
    // -----------------------------------------------------------------------

    $(document).on('click', '.dex-toggle-password', function () {
        var targetId  = $(this).data('target');
        var $input    = $('#' + targetId);
        var $icon     = $(this).find('.dashicons');
        var $srText   = $(this).find('.screen-reader-text');
        var isPass    = $input.attr('type') === 'password';

        $input.attr('type', isPass ? 'text' : 'password');
        $icon.toggleClass('dashicons-visibility', !isPass)
             .toggleClass('dashicons-hidden', isPass);
        $(this).attr('aria-pressed', isPass ? 'true' : 'false');
        $srText.text(isPass ? 'Sakrij lozinku' : 'Prikaži lozinku');
    });

    // -----------------------------------------------------------------------
    // Copy to clipboard
    // -----------------------------------------------------------------------

    $(document).on('click', '.dex-copy-btn', function () {
        var targetId = $(this).data('target');
        var text     = $('#' + targetId).text().trim();
        var $btn     = $(this);

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                var orig = $btn.text();
                $btn.text('Kopirano!');
                setTimeout(function () { $btn.text(orig); }, 1500);
            });
        }
    });

    // -----------------------------------------------------------------------
    // Test connection
    // -----------------------------------------------------------------------

    $('#dex-test-connection').on('click', function () {
        var $btn    = $(this);
        var $result = $('#dex-test-connection-result');

        clearResult($result);
        $btn.prop('disabled', true).text(admin.strings.testing);

        $.post(admin.ajaxUrl, {
            action:   'dexpress_test_connection',
            nonce:    admin.nonces.testConnection,
            username: $('#api_username').val(),
            password: $('#api_password').val(),
        })
        .done(function (response) {
            if (response.success) {
                setResult($result, response.data.message, true);
            } else {
                setResult($result, response.data.message, false);
            }
        })
        .fail(function () {
            setResult($result, 'Greška pri slanju zahteva.', false);
        })
        .always(function () {
            $btn.prop('disabled', false).text('Testiraj konekciju');
        });
    });

    // -----------------------------------------------------------------------
    // Generic autocomplete helpers
    // -----------------------------------------------------------------------

    function buildAutocomplete(opts) {
        // opts: { inputId, suggestionsId, wrapperClass, onSelect, searchFn, spinnerId }
        var timer    = null;
        var $input   = $('#' + opts.inputId);
        var $box     = $('#' + opts.suggestionsId);
        var $spinner = opts.spinnerId ? $('#' + opts.spinnerId) : null;

        function spinOn()  { if ($spinner) $spinner.addClass('is-active'); }
        function spinOff() { if ($spinner) $spinner.removeClass('is-active'); }

        function close() { $box.hide().empty(); }

        function renderResults(items) {
            spinOff();
            $box.empty();
            if (!items.length) {
                $box.append('<div class="dex-dropdown__item dex-dropdown__empty">Nema rezultata</div>');
            } else {
                $.each(items, function (i, item) {
                    $('<div class="dex-dropdown__item" role="option" tabindex="-1">')
                        .text(item.name)
                        .data('item', item)
                        .appendTo($box);
                });
            }
            $box.show();
        }

        $(document).on('input', '#' + opts.inputId, function () {
            var q = $(this).val().trim();
            clearTimeout(timer);
            if (q.length < 2) { close(); spinOff(); return; }

            timer = setTimeout(function () {
                spinOn();
                opts.searchFn(q, renderResults);
            }, 280);
        });

        $(document).on('click', '#' + opts.suggestionsId + ' .dex-dropdown__item', function () {
            var item = $(this).data('item');
            if (item) { opts.onSelect(item); close(); }
        });

        // Keyboard: arrow down into list from input.
        $(document).on('keydown', '#' + opts.inputId, function (e) {
            var $items = $box.find('.dex-dropdown__item[tabindex]');
            if (!$items.length) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); $items.first().trigger('focus'); }
            else if (e.key === 'Escape') { close(); }
        });

        // Keyboard: navigate within list.
        $(document).on('keydown', '#' + opts.suggestionsId + ' .dex-dropdown__item', function (e) {
            var $item = $(this);
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var item = $item.data('item');
                if (item) { opts.onSelect(item); close(); }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                $item.next('.dex-dropdown__item').trigger('focus');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var $prev = $item.prev('.dex-dropdown__item');
                if ($prev.length) { $prev.trigger('focus'); }
                else { $input.trigger('focus'); }
            } else if (e.key === 'Escape') {
                close();
                $input.trigger('focus');
            }
        });

        // Close on outside click.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.' + opts.wrapperClass).length) { close(); }
        });

        return { close: close };
    }

    // -----------------------------------------------------------------------
    // Town autocomplete
    // -----------------------------------------------------------------------

    var townAC = buildAutocomplete({
        inputId:       'dex-loc-town-name',
        suggestionsId: 'dex-town-suggestions',
        wrapperClass:  'dex-town-autocomplete',
        spinnerId:     'dex-town-spinner',
        searchFn: function (q, cb) {
            $.get(admin.ajaxUrl, {
                action: 'dexpress_admin_search_towns',
                nonce:  admin.nonces.searchTowns,
                q:      q,
            })
            .done(function (r) { cb(r.success ? r.data : []); })
            .fail(function ()  { cb([]); });
        },
        onSelect: function (town) {
            $('#dex-loc-town-id').val(town.id);
            $('#dex-loc-town-name').val(town.name);
            // Clear street when town changes.
            $('#dex-loc-street-name').val('');
            $('#dex-loc-street-id').val('');
            streetAC.close();
        },
    });

    // Clear town-id + street when user edits the town text directly.
    $(document).on('input', '#dex-loc-town-name', function () {
        $('#dex-loc-town-id').val('');
        $('#dex-loc-street-name').val('');
        $('#dex-loc-street-id').val('');
    });

    // -----------------------------------------------------------------------
    // Street autocomplete
    // -----------------------------------------------------------------------

    var streetAC = buildAutocomplete({
        inputId:       'dex-loc-street-name',
        suggestionsId: 'dex-street-suggestions',
        wrapperClass:  'dex-street-autocomplete',
        spinnerId:     'dex-street-spinner',
        searchFn: function (q, cb) {
            var townId = $('#dex-loc-town-id').val();
            if (!townId) { cb([]); return; }

            $.get(admin.ajaxUrl, {
                action:  'dexpress_admin_search_streets',
                nonce:   admin.nonces.searchStreets,
                town_id: townId,
                q:       q,
            })
            .done(function (r) { cb(r.success ? r.data : []); })
            .fail(function ()  { cb([]); });
        },
        onSelect: function (street) {
            $('#dex-loc-street-id').val(street.id);
            $('#dex-loc-street-name').val(street.name);
        },
    });

    // -----------------------------------------------------------------------
    // Phone validation (Bug 3)
    // -----------------------------------------------------------------------

    var PHONE_RE = /^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$/;

    function normalizePhone(raw) {
        raw = raw.replace(/[\s\-\(\)]/g, '').replace(/^\+/, '');
        if (/^00381/.test(raw)) { raw = '381' + raw.slice(5); }
        else if (/^00/.test(raw)) { raw = raw.slice(2); }
        else if (/^0/.test(raw)) { raw = '381' + raw.slice(1); }
        return raw;
    }

    $(document).on('blur', '#dex-loc-contact-phone', function () {
        var raw  = $(this).val().trim();
        var $err = $('#dex-phone-error');

        if (raw === '') {
            $err.text('').removeClass('is-visible');
            return;
        }

        var normalized = normalizePhone(raw);

        if (!PHONE_RE.test(normalized)) {
            $err.text('Format nije ispravan. Primer: 381641234567').addClass('is-visible');
        } else {
            $(this).val(normalized);
            $err.text('').removeClass('is-visible');
        }
    });

    // -----------------------------------------------------------------------
    // Sender location modal
    // -----------------------------------------------------------------------

    function openModal(title, data) {
        data = data || {};

        $('#dex-modal-title').text(title);
        $('#dex-location-id').val(data.id || '');
        $('#dex-loc-name').val(data.name || '');
        $('#dex-loc-street-id').val(data.streetId || '');
        $('#dex-loc-street-name').val(data.streetName || '');
        $('#dex-loc-number').val(data.streetNumber || '');
        $('#dex-loc-addr-desc').val(data.addressDesc || '');
        $('#dex-loc-contact-name').val(data.contactName || '');
        $('#dex-loc-contact-phone').val(data.contactPhone || '');
        $('#dex-loc-bank-account').val(data.bankAccount || '');
        $('#dex-phone-error').text('').removeClass('is-visible');

        $('#dex-loc-town-id').val(data.townId || '');
        $('#dex-loc-town-name').val(data.townName || '');

        townAC.close();
        streetAC.close();
        clearResult($('#dex-location-result'));

        $('#dex-location-modal').addClass('is-open');
        $('body').addClass('dex-modal-open');
        $('#dex-loc-name').trigger('focus');
    }

    function closeModal() {
        $('#dex-location-modal').removeClass('is-open');
        $('body').removeClass('dex-modal-open');
        townAC.close();
        streetAC.close();
    }

    $('#dex-add-location').on('click', function () {
        openModal('Dodaj lokaciju');
    });

    $(document).on('click', '.dex-edit-location', function () {
        var $btn = $(this);
        openModal('Izmeni lokaciju', {
            id:           $btn.data('id'),
            name:         $btn.data('name'),
            streetId:     $btn.data('street-id'),
            streetName:   $btn.data('street-name'),
            streetNumber: $btn.data('street-number'),
            townId:       $btn.data('town-id'),
            townName:     $btn.data('town-name'),
            addressDesc:  $btn.data('address-desc'),
            contactName:  $btn.data('contact-name'),
            contactPhone: $btn.data('contact-phone'),
            bankAccount:  $btn.data('bank-account'),
        });
    });

    $('#dex-cancel-location').on('click', closeModal);

    $('#dex-location-modal').on('click', '.dex-modal__backdrop', closeModal);

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') { closeModal(); }
    });

    // Save location.
    $('#dex-save-location').on('click', function () {
        var $btn        = $(this);
        var $result     = $('#dex-location-result');
        var townId      = $('#dex-loc-town-id').val();
        var streetId    = $('#dex-loc-street-id').val();
        var street      = $('#dex-loc-street-name').val().trim();
        var name        = $('#dex-loc-name').val().trim();
        var contactName = $('#dex-loc-contact-name').val().trim();

        if (!name) {
            setResult($result, 'Naziv lokacije je obavezan.', false);
            $('#dex-loc-name').trigger('focus');
            return;
        }

        if (!townId) {
            setResult($result, 'Izaberite grad iz liste.', false);
            $('#dex-loc-town-name').trigger('focus');
            return;
        }

        if (!streetId || !street) {
            setResult($result, 'Izaberite ulicu iz liste.', false);
            $('#dex-loc-street-name').trigger('focus');
            return;
        }

        var number = $('#dex-loc-number').val().trim();
        if (!number) {
            setResult($result, 'Kućni broj je obavezan.', false);
            $('#dex-loc-number').trigger('focus');
            return;
        }

        if (!contactName) {
            setResult($result, 'Kontakt osoba je obavezna.', false);
            $('#dex-loc-contact-name').trigger('focus');
            return;
        }

        var phone    = $('#dex-loc-contact-phone').val().trim();
        if (!phone) {
            setResult($result, 'Telefon je obavezan.', false);
            $('#dex-loc-contact-phone').trigger('focus');
            return;
        }
        var phoneVal = normalizePhone(phone);
        if (!PHONE_RE.test(phoneVal)) {
            setResult($result, 'Telefon nije u ispravnom formatu. Primer: 381641234567', false);
            $('#dex-loc-contact-phone').trigger('focus');
            return;
        }

        clearResult($result);
        $btn.prop('disabled', true).text(admin.strings.saving);

        $.post(admin.ajaxUrl, {
            action:        'dexpress_save_sender_location',
            nonce:         admin.nonces.saveSenderLocation,
            id:            $('#dex-location-id').val(),
            name:          name,
            street_id:     streetId,
            street_name:   street,
            street_number: number,
            town_id:       townId,
            address_desc:  $('#dex-loc-addr-desc').val(),
            contact_name:  contactName,
            contact_phone: phoneVal,
            bank_account:  $('#dex-loc-bank-account').val().trim(),
        })
        .done(function (response) {
            if (response.success) {
                window.location.reload();
            } else {
                setResult($result, response.data.message, false);
                $btn.prop('disabled', false).text('Sačuvaj');
            }
        })
        .fail(function () {
            setResult($result, 'Greška pri slanju zahteva.', false);
            $btn.prop('disabled', false).text('Sačuvaj');
        });
    });

    // Delete location.
    $(document).on('click', '.dex-delete-location', function () {
        if (!window.confirm(admin.strings.confirmDelete)) {
            return;
        }

        var id   = $(this).data('id');
        var $row = $(this).closest('tr');

        $.post(admin.ajaxUrl, {
            action: 'dexpress_delete_sender_location',
            nonce:  admin.nonces.deleteSenderLocation,
            id:     id,
        })
        .done(function (response) {
            if (response.success) {
                $row.remove();
                if ($('#dex-locations-list tr').length === 0) {
                    window.location.reload();
                }
            } else {
                window.alert(response.data.message);
            }
        });
    });

    // Set default location.
    $(document).on('click', '.dex-set-default', function () {
        var id = $(this).data('id');

        $.post(admin.ajaxUrl, {
            action: 'dexpress_set_default_location',
            nonce:  admin.nonces.setDefaultLocation,
            id:     id,
        })
        .done(function (response) {
            if (response.success) {
                window.location.reload();
            } else {
                window.alert(response.data.message);
            }
        });
    });

    // -----------------------------------------------------------------------
    // Manual sync (Šifarnici)
    // -----------------------------------------------------------------------

    function setRowSyncStatus(type, state) {
        var $cell = $('tr[data-sync-type="' + type + '"] .dex-sync-status');
        if (!$cell.length) {
            return;
        }
        if (state === 'loading') {
            $cell.html('<span class="spinner is-active"></span>');
        } else if (state === 'success') {
            $cell.html('<span class="dashicons dashicons-yes-alt dex-sync-ok" aria-hidden="true"></span>');
        } else if (state === 'error') {
            $cell.html('<span class="dashicons dashicons-dismiss dex-sync-err" aria-hidden="true"></span>');
        } else {
            $cell.empty();
        }
    }

    function clearAllRowSyncStatuses() {
        $('.dex-sync-table .dex-sync-status').empty();
    }

    function updateLastSyncCell($btn) {
        var $td = $btn.closest('tr').find('td:nth-child(3)');
        var now = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        var ts  = pad(now.getDate()) + '.' +
            pad(now.getMonth() + 1) + '.' +
            now.getFullYear() + ' ' +
            pad(now.getHours()) + ':' +
            pad(now.getMinutes()) + ':' +
            pad(now.getSeconds());
        $td.text(ts);
    }

    function runSync(type, $btn, $result, requireConfirm) {
        if (requireConfirm && !window.confirm(admin.strings.confirmSync)) {
            return;
        }

        var originalText = $btn.text();

        $btn.prop('disabled', true).text(admin.strings.syncing);
        if ($result) { clearResult($result); }
        setRowSyncStatus(type, 'loading');

        $.post(admin.ajaxUrl, {
            action: 'dexpress_manual_sync',
            nonce:  admin.nonces.manualSync,
            type:   type,
        })
            .done(function (response) {
                if (response.success) {
                    setRowSyncStatus(type, 'success');
                    if ($result) { setResult($result, '✓ ' + response.data.message, true); }
                    $btn.prop('disabled', false).text(originalText);
                    updateLastSyncCell($btn);
                } else {
                    setRowSyncStatus(type, 'error');
                    if ($result) { setResult($result, response.data.message, false); }
                    $btn.prop('disabled', false).text(originalText);
                }
            })
            .fail(function () {
                setRowSyncStatus(type, 'error');
                if ($result) { setResult($result, admin.strings.syncAjaxFail, false); }
                $btn.prop('disabled', false).text(originalText);
            });
    }

    $(document).on('click', '.dex-manual-sync', function () {
        var $btn    = $(this);
        var type    = $btn.data('type');
        var $result = $btn.closest('td').find('.dex-sync-result');
        runSync(type, $btn, $result, false);
    });

    $('#dex-sync-all').on('click', function () {
        if (!window.confirm(admin.strings.confirmSync)) {
            return;
        }

        var order = admin.syncAllOrder;
        if (!order || !order.length) {
            return;
        }

        var $mainBtn    = $(this);
        var $mainResult = $('#dex-sync-all-result');
        var $allRowBtns = $('.dex-sync-table .dex-manual-sync');

        if (!$mainBtn.data('orig-label')) {
            $mainBtn.data('orig-label', $mainBtn.text());
        }

        clearResult($mainResult);
        clearAllRowSyncStatuses();

        $mainBtn.prop('disabled', true).text(admin.strings.syncAllRunning);
        $allRowBtns.prop('disabled', true);

        function finishFail(msg) {
            setResult($mainResult, msg, false);
            $mainBtn.prop('disabled', false).text($mainBtn.data('orig-label'));
            $allRowBtns.prop('disabled', false);
        }

        function step(i) {
            if (i >= order.length) {
                setResult($mainResult, '✓ ' + admin.strings.syncAllDone, true);
                $mainBtn.prop('disabled', false).text($mainBtn.data('orig-label'));
                $allRowBtns.prop('disabled', false);
                setTimeout(function () { window.location.reload(); }, 1200);
                return;
            }

            var type = order[i];
            setRowSyncStatus(type, 'loading');

            $.post(admin.ajaxUrl, {
                action: 'dexpress_manual_sync',
                nonce:  admin.nonces.manualSync,
                type:   type,
            })
                .done(function (response) {
                    if (response.success) {
                        setRowSyncStatus(type, 'success');
                        var $rowBtn = $('.dex-manual-sync[data-type="' + type + '"]');
                        if ($rowBtn.length) {
                            updateLastSyncCell($rowBtn);
                        }
                        step(i + 1);
                    } else {
                        setRowSyncStatus(type, 'error');
                        finishFail(response.data && response.data.message ? response.data.message : admin.strings.syncStepUnknown);
                    }
                })
                .fail(function () {
                    setRowSyncStatus(type, 'error');
                    finishFail(admin.strings.syncAjaxFail);
                });
        }

        step(0);
    });

    // -----------------------------------------------------------------------
    // Simulation tab — show timing only when simulation on; Brza/Realna UI
    // -----------------------------------------------------------------------

    var $simSection = $('#dex-section-simulation');
    if ($simSection.length) {
        var $simEnabled = $('#dex-simulation-enabled');
        var $simWrap    = $('#dex-sim-timing-wrap');
        var $quickCb    = $('#dex-sim-quick-checkbox');
        var $btnBrza    = $('#dex-sim-mode-brza');
        var $btnRealna  = $('#dex-sim-mode-realna');
        var $panelBrza  = $('#dex-sim-timing-brza');
        var $panelReal  = $('#dex-sim-timing-realna');

        function simSyncToggleUi() {
            var quick = $quickCb.prop('checked');
            $btnBrza.toggleClass('is-selected', quick);
            $btnRealna.toggleClass('is-selected', !quick);
            $btnBrza.attr('aria-pressed', quick ? 'true' : 'false');
            $btnRealna.attr('aria-pressed', quick ? 'false' : 'true');
            $panelBrza.prop('hidden', !quick);
            $panelReal.prop('hidden', quick);
        }

        function simSetWrapVisible(show) {
            $simWrap.toggle(!!show);
        }

        $simEnabled.on('change', function () {
            var on = $(this).prop('checked');
            simSetWrapVisible(on);
            if (on) {
                $quickCb.prop('checked', true);
                simSyncToggleUi();
            }
        });

        $btnBrza.on('click', function () {
            $quickCb.prop('checked', true);
            simSyncToggleUi();
        });

        $btnRealna.on('click', function () {
            $quickCb.prop('checked', false);
            simSyncToggleUi();
        });

        simSyncToggleUi();
        simSetWrapVisible($simEnabled.prop('checked'));
    }

    // -----------------------------------------------------------------------
    // Password saved-state toggle (Promeni / Otkaži)
    // -----------------------------------------------------------------------

    $(document).on('click', '#dex-pw-change', function () {
        $('#dex-pw-saved-indicator').addClass('dex-hidden');
        $('#dex-pw-input-wrap').removeClass('dex-hidden');
        $('#dex-pw-hint').removeClass('dex-hidden');
        $('#api_password').trigger('focus');
    });

    $(document).on('click', '#dex-pw-cancel', function () {
        // Clear the input and reset the visibility toggle state.
        $('#api_password').val('').attr('type', 'password');
        var $tog = $('.dex-toggle-password[data-target="api_password"]');
        $tog.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
        $tog.attr('aria-pressed', 'false');
        $tog.find('.screen-reader-text').text('Prikaži lozinku');

        $('#dex-pw-input-wrap').addClass('dex-hidden');
        $('#dex-pw-hint').addClass('dex-hidden');
        $('#dex-pw-saved-indicator').removeClass('dex-hidden');
    });

    // -----------------------------------------------------------------------
    // Unsaved-changes guard
    // -----------------------------------------------------------------------

    var dexDirty  = false;
    var dexNavUrl = null;

    function dexMarkDirty()  { dexDirty = true; }
    function dexClearDirty() { dexDirty = false; }

    // Standard form inputs inside the settings wrap.
    $(document).on(
        'input change',
        '.dex-settings-wrap form input, .dex-settings-wrap form textarea, .dex-settings-wrap form select',
        dexMarkDirty
    );

    // JS-toggled controls that modify hidden inputs without triggering change events.
    $(document).on(
        'click',
        '.dex-settings-wrap form .dex-env-option, .dex-settings-wrap form .dex-sim-toggle__btn',
        dexMarkDirty
    );

    // Clear dirty flag when the form is submitted (saved successfully).
    $(document).on('submit', '.dex-settings-wrap form', dexClearDirty);

    // Intercept tab navigation when there are unsaved changes.
    $(document).on('click', '.dex-tabs__item', function (e) {
        if (!dexDirty) { return; }
        e.preventDefault();
        dexNavUrl = $(this).attr('href');
        $('#dex-unsaved-modal').addClass('is-open');
        $('body').addClass('dex-modal-open');
        $('#dex-unsaved-confirm').trigger('focus');
    });

    // Confirm: leave the tab without saving.
    $('#dex-unsaved-confirm').on('click', function () {
        dexClearDirty();
        dexCloseUnsavedModal();
        if (dexNavUrl) {
            window.location.href = dexNavUrl;
        }
    });

    function dexCloseUnsavedModal() {
        $('#dex-unsaved-modal').removeClass('is-open');
        $('body').removeClass('dex-modal-open');
        dexNavUrl = null;
    }

    $('#dex-unsaved-cancel').on('click', dexCloseUnsavedModal);
    $('#dex-unsaved-modal').on('click', '.dex-modal__backdrop', dexCloseUnsavedModal);

    // Escape closes the unsaved modal (the existing keydown handler covers the
    // location modal; this one only fires when the unsaved modal is open).
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#dex-unsaved-modal').hasClass('is-open')) {
            dexCloseUnsavedModal();
        }
    });

}(jQuery));
