/* global dexpressMetabox, jQuery */
(function ($) {
    'use strict';

    var mb = window.dexpressMetabox || {};
    var $root = $('#dex-shipment-root');
    if (!$root.length) {
        return;
    }

    function esc(str) {
        return $('<div/>').text(String(str || '')).html();
    }

    function setResult(msg, isError) {
        $('#dex-wizard-result').text(msg || '').toggleClass('is-error', !!isError).toggleClass('is-success', !isError && !!msg);
    }

    function openLabel(url, existingWindow) {
        if (!url) {
            return false;
        }
        var win = existingWindow || window.open('', '_blank');
        if (!win) {
            return false;
        }
        win.location = String(url);
        return true;
    }

    function initPendingSendState() {
        var $pending = $('.dex-state--draft[data-state="pending_send"]');
        if (!$pending.length) {
            return;
        }

        var shipmentId = parseInt($pending.data('shipment-id'), 10) || 0;
        var labelUrl = String($pending.data('label-url') || '');

        $('#dex-reprint-label').on('click', function () {
            if (!openLabel(labelUrl)) {
                setResult('Browser je blokirao pop-up prozor. Omogućite pop-up za admin stranicu.', true);
            }
        });

        $('#dex-edit-shipment').on('click', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('dexpress_edit_shipment', String(shipmentId));
            window.location.href = url.toString();
        });

        $('#dex-send-shipment').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text(mb.i18n.sending || 'Slanje...');
            setResult('', false);

            $.post(mb.ajaxUrl, {
                action: 'dexpress_send_saved_shipment',
                nonce: mb.nonceSendSaved,
                shipment_id: shipmentId
            }).done(function (resp) {
                if (!resp.success) {
                    setResult((resp.data && resp.data.message) || (mb.i18n.error || 'Greška.'), true);
                    return;
                }
                setResult((resp.data && resp.data.message) || 'Pošiljka je poslata.', false);
                setTimeout(function () { window.location.reload(); }, 1100);
            }).fail(function () {
                setResult(mb.i18n.error || 'Greška.', true);
            }).always(function () {
                $btn.prop('disabled', false).text(mb.i18n.sendToDexpress || 'Pošalji u D-Express');
            });
        });
    }

    function initWizardState() {
        var $wizard = $('.dex-state--wizard[data-state="wizard"]');
        if (!$wizard.length) {
            return;
        }

        var maxPackages = parseInt(mb.maxPackages, 10) || 30;
        var defaults = mb.defaults || {};
        var step = 1;
        var initialDraft = mb.initialDraft || null;
        var state = {
            packages: [],
            options: {
                sender_location_id: null,
                delivery_type: parseInt(defaults.delivery_type || 2, 10),
                payment_type: parseInt(defaults.payment_type || 2, 10),
                return_doc: parseInt(defaults.return_doc || 0, 10),
                self_drop_off: defaults.self_drop_off ? 1 : 0,
                content: '',
                note: ''
            }
        };

        function showError(msg) {
            $('#dex-wizard-error').text(msg).prop('hidden', false);
        }

        function clearError() {
            $('#dex-wizard-error').text('').prop('hidden', true);
        }

        function ensureAtLeastOnePackage() {
            if (!state.packages.length) {
                state.packages.push({ mass: 0, dim_x: null, dim_y: null, dim_z: null, content: '', items: {} });
            }
        }

        function packageItemsTable(pkgIndex, pkg) {
            var lines = mb.orderLineItems || [];
            if (!lines.length) {
                return '';
            }
            var rows = '';
            lines.forEach(function (line) {
                var key = String(line.id);
                var qty = (pkg.items && pkg.items[key]) ? parseInt(pkg.items[key], 10) : 0;
                rows += '<tr><td>' + esc(line.name) + '</td>' +
                    '<td><input type="number" min="0" max="' + parseInt(line.qty_max, 10) + '" class="small-text dex-item-qty" data-pkg="' + pkgIndex + '" data-item="' + key + '" value="' + qty + '"></td>' +
                    '<td>/ ' + parseInt(line.qty_max, 10) + '</td></tr>';
            });
            return '<div class="dex-item-allocation"><p class="dex-field-label">Raspodela stavki po paketu</p><table class="widefat striped"><tbody>' + rows + '</tbody></table></div>';
        }

        function renderPackages() {
            var html = '';
            state.packages.forEach(function (pkg, idx) {
                html += '<article class="dex-package-card">' +
                    '<div class="dex-package-card-head"><span class="dashicons dashicons-archive dex-package-icon"></span><strong>PKG_' + (idx + 1) + '</strong></div>' +
                    '<div class="dex-package-card-body">' +
                    '<p><label class="dex-field-label">Težina (kg) <span class="dex-req">*</span></label>' +
                    '<input type="number" min="0.01" step="0.01" class="widefat dex-pkg-mass" data-pkg="' + idx + '" value="' + ((pkg.mass || 0) > 0 ? ((pkg.mass || 0) / 1000).toFixed(2) : '') + '"></p>' +
                    '<div class="dex-dims-grid">' +
                    '<p><label class="dex-field-label">Dužina (cm)</label><input type="number" min="0" class="widefat dex-pkg-dx" data-pkg="' + idx + '" value="' + (pkg.dim_x || '') + '"></p>' +
                    '<p><label class="dex-field-label">Širina (cm)</label><input type="number" min="0" class="widefat dex-pkg-dy" data-pkg="' + idx + '" value="' + (pkg.dim_y || '') + '"></p>' +
                    '<p><label class="dex-field-label">Visina (cm)</label><input type="number" min="0" class="widefat dex-pkg-dz" data-pkg="' + idx + '" value="' + (pkg.dim_z || '') + '"></p>' +
                    '</div>' +
                    '<p><label class="dex-field-label">Sadržaj paketa</label><input type="text" maxlength="50" class="widefat dex-pkg-content" data-pkg="' + idx + '" value="' + esc(pkg.content || '') + '"></p>' +
                    packageItemsTable(idx, pkg) +
                    '</div></article>';
            });
            $('#dex-package-cards').html(html);
        }

        function readOptionsFromForm() {
            state.options.sender_location_id = parseInt($('#dex-sender-location').val(), 10) || 0;
            state.options.delivery_type = parseInt($('#dex-delivery-type').val(), 10) || 2;
            state.options.payment_type = parseInt($('#dex-payment-type').val(), 10) || 2;
            state.options.return_doc = parseInt($('#dex-return-doc').val(), 10) || 0;
            state.options.self_drop_off = $('#dex-self-drop-off').is(':checked') ? 1 : 0;
            state.options.content = ($('#dex-content').val() || '').trim();
            state.options.note = ($('#dex-note').val() || '').trim();
        }

        function readPackagesFromForm() {
            state.packages.forEach(function (pkg, idx) {
                var massKg = parseFloat($('.dex-pkg-mass[data-pkg="' + idx + '"]').val()) || 0;
                pkg.mass = massKg > 0 ? Math.round(massKg * 1000) : 0;
                var dx = parseInt($('.dex-pkg-dx[data-pkg="' + idx + '"]').val(), 10) || 0;
                var dy = parseInt($('.dex-pkg-dy[data-pkg="' + idx + '"]').val(), 10) || 0;
                var dz = parseInt($('.dex-pkg-dz[data-pkg="' + idx + '"]').val(), 10) || 0;
                pkg.dim_x = dx > 0 ? dx : null;
                pkg.dim_y = dy > 0 ? dy : null;
                pkg.dim_z = dz > 0 ? dz : null;
                pkg.content = (($('.dex-pkg-content[data-pkg="' + idx + '"]').val() || '').trim()).slice(0, 50);
                pkg.items = {};
                $('.dex-item-qty[data-pkg="' + idx + '"]').each(function () {
                    var qty = parseInt($(this).val(), 10) || 0;
                    if (qty > 0) {
                        pkg.items[String($(this).data('item'))] = qty;
                    }
                });
            });
        }

        function validateAllocations() {
            var totals = {};
            var limits = {};
            (mb.orderLineItems || []).forEach(function (line) {
                limits[String(line.id)] = parseInt(line.qty_max, 10) || 0;
            });
            state.packages.forEach(function (pkg) {
                Object.keys(pkg.items || {}).forEach(function (k) {
                    totals[k] = (totals[k] || 0) + (parseInt(pkg.items[k], 10) || 0);
                });
            });
            return Object.keys(totals).every(function (k) {
                return limits[k] !== undefined && totals[k] <= limits[k];
            });
        }

        function validateStep(targetStep) {
            clearError();
            if (targetStep === 2) {
                readPackagesFromForm();
                var okMass = state.packages.every(function (pkg) { return (pkg.mass || 0) > 0; });
                if (!okMass) {
                    showError('Unesite težinu za svaki paket.');
                    return false;
                }
                if (!validateAllocations()) {
                    showError('Raspodela stavki po paketima nije validna.');
                    return false;
                }
            }
            if (targetStep === 3) {
                readOptionsFromForm();
                if (!state.options.sender_location_id) {
                    showError('Izaberite lokaciju pošiljaoca.');
                    return false;
                }
                if (!state.options.content) {
                    showError('Sadržaj pošiljke je obavezan.');
                    return false;
                }
            }
            return true;
        }

        function renderSummary() {
            var totalMass = 0;
            var list = '<ul class="dex-summary-packages">';
            state.packages.forEach(function (pkg, idx) {
                totalMass += parseInt(pkg.mass, 10) || 0;
                var massKg = ((parseInt(pkg.mass, 10) || 0) / 1000).toFixed(2);
                var dims = (pkg.dim_x && pkg.dim_y && pkg.dim_z) ? (pkg.dim_x + '×' + pkg.dim_y + '×' + pkg.dim_z + ' cm') : '—';
                list += '<li><strong>PKG_' + (idx + 1) + '</strong> · ' + massKg + ' kg · ' + dims + '</li>';
            });
            list += '</ul>';

            var recipient = mb.isPackageShop && mb.destination ? esc(mb.destination) : 'Adresa kupca';
            var html = '<table class="widefat striped dex-summary-table"><tbody>' +
                '<tr><th>Broj paketa</th><td>' + state.packages.length + '</td></tr>' +
                '<tr><th>Ukupna masa</th><td>' + (totalMass / 1000).toFixed(2) + ' kg</td></tr>' +
                '<tr><th>Sadržaj pošiljke</th><td>' + esc(state.options.content) + '</td></tr>' +
                '<tr><th>Napomena</th><td>' + esc(state.options.note || '—') + '</td></tr>' +
                '<tr><th>Primalac</th><td>' + recipient + '</td></tr>' +
                '</tbody></table>' + list;
            $('#dex-step3-summary').html(html);
        }

        function showStep(n) {
            step = n;
            $('.dex-step').removeClass('is-active').filter('[data-step="' + n + '"]').addClass('is-active');
            $('.dex-step-panel').prop('hidden', true).filter('[data-step="' + n + '"]').prop('hidden', false);
            $('#dex-step-back').prop('hidden', n === 1);
            $('#dex-step-next').prop('hidden', n === 3);
            $('#dex-print-label').prop('hidden', n !== 3);
            if (n === 3) {
                renderSummary();
            }
        }

        function toPayload() {
            readPackagesFromForm();
            readOptionsFromForm();
            return {
                options: {
                    sender_location_id: state.options.sender_location_id,
                    delivery_type: state.options.delivery_type,
                    payment_type: state.options.payment_type,
                    return_doc: state.options.return_doc,
                    self_drop_off: state.options.self_drop_off,
                    content: state.options.content,
                    note: state.options.note
                },
                packages: state.packages.map(function (pkg) {
                    var items = [];
                    Object.keys(pkg.items || {}).forEach(function (k) {
                        items.push({ order_item_id: parseInt(k, 10), qty: parseInt(pkg.items[k], 10) || 0 });
                    });
                    return {
                        mass: parseInt(pkg.mass, 10) || 0,
                        dim_x: pkg.dim_x || null,
                        dim_y: pkg.dim_y || null,
                        dim_z: pkg.dim_z || null,
                        content: (pkg.content || '').trim(),
                        items: items
                    };
                })
            };
        }

        ensureAtLeastOnePackage();
        if (initialDraft && initialDraft.options && Array.isArray(initialDraft.packages) && initialDraft.packages.length) {
            state.options.sender_location_id = parseInt(initialDraft.options.sender_location_id, 10) || null;
            state.options.delivery_type = parseInt(initialDraft.options.delivery_type, 10) || state.options.delivery_type;
            state.options.payment_type = parseInt(initialDraft.options.payment_type, 10) || state.options.payment_type;
            state.options.return_doc = parseInt(initialDraft.options.return_doc, 10) || state.options.return_doc;
            state.options.self_drop_off = initialDraft.options.self_drop_off ? 1 : 0;
            state.options.content = String(initialDraft.options.content || '').trim();
            state.options.note = String(initialDraft.options.note || '').trim();
            state.packages = initialDraft.packages.map(function (pkg) {
                var items = {};
                if (pkg.items && typeof pkg.items === 'object') {
                    Object.keys(pkg.items).forEach(function (key) {
                        items[String(key)] = parseInt(pkg.items[key], 10) || 0;
                    });
                }
                return {
                    mass: parseInt(pkg.mass, 10) || 0,
                    dim_x: pkg.dim_x ? parseInt(pkg.dim_x, 10) : null,
                    dim_y: pkg.dim_y ? parseInt(pkg.dim_y, 10) : null,
                    dim_z: pkg.dim_z ? parseInt(pkg.dim_z, 10) : null,
                    content: String(pkg.content || '').trim(),
                    items: items
                };
            });
        }
        ensureAtLeastOnePackage();
        renderPackages();
        if (state.options.sender_location_id) {
            $('#dex-sender-location').val(String(state.options.sender_location_id));
        }
        $('#dex-delivery-type').val(String(state.options.delivery_type));
        $('#dex-payment-type').val(String(state.options.payment_type));
        $('#dex-return-doc').val(String(state.options.return_doc));
        $('#dex-self-drop-off').prop('checked', !!state.options.self_drop_off);
        $('#dex-content').val(state.options.content);
        $('#dex-note').val(state.options.note);
        showStep(1);

        $('#dex-add-package').on('click', function () {
            if (state.packages.length >= maxPackages) {
                showError('Dostignut je maksimalan broj paketa.');
                return;
            }
            state.packages.push({ mass: 0, dim_x: null, dim_y: null, dim_z: null, content: '', items: {} });
            renderPackages();
            clearError();
        });

        $('#dex-remove-package').on('click', function () {
            if (state.packages.length <= 1) {
                return;
            }
            state.packages.pop();
            renderPackages();
            clearError();
        });

        $('#dex-step-next').on('click', function () {
            if (!validateStep(step + 1)) {
                return;
            }
            showStep(step + 1);
        });

        $('#dex-step-back').on('click', function () {
            showStep(step - 1);
        });

        $('#dex-print-label').on('click', function () {
            if (!validateStep(3)) {
                return;
            }
            var printWin = window.open('', '_blank');
            if (!printWin) {
                setResult('Browser je blokirao pop-up prozor. Omogućite pop-up za admin stranicu.', true);
                return;
            }

            var payload = toPayload();
            var $btn = $(this);
            $btn.prop('disabled', true).text(mb.i18n.savingLocal || 'Čuvanje...');
            setResult('', false);

            $.post(mb.ajaxUrl, {
                action: 'dexpress_save_shipment_local',
                nonce: mb.nonceSaveLocal,
                order_id: mb.orderId,
                shipment_id: parseInt(mb.editShipmentId || 0, 10) || 0,
                draft: JSON.stringify(payload)
            }).done(function (resp) {
                if (!resp.success) {
                    try { printWin.close(); } catch (e) {}
                    setResult((resp.data && resp.data.message) || (mb.i18n.error || 'Greška.'), true);
                    return;
                }
                var labelUrl = resp.data && resp.data.label_url ? String(resp.data.label_url) : '';
                if (!openLabel(labelUrl, printWin)) {
                    try { printWin.close(); } catch (e2) {}
                    setResult('Neuspešno otvaranje nalepnice.', true);
                    return;
                }
                setResult((resp.data && resp.data.message) || 'Nalepnica je kreirana.', false);
                setTimeout(function () {
                    var cleanUrl = new URL(window.location.href);
                    cleanUrl.searchParams.delete('dexpress_edit_shipment');
                    window.location.href = cleanUrl.toString();
                }, 500);
            }).fail(function () {
                try { printWin.close(); } catch (e3) {}
                setResult(mb.i18n.error || 'Greška.', true);
            }).always(function () {
                $btn.prop('disabled', false).text('Štampaj nalepnicu');
            });
        });
    }

    if ($('.dex-state--wizard').length) {
        initWizardState();
    } else if ($('.dex-state--draft[data-state="pending_send"]').length) {
        initPendingSendState();
    } else if ($('.dex-state--created[data-state="created"]').length) {
        $('.dex-copy-track').on('click', function () {
            var code = String($(this).data('track') || '').trim();
            if (!code) {
                return;
            }
            var done = function () {
                setResult('Kod za praćenje je kopiran.', false);
            };
            var fallback = function () {
                var $tmp = $('<input type="text">').val(code).appendTo('body');
                $tmp.trigger('select');
                try {
                    document.execCommand('copy');
                    done();
                } catch (e) {
                    setResult('Kopiranje nije uspelo. Kopirajte kod ručno.', true);
                }
                $tmp.remove();
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done).catch(fallback);
            } else {
                fallback();
            }
        });
    }
}(jQuery));
