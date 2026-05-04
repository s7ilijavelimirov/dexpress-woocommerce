/* global dexpressMetabox, jQuery */
(function ($) {
    'use strict';

    var mb = dexpressMetabox;
    var MAX_PKG = parseInt(mb.maxPackages, 10) || 5;

    var state = {
        step:         1,
        packageCount: 1,
        packages:     [],
    };

    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }

    function attrEscape(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;');
    }

    function packageLineItemsHtml(pkgIdx, pkg) {
        var lines = mb.orderLineItems || [];
        if (!lines.length) {
            return '';
        }
        var items = pkg.items || {};
        var rows = '';
        var j;
        for (j = 0; j < lines.length; j++) {
            var li = lines[j];
            var sid = String(li.id);
            var qty = items[sid] !== undefined ? parseInt(items[sid], 10) || 0 : 0;
            rows +=
                '<tr>' +
                '<td>' + escapeHtml(li.name) + '</td>' +
                '<td style="width:72px;">' +
                '<input type="number" min="0" max="' + li.qty_max + '" class="small-text dexpress-pkg-item-qty" ' +
                'data-idx="' + pkgIdx + '" data-item-id="' + li.id + '" value="' + qty + '">' +
                '</td>' +
                '<td>/ ' + li.qty_max + '</td>' +
                '</tr>';
        }
        return (
            '<div class="dexpress-pkg-items" style="margin-top:8px;">' +
            '<div class="dexpress-field-label">Stavke u paketu (opciono)</div>' +
            '<table class="widefat" style="margin-top:4px;font-size:11px;">' +
            rows +
            '</table></div>'
        );
    }

    function validateLineAllocations() {
        var lines = mb.orderLineItems || [];
        if (!lines.length) {
            return true;
        }
        var maxById = {};
        var j;
        for (j = 0; j < lines.length; j++) {
            maxById[String(lines[j].id)] = lines[j].qty_max;
        }
        var totals = {};
        var i;
        var k;
        for (i = 0; i < state.packageCount; i++) {
            var pkg = state.packages[i];
            if (!pkg.items) {
                continue;
            }
            for (k in pkg.items) {
                if (!Object.prototype.hasOwnProperty.call(pkg.items, k)) {
                    continue;
                }
                var q = pkg.items[k];
                if (!maxById.hasOwnProperty(k)) {
                    showError('Nepoznata stavka u raspodeli.');
                    return false;
                }
                totals[k] = (totals[k] || 0) + q;
            }
        }
        for (k in maxById) {
            if (!Object.prototype.hasOwnProperty.call(maxById, k)) {
                continue;
            }
            if ((totals[k] || 0) > maxById[k]) {
                showError('Ukupna količina po stavci premašuje poručenu.');
                return false;
            }
        }
        return true;
    }

    function showStep(n) {
        state.step = n;

        $('.dexpress-wizard-step').hide();
        $('#dexpress-step-' + n).show();

        $('.dexpress-step-pill').each(function () {
            var s = parseInt($(this).data('step'), 10);
            $(this).toggleClass('is-done', s < n).toggleClass('is-active', s === n);
        });

        $('.dexpress-step-conn').each(function (i) {
            $(this).toggleClass('is-done', i < n - 1);
        });

        $('#dexpress-wizard-back').toggle(n > 1);

        if (n === 4) {
            $('#dexpress-wizard-next').hide();
            $('#dexpress-create-shipment-btn').show();
        } else {
            $('#dexpress-wizard-next').show();
            $('#dexpress-create-shipment-btn').hide();
        }

        hideError();
    }

    function showError(msg) {
        $('#dexpress-wizard-error').text(msg).show();
    }

    function hideError() {
        $('#dexpress-wizard-error').hide().text('');
    }

    $('#dexpress-pkg-minus').on('click', function () {
        var $i = $('#dexpress-pkg-count');
        var v  = parseInt($i.val(), 10) || 1;
        if (v > 1) {
            $i.val(v - 1);
        }
    });

    $('#dexpress-pkg-plus').on('click', function () {
        var $i = $('#dexpress-pkg-count');
        var v  = parseInt($i.val(), 10) || 1;
        if (v < MAX_PKG) {
            $i.val(v + 1);
        }
    });

    function renderPackages() {
        var $list = $('#dexpress-packages-list');
        $list.empty();

        for (var i = 0; i < state.packageCount; i++) {
            var pkg = state.packages[i] || {};
            var title = 'PKG_' + (i + 1);

            $list.append(
                '<div class="dexpress-pkg-card">' +
                    '<div class="dexpress-pkg-card-title">' +
                        '<span class="dashicons dashicons-archive" aria-hidden="true"></span> ' +
                        title +
                    '</div>' +
                    '<p class="dexpress-pkg-field">' +
                        '<label class="dexpress-field-label">' +
                            'Težina (g) <span class="dexpress-req">*</span>' +
                        '</label>' +
                        '<input type="number" class="widefat dexpress-pkg-weight" data-idx="' + i + '" ' +
                            'value="' + (pkg.weight || '') + '" min="1" placeholder="npr. 500">' +
                    '</p>' +
                    '<p class="dexpress-pkg-dim-hint">' +
                        '<span class="dashicons dashicons-image-crop" aria-hidden="true"></span> ' +
                        'Dimenzije (mm, opciono)' +
                    '</p>' +
                    '<div class="dexpress-pkg-dims">' +
                        '<div><label class="dexpress-dim-label">X</label>' +
                            '<input type="number" class="dexpress-pkg-length" data-idx="' + i + '" ' +
                                'value="' + (pkg.length || '') + '" min="0" placeholder="mm"></div>' +
                        '<div><label class="dexpress-dim-label">Y</label>' +
                            '<input type="number" class="dexpress-pkg-width" data-idx="' + i + '" ' +
                                'value="' + (pkg.width || '') + '" min="0" placeholder="mm"></div>' +
                        '<div><label class="dexpress-dim-label">Z</label>' +
                            '<input type="number" class="dexpress-pkg-height" data-idx="' + i + '" ' +
                                'value="' + (pkg.height || '') + '" min="0" placeholder="mm"></div>' +
                    '</div>' +
                    '<p class="dexpress-pkg-field">' +
                        '<label class="dexpress-field-label">Sadržaj paketa (opciono, max 50)</label>' +
                        '<input type="text" maxlength="50" class="widefat dexpress-pkg-content" data-idx="' + i + '" ' +
                            'value="' + attrEscape(pkg.content || '') + '">' +
                    '</p>' +
                    packageLineItemsHtml(i, pkg) +
                '</div>'
            );
        }
    }

    function savePackages() {
        state.packages = [];
        var i;
        var elContent;
        for (i = 0; i < state.packageCount; i++) {
            elContent = $('.dexpress-pkg-content[data-idx="' + i + '"]').val() || '';
            var itemsObj = {};
            $('.dexpress-pkg-item-qty[data-idx="' + i + '"]').each(function () {
                var q = parseInt($(this).val(), 10) || 0;
                if (q > 0) {
                    itemsObj[String($(this).data('item-id'))] = q;
                }
            });
            state.packages.push({
                weight:  parseInt($('.dexpress-pkg-weight[data-idx="' + i + '"]').val(), 10) || 0,
                length:  parseInt($('.dexpress-pkg-length[data-idx="' + i + '"]').val(), 10) || 0,
                width:   parseInt($('.dexpress-pkg-width[data-idx="' + i + '"]').val(), 10) || 0,
                height:  parseInt($('.dexpress-pkg-height[data-idx="' + i + '"]').val(), 10) || 0,
                content: elContent.trim(),
                items:   itemsObj,
            });
        }
    }

    function totalWeight() {
        var sum = 0;
        for (var i = 0; i < state.packages.length; i++) {
            sum += state.packages[i].weight || 0;
        }
        return sum;
    }

    function dimLabel(p) {
        var parts = [];
        if (p.length > 0) {
            parts.push('X=' + p.length);
        }
        if (p.width > 0) {
            parts.push('Y=' + p.width);
        }
        if (p.height > 0) {
            parts.push('Z=' + p.height);
        }
        return parts.length ? parts.join(', ') + ' mm' : '—';
    }

    function buildSummary() {
        var tw      = totalWeight();
        var twKg    = (tw / 1000).toFixed(2);
        var cod     = parseFloat(mb.codAmount || '0');

        var row = function (label, value) {
            return '<tr>' +
                '<td class="dexpress-sum-label">' + label + '</td>' +
                '<td class="dexpress-sum-val">' + value + '</td>' +
                '</tr>';
        };

        var html = '<table class="dexpress-summary-table">';
        html += row('Broj paketa:', String(state.packageCount));
        html += row('Ukupna težina:', tw + ' g (' + twKg + ' kg)');

        var pi;
        for (pi = 0; pi < state.packages.length; pi++) {
            var p = state.packages[pi];
            var sumLine = (p.weight || 0) + ' g, ' + dimLabel(p);
            if (p.content) {
                sumLine += '<br><em>' + $('<span>').text(p.content).html() + '</em>';
            }
            if (p.items && mb.orderLineItems && mb.orderLineItems.length) {
                var bits = [];
                var oid;
                for (oid in p.items) {
                    if (!Object.prototype.hasOwnProperty.call(p.items, oid)) {
                        continue;
                    }
                    var qItem = p.items[oid];
                    var nameLookup = '';
                    var lx;
                    for (lx = 0; lx < mb.orderLineItems.length; lx++) {
                        if (String(mb.orderLineItems[lx].id) === String(oid)) {
                            nameLookup = mb.orderLineItems[lx].name;
                            break;
                        }
                    }
                    bits.push($('<span>').text(nameLookup || oid).html() + ' × ' + qItem);
                }
                if (bits.length) {
                    sumLine += '<br>' + bits.join(', ');
                }
            }
            html += row('PKG_' + (pi + 1), sumLine);
        }

        if (cod > 0) {
            html += row('Otkupnina (COD):', cod.toLocaleString('sr-RS', {minimumFractionDigits: 2}) + ' RSD');
        }

        html += row('Sadržaj:', $('<span>').text($('#dexpress-content').val().trim()).html());
        html += row('Tip dostave:', $('#dexpress-delivery-type option:selected').text());
        html += row('Način plaćanja:', $('#dexpress-payment-type option:selected').text());
        html += row('Povraćaj dok.:', $('#dexpress-return-doc option:selected').text());
        html += row('Self drop-off:', $('#dexpress-self-drop-off').is(':checked') ? 'Da' : 'Ne');

        var note = $('#dexpress-note').val().trim();
        if (note) {
            html += row('Napomena:', $('<span>').text(note).html());
        }

        html += '</table>';
        $('#dexpress-summary').html(html);
    }

    function validateStep(step) {
        if (step === 1) {
            var count = parseInt($('#dexpress-pkg-count').val(), 10);
            if (!count || count < 1 || count > MAX_PKG) {
                showError('Unesite broj paketa od 1 do ' + MAX_PKG + '.');
                return false;
            }
            return true;
        }

        if (step === 2) {
            savePackages();
            for (var i = 0; i < state.packageCount; i++) {
                if (!state.packages[i].weight || state.packages[i].weight < 1) {
                    showError('Unesite težinu (g) za paket ' + (i + 1) + '.');
                    $('.dexpress-pkg-weight[data-idx="' + i + '"]').trigger('focus');
                    return false;
                }
            }
            if (!validateLineAllocations()) {
                return false;
            }
            return true;
        }

        if (step === 3) {
            if (!$('#dexpress-content').val().trim()) {
                showError('Sadržaj pošiljke je obavezan.');
                $('#dexpress-content').trigger('focus');
                return false;
            }
            return true;
        }

        return true;
    }

    $('#dexpress-wizard-next').on('click', function () {
        if (!validateStep(state.step)) {
            return;
        }

        if (state.step === 1) {
            state.packageCount = parseInt($('#dexpress-pkg-count').val(), 10) || 1;
            renderPackages();
            showStep(2);
        } else if (state.step === 2) {
            showStep(3);
        } else if (state.step === 3) {
            buildSummary();
            showStep(4);
        }
    });

    $('#dexpress-wizard-back').on('click', function () {
        if (state.step > 1) {
            showStep(state.step - 1);
        }
    });

    $('#dexpress-create-shipment-btn').on('click', function () {
        savePackages();
        var $btn    = $(this);
        var $result = $('#dexpress-create-result');
        var tw      = totalWeight();

        $result.text('').css('color', '');
        $btn.prop('disabled', true).text(mb.i18n.creating);

        var pkgPayload = state.packages.map(function (p) {
            var itemsArr = [];
            if (p.items) {
                var oid;
                for (oid in p.items) {
                    if (!Object.prototype.hasOwnProperty.call(p.items, oid)) {
                        continue;
                    }
                    itemsArr.push({
                        order_item_id: parseInt(oid, 10),
                        qty:           p.items[oid],
                    });
                }
            }
            return {
                mass:    p.weight || 0,
                dim_x:   p.length > 0 ? p.length : null,
                dim_y:   p.width  > 0 ? p.width  : null,
                dim_z:   p.height > 0 ? p.height : null,
                content: (p.content || '').trim(),
                items:   itemsArr,
            };
        });

        $.post(mb.ajaxUrl, {
            action:             'dexpress_create_shipment',
            nonce:              mb.nonce,
            order_id:           mb.orderId,
            sender_location_id: $('#dexpress-sender-location').val(),
            delivery_type:      $('#dexpress-delivery-type').val(),
            payment_type:       $('#dexpress-payment-type').val(),
            return_doc:         $('#dexpress-return-doc').val(),
            self_drop_off:      $('#dexpress-self-drop-off').is(':checked') ? 1 : 0,
            content:            $('#dexpress-content').val().trim(),
            note:               $('#dexpress-note').val().trim(),
            total_mass_grams:   tw,
            packages:           JSON.stringify(pkgPayload),
        })
        .done(function (response) {
            if (response.success) {
                var color = response.data.test_mode ? '#b45309' : '#2a6a2a';
                var codes = Array.isArray(response.data.tracking_codes) && response.data.tracking_codes.length
                    ? response.data.tracking_codes
                    : [response.data.tracking_code || ''];
                codes = codes.filter(function (c) { return c; });
                var codesPart = codes.length === 0
                    ? ''
                    : (codes.length === 1
                        ? (' Kod: ' + codes[0])
                        : (' Kodovi paketa (' + codes.length + '): ' + codes.join(', ')));
                $result
                    .text(response.data.message + codesPart)
                    .css('color', color);
                $btn.prop('disabled', true);
                setTimeout(function () { window.location.reload(); }, 2200);
            } else {
                $result.text(response.data.message || mb.i18n.error).css('color', '#b32d2e');
                $btn.prop('disabled', false).text(mb.i18n.create);
            }
        })
        .fail(function () {
            $result.text(mb.i18n.error).css('color', '#b32d2e');
            $btn.prop('disabled', false).text(mb.i18n.create);
        });
    });

    showStep(1);

}(jQuery));
