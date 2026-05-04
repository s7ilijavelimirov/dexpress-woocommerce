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

    $(document).on('click', '.dexpress-toggle-password', function () {
        var targetId = $(this).data('target');
        var $input   = $('#' + targetId);
        var isPass   = $input.attr('type') === 'password';

        $input.attr('type', isPass ? 'text' : 'password');
        $(this).text(isPass ? 'Sakrij' : 'Prikaži');
    });

    // -----------------------------------------------------------------------
    // Copy to clipboard
    // -----------------------------------------------------------------------

    $(document).on('click', '.dexpress-copy-btn', function () {
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

    $('#dexpress-test-connection').on('click', function () {
        var $btn    = $(this);
        var $result = $('#dexpress-test-connection-result');

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
                $box.append('<div class="dexpress-suggestion-item dexpress-suggestion-empty">Nema rezultata</div>');
            } else {
                $.each(items, function (i, item) {
                    $('<div class="dexpress-suggestion-item" role="option" tabindex="-1">')
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

        $(document).on('click', '#' + opts.suggestionsId + ' .dexpress-suggestion-item', function () {
            var item = $(this).data('item');
            if (item) { opts.onSelect(item); close(); }
        });

        // Keyboard: arrow down into list from input.
        $(document).on('keydown', '#' + opts.inputId, function (e) {
            var $items = $box.find('.dexpress-suggestion-item[tabindex]');
            if (!$items.length) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); $items.first().trigger('focus'); }
            else if (e.key === 'Escape') { close(); }
        });

        // Keyboard: navigate within list.
        $(document).on('keydown', '#' + opts.suggestionsId + ' .dexpress-suggestion-item', function (e) {
            var $item = $(this);
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var item = $item.data('item');
                if (item) { opts.onSelect(item); close(); }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                $item.next('.dexpress-suggestion-item').trigger('focus');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var $prev = $item.prev('.dexpress-suggestion-item');
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
        inputId:       'dexpress-loc-town-name',
        suggestionsId: 'dexpress-town-suggestions',
        wrapperClass:  'dexpress-town-autocomplete',
        spinnerId:     'dexpress-town-spinner',
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
            $('#dexpress-loc-town-id').val(town.id);
            $('#dexpress-loc-town-name').val(town.name);
            // Clear street when town changes.
            $('#dexpress-loc-street-name').val('');
            $('#dexpress-loc-street-id').val('');
            streetAC.close();
        },
    });

    // Clear town-id + street when user edits the town text directly.
    $(document).on('input', '#dexpress-loc-town-name', function () {
        $('#dexpress-loc-town-id').val('');
        $('#dexpress-loc-street-name').val('');
        $('#dexpress-loc-street-id').val('');
    });

    // -----------------------------------------------------------------------
    // Street autocomplete
    // -----------------------------------------------------------------------

    var streetAC = buildAutocomplete({
        inputId:       'dexpress-loc-street-name',
        suggestionsId: 'dexpress-street-suggestions',
        wrapperClass:  'dexpress-street-autocomplete',
        spinnerId:     'dexpress-street-spinner',
        searchFn: function (q, cb) {
            var townId = $('#dexpress-loc-town-id').val();
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
            $('#dexpress-loc-street-id').val(street.id);
            $('#dexpress-loc-street-name').val(street.name);
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

    $(document).on('blur', '#dexpress-loc-contact-phone', function () {
        var raw  = $(this).val().trim();
        var $err = $('#dexpress-phone-error');

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

        $('#dexpress-modal-title').text(title);
        $('#dexpress-location-id').val(data.id || '');
        $('#dexpress-loc-name').val(data.name || '');
        $('#dexpress-loc-street-id').val(data.streetId || '');
        $('#dexpress-loc-street-name').val(data.streetName || '');
        $('#dexpress-loc-number').val(data.streetNumber || '');
        $('#dexpress-loc-addr-desc').val(data.addressDesc || '');
        $('#dexpress-loc-contact-name').val(data.contactName || '');
        $('#dexpress-loc-contact-phone').val(data.contactPhone || '');
        $('#dexpress-loc-bank-account').val(data.bankAccount || '');
        $('#dexpress-phone-error').text('').removeClass('is-visible');

        $('#dexpress-loc-town-id').val(data.townId || '');
        $('#dexpress-loc-town-name').val(data.townName || '');

        townAC.close();
        streetAC.close();
        clearResult($('#dexpress-location-result'));

        $('#dexpress-modal-overlay, #dexpress-location-modal').show();
        $('#dexpress-loc-name').trigger('focus');
    }

    function closeModal() {
        $('#dexpress-modal-overlay, #dexpress-location-modal').hide();
        townAC.close();
        streetAC.close();
    }

    $('#dexpress-add-location').on('click', function () {
        openModal('Dodaj lokaciju');
    });

    $(document).on('click', '.dexpress-edit-location', function () {
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

    $('#dexpress-cancel-location, #dexpress-modal-overlay').on('click', closeModal);

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') { closeModal(); }
    });

    // Save location.
    $('#dexpress-save-location').on('click', function () {
        var $btn        = $(this);
        var $result     = $('#dexpress-location-result');
        var townId      = $('#dexpress-loc-town-id').val();
        var streetId    = $('#dexpress-loc-street-id').val();
        var street      = $('#dexpress-loc-street-name').val().trim();
        var name        = $('#dexpress-loc-name').val().trim();
        var contactName = $('#dexpress-loc-contact-name').val().trim();

        if (!name) {
            setResult($result, 'Naziv lokacije je obavezan.', false);
            $('#dexpress-loc-name').trigger('focus');
            return;
        }

        if (!townId) {
            setResult($result, 'Izaberite grad iz liste.', false);
            $('#dexpress-loc-town-name').trigger('focus');
            return;
        }

        if (!streetId || !street) {
            setResult($result, 'Izaberite ulicu iz liste.', false);
            $('#dexpress-loc-street-name').trigger('focus');
            return;
        }

        var number = $('#dexpress-loc-number').val().trim();
        if (!number) {
            setResult($result, 'Kućni broj je obavezan.', false);
            $('#dexpress-loc-number').trigger('focus');
            return;
        }

        if (!contactName) {
            setResult($result, 'Kontakt osoba je obavezna.', false);
            $('#dexpress-loc-contact-name').trigger('focus');
            return;
        }

        var phone    = $('#dexpress-loc-contact-phone').val().trim();
        if (!phone) {
            setResult($result, 'Telefon je obavezan.', false);
            $('#dexpress-loc-contact-phone').trigger('focus');
            return;
        }
        var phoneVal = normalizePhone(phone);
        if (!PHONE_RE.test(phoneVal)) {
            setResult($result, 'Telefon nije u ispravnom formatu. Primer: 381641234567', false);
            $('#dexpress-loc-contact-phone').trigger('focus');
            return;
        }

        clearResult($result);
        $btn.prop('disabled', true).text(admin.strings.saving);

        $.post(admin.ajaxUrl, {
            action:        'dexpress_save_sender_location',
            nonce:         admin.nonces.saveSenderLocation,
            id:            $('#dexpress-location-id').val(),
            name:          name,
            street_id:     streetId,
            street_name:   street,
            street_number: number,
            town_id:       townId,
            address_desc:  $('#dexpress-loc-addr-desc').val(),
            contact_name:  contactName,
            contact_phone: phoneVal,
            bank_account:  $('#dexpress-loc-bank-account').val().trim(),
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
    $(document).on('click', '.dexpress-delete-location', function () {
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
                if ($('#dexpress-locations-list tr').length === 0) {
                    window.location.reload();
                }
            } else {
                window.alert(response.data.message);
            }
        });
    });

    // Set default location.
    $(document).on('click', '.dexpress-set-default', function () {
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
    // Manual sync
    // -----------------------------------------------------------------------

    function runSync(type, $btn, $result, requireConfirm) {
        if (requireConfirm && !window.confirm(admin.strings.confirmSync)) {
            return;
        }

        var originalText = $btn.text();
        var reloadAfter  = (type === 'all');

        $btn.prop('disabled', true).text(admin.strings.syncing);
        if ($result) { clearResult($result); }

        $.post(admin.ajaxUrl, {
            action: 'dexpress_manual_sync',
            nonce:  admin.nonces.manualSync,
            type:   type,
        })
        .done(function (response) {
            if (response.success) {
                if ($result) { setResult($result, '✓ ' + response.data.message, true); }
                if (reloadAfter) {
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    $btn.prop('disabled', false).text(originalText);
                    var $td  = $btn.closest('tr').find('td:nth-child(3)');
                    var now  = new Date();
                    var pad  = function (n) { return String(n).padStart(2, '0'); };
                    var ts   = pad(now.getDate()) + '.' +
                               pad(now.getMonth() + 1) + '.' +
                               now.getFullYear() + ' ' +
                               pad(now.getHours()) + ':' +
                               pad(now.getMinutes()) + ':' +
                               pad(now.getSeconds());
                    $td.text(ts);
                }
            } else {
                if ($result) { setResult($result, response.data.message, false); }
                $btn.prop('disabled', false).text(originalText);
            }
        })
        .fail(function () {
            if ($result) { setResult($result, 'Greška pri slanju zahteva.', false); }
            $btn.prop('disabled', false).text(originalText);
        });
    }

    $(document).on('click', '.dexpress-manual-sync', function () {
        var $btn    = $(this);
        var type    = $btn.data('type');
        var $result = $btn.closest('td').find('.dexpress-sync-result');
        runSync(type, $btn, $result, false);
    });

    $('#dexpress-sync-all').on('click', function () {
        var $result = $('#dexpress-sync-all-result');
        runSync('all', $(this), $result, true);
    });

}(jQuery));
