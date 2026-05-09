/**
 * D Express — Bulk Shipment Wizard JS
 *
 * Korak 1: Globalna podešavanja (lokacija, masa, dimenzije, sadržaj)
 * Korak 2: Pregled i izmena po narudžbini (editabilna tabela)
 * Korak 3: Sekvencijalni AJAX save → prikaz rezultata → AJAX send
 */
(function ($) {
    'use strict';

    var cfg      = window.dexpressBulk || {};
    var ajax     = cfg.ajaxUrl || '';
    var nonces   = cfg.nonces || {};
    var orders   = cfg.orders || [];      // [{id, number, customer, total, edit_url}]
    var i18n     = cfg.i18n || {};
    var labelBase = cfg.labelBase || '';

    var currentStep = 1;

    // Stanje per-narudžbina: dimenzije/masa/sadržaj/napomena (popunjava se u step 2 iz defaulta i može se menjati).
    var orderState = {};  // keyed by orderId

    // Rezultati save/send faze.
    var saveResults = {};  // keyed by orderId: {shipment_id, tracking_code, error, sent, sendError}

    // ── Stepper ────────────────────────────────────────────────────────────────
    function setStep(n) {
        currentStep = n;
        $('.dex-bulk-step').each(function () {
            var s = parseInt($(this).data('step'), 10);
            $(this).removeClass('dex-bulk-step--active dex-bulk-step--done');
            if (s === n) { $(this).addClass('dex-bulk-step--active'); }
            else if (s < n) { $(this).addClass('dex-bulk-step--done'); }
        });
        $('#dex-bulk-step1, #dex-bulk-step2, #dex-bulk-step3').hide();
        $('#dex-bulk-step' + n).show();
        window.scrollTo(0, 0);
    }

    // ── Čitanje globalnih defaulta iz Step 1 forme ─────────────────────────────
    function readDefaults() {
        return {
            sender_location_id: $('#dex-bulk-location').val(),
            delivery_type:      $('#dex-bulk-delivery').val(),
            payment_type:       $('#dex-bulk-payment').val(),
            return_doc:         $('#dex-bulk-returndoc').val(),
            self_drop_off:      $('#dex-bulk-selfdrop').is(':checked') ? '1' : '0',
            weight_kg:          $('#dex-bulk-weight').val().replace(',', '.'),
            dim_x:              $('#dex-bulk-dx').val().replace(',', '.'),
            dim_y:              $('#dex-bulk-dy').val().replace(',', '.'),
            dim_z:              $('#dex-bulk-dz').val().replace(',', '.'),
            content:            $('#dex-bulk-content').val(),
            note:               $('#dex-bulk-note').val(),
        };
    }

    // ── Validacija Koraka 1 ────────────────────────────────────────────────────
    function validateStep1() {
        var d = readDefaults();
        if (!d.sender_location_id || d.sender_location_id === '0') {
            alert('Lokacija pošiljaoca je obavezna.');
            return false;
        }
        if (!d.weight_kg || parseFloat(d.weight_kg) <= 0) {
            alert(i18n.step1Valid || 'Unesite masu i sadržaj pre prelaska na pregled.');
            return false;
        }
        if (!d.content.trim()) {
            alert(i18n.contentReq || 'Sadržaj je obavezan za sve narudžbine.');
            return false;
        }
        return true;
    }

    // ── Inicijalizuj stanje narudžbina iz defaulta ─────────────────────────────
    function initOrderState(defaults) {
        orders.forEach(function (o) {
            orderState[o.id] = {
                weight_kg: defaults.weight_kg,
                dim_x:     defaults.dim_x,
                dim_y:     defaults.dim_y,
                dim_z:     defaults.dim_z,
                content:   defaults.content,
                note:      defaults.note,
            };
        });
    }

    // ── Primeni defaulte na sva polja u Step 2 tabeli ─────────────────────────
    function applyDefaultsToAll() {
        var d = readDefaults();
        orders.forEach(function (o) {
            var id = o.id;
            $('#dex-row-weight-' + id).val(d.weight_kg);
            $('#dex-row-dx-' + id).val(d.dim_x);
            $('#dex-row-dy-' + id).val(d.dim_y);
            $('#dex-row-dz-' + id).val(d.dim_z);
            $('#dex-row-content-' + id).val(d.content);
            $('#dex-row-note-' + id).val(d.note);
            orderState[id] = {
                weight_kg: d.weight_kg,
                dim_x: d.dim_x,
                dim_y: d.dim_y,
                dim_z: d.dim_z,
                content: d.content,
                note: d.note,
            };
        });
    }

    // ── Rendera Step 2 tabelu ─────────────────────────────────────────────────
    function renderOrdersTable() {
        if (!orders.length) {
            $('#dex-bulk-orders-table-wrap').html('<p class="description">Nema narudžbina.</p>');
            return;
        }

        var rows = orders.map(function (o) {
            var s = orderState[o.id] || {};
            return '<tr>'
                + '<td><a href="' + escHtml(o.edit_url) + '" target="_blank">#' + escHtml(o.number) + '</a></td>'
                + '<td>' + escHtml(o.customer) + '</td>'
                + '<td>' + escHtml(o.total) + '</td>'
                + '<td><input type="number" id="dex-row-weight-' + o.id + '" class="small-text dex-row-field" data-id="' + o.id + '" data-field="weight_kg" min="0.001" step="0.001" value="' + escAttr(s.weight_kg || '') + '" /></td>'
                + '<td class="dex-col-dims">'
                    + '<input type="number" id="dex-row-dx-' + o.id + '" class="tiny-text dex-row-field dex-dim-input" data-id="' + o.id + '" data-field="dim_x" min="0" step="0.1" value="' + escAttr(s.dim_x || '') + '" placeholder="D" />'
                    + '×<input type="number" id="dex-row-dy-' + o.id + '" class="tiny-text dex-row-field dex-dim-input" data-id="' + o.id + '" data-field="dim_y" min="0" step="0.1" value="' + escAttr(s.dim_y || '') + '" placeholder="Š" />'
                    + '×<input type="number" id="dex-row-dz-' + o.id + '" class="tiny-text dex-row-field dex-dim-input" data-id="' + o.id + '" data-field="dim_z" min="0" step="0.1" value="' + escAttr(s.dim_z || '') + '" placeholder="V" />'
                + '</td>'
                + '<td><input type="text" id="dex-row-content-' + o.id + '" class="dex-row-field dex-text-medium" data-id="' + o.id + '" data-field="content" maxlength="50" value="' + escAttr(s.content || '') + '" /></td>'
                + '<td><input type="text" id="dex-row-note-' + o.id + '" class="dex-row-field dex-text-medium" data-id="' + o.id + '" data-field="note" maxlength="150" value="' + escAttr(s.note || '') + '" /></td>'
                + '</tr>';
        });

        var html = '<table class="dex-bulk-orders-table">'
            + '<thead><tr>'
            + '<th>' + i18n.order + '</th>'
            + '<th>' + i18n.customer + '</th>'
            + '<th>' + i18n.total + '</th>'
            + '<th>' + i18n.weight + '</th>'
            + '<th>' + i18n.dims + '</th>'
            + '<th>' + i18n.content + '</th>'
            + '<th>' + i18n.note + '</th>'
            + '</tr></thead>'
            + '<tbody>' + rows.join('') + '</tbody>'
            + '</table>';

        $('#dex-bulk-orders-table-wrap').html(html);
    }

    // ── Live sync Step 2 polja u orderState ───────────────────────────────────
    $(document).on('change input', '.dex-row-field', function () {
        var id    = $(this).data('id');
        var field = $(this).data('field');
        if (!orderState[id]) { orderState[id] = {}; }
        orderState[id][field] = $(this).val();
    });

    // ── Validacija Step 2 ──────────────────────────────────────────────────────
    function validateStep2() {
        var valid = true;
        orders.forEach(function (o) {
            var s = orderState[o.id] || {};
            if (!s.weight_kg || parseFloat(s.weight_kg) <= 0) { valid = false; }
            if (!s.content || !s.content.trim()) { valid = false; }
        });

        if (!valid) {
            alert((i18n.weightReq || 'Masa mora biti > 0.') + '\n' + (i18n.contentReq || 'Sadržaj je obavezan.'));
        }
        return valid;
    }

    // ── STEP 3: sekvencijalni save ─────────────────────────────────────────────
    function startSavePhase() {
        setStep(3);
        saveResults = {};
        var defaults = readDefaults();
        var total    = orders.length;
        var done     = 0;

        $('#dex-bulk-progress-wrap').show();
        $('#dex-bulk-results-wrap').hide();

        function updateProgress() {
            var pct = total > 0 ? Math.round((done / total) * 100) : 0;
            $('#dex-bulk-progress-fill').css('width', pct + '%');
            $('#dex-bulk-progress-label').text(
                i18n.saving + ' (' + done + '/' + total + ')'
            );
        }

        updateProgress();

        function saveNext(idx) {
            if (idx >= orders.length) {
                // Sve obrađeno — prikaži rezultate.
                $('#dex-bulk-progress-wrap').hide();
                renderResultsTable();
                $('#dex-bulk-results-wrap').show();
                return;
            }

            var o = orders[idx];
            var s = orderState[o.id] || {};

            $.post(ajax, {
                action:            'dexpress_bulk_save_shipment',
                nonce:             nonces.bulkSave,
                order_id:          o.id,
                sender_location_id: defaults.sender_location_id,
                delivery_type:     defaults.delivery_type,
                payment_type:      defaults.payment_type,
                return_doc:        defaults.return_doc,
                self_drop_off:     defaults.self_drop_off,
                content:           s.content || defaults.content,
                note:              s.note || defaults.note,
                weight_kg:         s.weight_kg || defaults.weight_kg,
                dim_x:             s.dim_x || defaults.dim_x,
                dim_y:             s.dim_y || defaults.dim_y,
                dim_z:             s.dim_z || defaults.dim_z,
            })
            .done(function (res) {
                if (res.success) {
                    saveResults[o.id] = {
                        shipment_id:   res.data.shipment_id,
                        tracking_code: res.data.tracking_code,
                        label_url:     res.data.label_url || null,
                        error:         null,
                        sent:          false,
                        sendError:     null,
                    };
                } else {
                    saveResults[o.id] = {
                        shipment_id:   null,
                        tracking_code: null,
                        label_url:     null,
                        error:         res.data.message || 'Greška',
                        sent:          false,
                        sendError:     null,
                    };
                }
            })
            .fail(function () {
                saveResults[o.id] = {
                    shipment_id: null, tracking_code: null,
                    error: 'Greška pri slanju zahteva.', sent: false, sendError: null,
                };
            })
            .always(function () {
                done++;
                updateProgress();
                saveNext(idx + 1);
            });
        }

        saveNext(0);
    }

    // ── Rendera rezultatnu tabelu (posle save faze) ────────────────────────────
    function renderResultsTable() {
        var savedIds = [];  // shipment IDs za bulk print

        var rows = orders.map(function (o) {
            var r = saveResults[o.id] || {};
            var statusHtml, actionsHtml = '';

            if (r.error) {
                statusHtml = '<span class="dex-row-status dex-row-status--error">' + escHtml(r.error) + '</span>';
            } else if (r.sent) {
                statusHtml = '<span class="dex-row-status dex-row-status--sent">✓ ' + i18n.sent + '</span>';
                savedIds.push(r.shipment_id);
            } else if (r.sendError) {
                statusHtml = '<span class="dex-row-status dex-row-status--error">' + escHtml(r.sendError) + '</span>'
                    + ' <button type="button" class="button button-small dex-retry-send-btn" data-order-id="' + o.id + '" data-shipment-id="' + r.shipment_id + '">' + i18n.retry + '</button>';
                savedIds.push(r.shipment_id);
            } else {
                // saved, pending send
                statusHtml = '<span class="dex-row-status dex-row-status--saved">✓ ' + i18n.saved + '</span>';
                savedIds.push(r.shipment_id);
            }

            if (r.label_url) {
                actionsHtml = '<a href="' + escHtml(r.label_url) + '" target="_blank" class="button button-small">' + i18n.printLabel + '</a>';
            }

            return '<tr>'
                + '<td><a href="' + escHtml(o.edit_url) + '" target="_blank">#' + escHtml(o.number) + '</a></td>'
                + '<td>' + escHtml(o.customer) + '</td>'
                + '<td><code>' + escHtml(r.tracking_code || '—') + '</code></td>'
                + '<td>' + statusHtml + '</td>'
                + '<td>' + actionsHtml + '</td>'
                + '</tr>';
        });

        var html = '<table class="dex-bulk-results-table">'
            + '<thead><tr>'
            + '<th>' + i18n.order + '</th>'
            + '<th>' + i18n.customer + '</th>'
            + '<th>' + i18n.trackingCode + '</th>'
            + '<th>' + i18n.status + '</th>'
            + '<th></th>'
            + '</tr></thead>'
            + '<tbody>' + rows.join('') + '</tbody>'
            + '</table>';

        $('#dex-bulk-results-table-wrap').html(html);

        // Prikaži "Štampaj sve" samo ako ima sačuvanih pošiljaka.
        if (savedIds.length > 0) {
            $('#dex-bulk-print-all').show().data('ids', savedIds);
            $('#dex-bulk-send-all').show();
        } else {
            $('#dex-bulk-print-all').hide();
            $('#dex-bulk-send-all').hide();
        }

        $('#dex-bulk-print-actions').show();
    }

    // ── Bulk print (otvori novi tab sa svim nalepnicama) ──────────────────────
    $('#dex-bulk-print-all').on('click', function () {
        var ids = $(this).data('ids') || [];
        if (!ids.length) { return; }
        var url = labelBase + '&shipment_ids=' + ids.join(',') + '&nonce=' + encodeURIComponent(nonces.bulkPrint);
        window.open(url, '_blank');
    });

    // ── Pošalji sve u D-Express ────────────────────────────────────────────────
    $('#dex-bulk-send-all').on('click', function () {
        if (!window.confirm(i18n.confirmSend || 'Poslati u D-Express?')) { return; }
        $(this).prop('disabled', true);

        var toSend = orders.filter(function (o) {
            var r = saveResults[o.id];
            return r && r.shipment_id && !r.sent && !r.sendError;
        });

        if (!toSend.length) { return; }

        function sendNext(idx) {
            if (idx >= toSend.length) {
                renderResultsTable();
                renderFinalSummary();
                $('#dex-bulk-send-all').prop('disabled', false);
                return;
            }

            var o  = toSend[idx];
            var r  = saveResults[o.id];
            var $statusCell = $('[data-order-id="' + o.id + '"]').closest('tr').find('td:nth-child(4)');
            $statusCell.html('<span class="dex-row-status dex-row-status--sending">' + i18n.sending + '</span>');

            $.post(ajax, {
                action:      'dexpress_bulk_send_shipment',
                nonce:       nonces.bulkSend,
                shipment_id: r.shipment_id,
            })
            .done(function (res) {
                if (res.success) {
                    saveResults[o.id].sent      = true;
                    saveResults[o.id].sendError = null;
                } else {
                    saveResults[o.id].sendError = res.data.message || 'Greška';
                }
            })
            .fail(function () {
                saveResults[o.id].sendError = 'Greška pri slanju zahteva.';
            })
            .always(function () { sendNext(idx + 1); });
        }

        sendNext(0);
    });

    // ── Retry send (delegirano) ────────────────────────────────────────────────
    $(document).on('click', '.dex-retry-send-btn', function () {
        var orderId    = $(this).data('order-id');
        var shipmentId = $(this).data('shipment-id');

        $.post(ajax, {
            action:      'dexpress_bulk_send_shipment',
            nonce:       nonces.bulkSend,
            shipment_id: shipmentId,
        })
        .done(function (res) {
            if (res.success) {
                saveResults[orderId].sent      = true;
                saveResults[orderId].sendError = null;
            } else {
                saveResults[orderId].sendError = res.data.message || 'Greška';
            }
            renderResultsTable();
        })
        .fail(function () {
            saveResults[orderId].sendError = 'Greška pri slanju zahteva.';
            renderResultsTable();
        });
    });

    // ── Profile kartica klik (Step 1) ─────────────────────────────────────────
    $(document).on('click', '.dex-profile-card', function () {
        $('.dex-profile-card').removeClass('dex-profile-card--active');
        $(this).addClass('dex-profile-card--active');

        var w = $(this).data('weight-kg');
        var x = $(this).data('dim-x');
        var y = $(this).data('dim-y');
        var z = $(this).data('dim-z');
        var c = $(this).data('content');

        if (w) { $('#dex-bulk-weight').val(w); }
        if (x) { $('#dex-bulk-dx').val(x); }
        if (y) { $('#dex-bulk-dy').val(y); }
        if (z) { $('#dex-bulk-dz').val(z); }
        if (c) { $('#dex-bulk-content').val(c); }
    });

    // ── Navigacija ─────────────────────────────────────────────────────────────
    $('#dex-bulk-step1-next').on('click', function () {
        if (!validateStep1()) { return; }
        initOrderState(readDefaults());
        renderOrdersTable();
        setStep(2);
    });

    $('#dex-bulk-step2-back').on('click', function () { setStep(1); });

    $('#dex-bulk-reset-defaults').on('click', function () { applyDefaultsToAll(); });

    $('#dex-bulk-step2-next').on('click', function () {
        if (!validateStep2()) { return; }
        startSavePhase();
    });

    // ── Finalna sažetak kartica (posle send faze) ─────────────────────────────
    function renderFinalSummary() {
        $('#dex-bulk-final-summary').remove();

        var sentCount    = 0;
        var sendErrCount = 0;
        var saveErrCount = 0;
        var trackingCodes = [];
        var savedIds      = [];

        orders.forEach(function (o) {
            var r = saveResults[o.id] || {};
            if (r.error) {
                saveErrCount++;
            } else if (r.sent) {
                sentCount++;
                if (r.tracking_code) { trackingCodes.push(r.tracking_code); }
                if (r.shipment_id)   { savedIds.push(r.shipment_id); }
            } else if (r.sendError) {
                sendErrCount++;
                if (r.shipment_id)   { savedIds.push(r.shipment_id); }
                if (r.tracking_code) { trackingCodes.push(r.tracking_code); }
            }
        });

        var failCount    = sendErrCount + saveErrCount;
        var totalOrders  = sentCount + failCount;
        var isAllSuccess = failCount === 0 && sentCount > 0;

        var headerClass = isAllSuccess
            ? 'dex-bulk-summary__header dex-bulk-summary__header--success'
            : 'dex-bulk-summary__header dex-bulk-summary__header--partial';

        var headerText = isAllSuccess
            ? '✓ ' + (i18n.allSent || 'Sve pošiljke su uspešno poslate u D-Express')
            : sentCount + ' ' + (i18n.partialSent || 'pošiljaka poslato') + ', '
                + failCount + ' ' + (i18n.partialFailed || 'nije uspelo');

        var trackingBlock = '';
        if (trackingCodes.length > 0) {
            var rows = Math.min(trackingCodes.length, 5);
            trackingBlock = '<div class="dex-bulk-summary__section">'
                + '<strong>' + escHtml(i18n.trackingCodesTitle || 'Kodovi pošiljaka') + ':</strong>'
                + '<textarea class="dex-bulk-tracking-codes" readonly rows="' + rows + '">'
                + escHtml(trackingCodes.join('\n'))
                + '</textarea>'
                + '<button type="button" class="button button-small" id="dex-copy-tracking">'
                + escHtml(i18n.copyTracking || 'Kopiraj kodove')
                + '</button>'
                + '</div>';
        }

        var printBtn = '';
        if (savedIds.length > 0) {
            var printUrl = labelBase + '&shipment_ids=' + savedIds.join(',')
                + '&nonce=' + encodeURIComponent(nonces.bulkPrint);
            printBtn = '<a href="' + escHtml(printUrl) + '" target="_blank" class="button button-primary">'
                + escHtml(i18n.printAllLabels || 'Štampaj sve nalepnice') + '</a>';
        }

        var backBtn = '<a href="' + escHtml(cfg.shipmentsUrl || '') + '" class="button">'
            + escHtml(i18n.backToShipments || 'Povratak na pošiljke') + '</a>';

        var html = '<div class="dex-bulk-summary" id="dex-bulk-final-summary">'
            + '<div class="' + headerClass + '">' + headerText + '</div>'
            + '<div class="dex-bulk-summary__body">'
            + '<div class="dex-bulk-summary__stats">'
            + '<span class="dex-bulk-summary__stat dex-bulk-summary__stat--sent">✓ ' + sentCount + ' ' + escHtml(i18n.sent || 'poslato') + '</span>'
            + (failCount > 0
                ? '<span class="dex-bulk-summary__stat dex-bulk-summary__stat--error">✗ ' + failCount + ' ' + escHtml(i18n.partialFailed || 'nije uspelo') + '</span>'
                : '')
            + '</div>'
            + trackingBlock
            + '<div class="dex-bulk-summary__actions">'
            + printBtn + ' ' + backBtn
            + '</div>'
            + '</div>'
            + '</div>';

        $('#dex-bulk-results-wrap').append(html);

        $(document).off('click.dexCopy').on('click.dexCopy', '#dex-copy-tracking', function () {
            var $btn  = $(this);
            var codes = trackingCodes.join('\n');
            var label = i18n.copyTracking || 'Kopiraj kodove';
            var done  = i18n.copied || 'Kopirano!';

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(codes).then(function () {
                    $btn.text(done);
                    setTimeout(function () { $btn.text(label); }, 2000);
                });
            } else {
                $('.dex-bulk-tracking-codes').trigger('select');
                document.execCommand('copy');
                $btn.text(done);
                setTimeout(function () { $btn.text(label); }, 2000);
            }
        });
    }

    // ── Utils ──────────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escAttr(str) { return escHtml(str); }

    // ── Init ───────────────────────────────────────────────────────────────────
    setStep(1);

})(jQuery);
