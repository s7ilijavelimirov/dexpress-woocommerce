/* global dexpressMetabox, jQuery */
(function ($) {
    'use strict';

    var mb = window.dexpressMetabox || {};
    var $root = $('#dex-mb-root');
    if (!$root.length) { return; }

    // ── Utilities ─────────────────────────────────────────────────

    function esc(s) { return $('<div/>').text(String(s || '')).html(); }

    function setMsg(msg, type) {
        $('#dex-mb-msg')
            .text(msg || '')
            .attr('class', 'dex-mb-msg' + (type ? ' is-' + type : ''));
    }

    function setWizardError(msg) {
        var $e = $('#dex-mb-wizard-error');
        if (msg) { $e.text(msg).prop('hidden', false); }
        else      { $e.text('').prop('hidden', true); }
    }

    // ── Package state ─────────────────────────────────────────────

    var pkgs = [];

    function defaultPkg() {
        return { mass: 500, dim_x: null, dim_y: null, dim_z: null, content: '', items: {} };
    }

    function addPkg() { pkgs.push(defaultPkg()); renderPkgs(); }

    function removePkg(idx) {
        if (pkgs.length > 1) { pkgs.splice(idx, 1); renderPkgs(); }
    }

    // Build item rows (always open — no toggle)
    function itemsBlock(idx, pkg) {
        var lines = mb.orderLineItems || [];
        if (!lines.length) { return ''; }

        var rows = '';
        lines.forEach(function (line) {
            var key = String(line.id);
            var qty = (pkg.items && pkg.items[key]) ? parseInt(pkg.items[key], 10) : 0;
            var img = line.image_url
                ? '<img src="' + esc(line.image_url) + '" width="40" height="40" class="dex-mb-item-img" alt="">'
                : '<span class="dex-mb-item-img dex-mb-item-img--placeholder dashicons dashicons-cart"></span>';
            rows += '<div class="dex-mb-item-row">'
                + '<div class="dex-mb-item-thumb">' + img + '</div>'
                + '<div class="dex-mb-item-info">'
                +   '<span class="dex-mb-item-title">' + esc(line.name) + '</span>'
                +   (line.category ? '<span class="dex-mb-item-cat">' + esc(line.category) + '</span>' : '')
                + '</div>'
                + '<div class="dex-mb-item-right">'
                +   '<span class="dex-mb-item-price">' + esc(line.price_display || '') + '</span>'
                +   '<div class="dex-mb-qty-ctrl">'
                +     '<button type="button" class="dex-mb-qty-btn dex-mb-qty-minus" data-pkg="' + idx + '" data-item="' + key + '">−</button>'
                +     '<input type="number" min="0" max="' + line.qty_max + '" value="' + qty
                +       '" class="dex-mb-item-qty" data-pkg="' + idx + '" data-item="' + key + '">'
                +     '<button type="button" class="dex-mb-qty-btn dex-mb-qty-plus" data-pkg="' + idx + '" data-item="' + key + '" data-max="' + line.qty_max + '">+</button>'
                +   '</div>'
                + '</div>'
                + '</div>';
        });

        return '<div class="dex-mb-items-block">'
            + '<div class="dex-mb-items-block__head">'
            + '<span class="dashicons dashicons-list-view"></span> Raspodela stavki'
            + '</div>'
            + '<div class="dex-mb-items-list">' + rows + '</div>'
            + '</div>';
    }

    function renderPkgs() {
        var html = '';
        pkgs.forEach(function (pkg, idx) {
            var massVal = (pkg.mass || 0) > 0 ? (pkg.mass / 1000).toFixed(3).replace(/\.?0+$/, '') : '';
            html += '<div class="dex-mb-pkg-card" data-pkg="' + idx + '">'
                + '<div class="dex-mb-pkg-card__head">'
                + '<div class="dex-mb-pkg-icon"><span class="dashicons dashicons-archive"></span></div>'
                + '<div class="dex-mb-pkg-card__title"><strong>Paket ' + (idx + 1) + '</strong></div>'
                + (pkgs.length > 1
                    ? '<button type="button" class="dex-mb-pkg-remove" data-idx="' + idx + '">'
                    + '<span class="dashicons dashicons-trash"></span> Ukloni'
                    + '</button>'
                    : '')
                + '</div>'
                + '<div class="dex-mb-pkg-card__body">'

                // 2-col row: weight (left) + dimensions (right)
                + '<div class="dex-mb-pkg-fields-row">'

                // Weight
                + '<div class="dex-mb-field">'
                + '<label class="dex-mb-field__label">Težina <span class="dex-mb-req">*</span></label>'
                + '<div class="dex-mb-input-group">'
                + '<input type="number" min="0.001" step="0.001" class="dex-mb-input dex-mb-input--sm dex-mb-pkg-mass" data-pkg="' + idx + '" value="' + massVal + '" placeholder="0.500">'
                + '<span class="dex-mb-input-group__suffix">kg</span>'
                + '</div></div>'

                // Dimensions (always visible, no toggle)
                + '<div class="dex-mb-field">'
                + '<label class="dex-mb-field__label">Dimenzije kutije <span class="dex-mb-field__optional">(opciono)</span></label>'
                + '<div class="dex-mb-dims-row">'
                + '<div class="dex-mb-input-group"><input type="number" min="0" class="dex-mb-input dex-mb-input--sm dex-mb-pkg-dx" data-pkg="' + idx + '" value="' + (pkg.dim_x || '') + '" placeholder="D"><span class="dex-mb-input-group__suffix">cm</span></div>'
                + '<div class="dex-mb-input-group"><input type="number" min="0" class="dex-mb-input dex-mb-input--sm dex-mb-pkg-dy" data-pkg="' + idx + '" value="' + (pkg.dim_y || '') + '" placeholder="Š"><span class="dex-mb-input-group__suffix">cm</span></div>'
                + '<div class="dex-mb-input-group"><input type="number" min="0" class="dex-mb-input dex-mb-input--sm dex-mb-pkg-dz" data-pkg="' + idx + '" value="' + (pkg.dim_z || '') + '" placeholder="V"><span class="dex-mb-input-group__suffix">cm</span></div>'
                + '</div></div>'

                + '</div>' // pkg-fields-row

                // Item allocation (always open)
                + itemsBlock(idx, pkg)

                + '</div></div>';
        });

        var $list = $('#dex-mb-pkg-list');
        $list.html(html);

        // Remove package
        $list.find('.dex-mb-pkg-remove').on('click', function () {
            removePkg(parseInt($(this).data('idx'), 10));
        });

        // Qty ± buttons
        $list.on('click', '.dex-mb-qty-minus', function () {
            var $inp = $list.find('.dex-mb-item-qty[data-pkg="' + $(this).data('pkg') + '"][data-item="' + $(this).data('item') + '"]');
            var v = Math.max(0, parseInt($inp.val(), 10) - 1);
            $inp.val(v).trigger('change');
        });
        $list.on('click', '.dex-mb-qty-plus', function () {
            var $inp = $list.find('.dex-mb-item-qty[data-pkg="' + $(this).data('pkg') + '"][data-item="' + $(this).data('item') + '"]');
            var max  = parseInt($(this).data('max'), 10) || 99;
            var v    = Math.min(max, parseInt($inp.val(), 10) + 1);
            $inp.val(v).trigger('change');
        });

        // Auto-fill content from item allocation
        $list.on('change', '.dex-mb-item-qty', function () {
            autoFillContent();
        });
    }

    // Auto-fill the content field from the first allocated items' category+name
    function autoFillContent() {
        var $content = $('#dex-mb-content');
        if ($content.attr('data-manual') === '1') { return; }

        var lines   = mb.orderLineItems || [];
        var totals  = {};
        $('.dex-mb-item-qty').each(function () {
            var qty = parseInt($(this).val(), 10) || 0;
            if (qty > 0) {
                var key = String($(this).data('item'));
                totals[key] = (totals[key] || 0) + qty;
            }
        });

        var parts = [];
        lines.forEach(function (line) {
            if ((totals[String(line.id)] || 0) > 0) {
                var label = (line.category ? line.category + ' ' : '') + line.name;
                parts.push(label);
            }
        });

        if (parts.length) {
            var suggestion = parts.join(', ').slice(0, 50);
            $content.val(suggestion);
        }
    }

    function readPkgsFromForm() {
        pkgs.forEach(function (pkg, idx) {
            var massKg = parseFloat($('.dex-mb-pkg-mass[data-pkg="' + idx + '"]').val()) || 0;
            pkg.mass   = massKg > 0 ? Math.round(massKg * 1000) : 0;
            pkg.dim_x  = (parseInt($('.dex-mb-pkg-dx[data-pkg="' + idx + '"]').val(), 10) || 0) || null;
            pkg.dim_y  = (parseInt($('.dex-mb-pkg-dy[data-pkg="' + idx + '"]').val(), 10) || 0) || null;
            pkg.dim_z  = (parseInt($('.dex-mb-pkg-dz[data-pkg="' + idx + '"]').val(), 10) || 0) || null;
            pkg.items  = {};
            $('.dex-mb-item-qty[data-pkg="' + idx + '"]').each(function () {
                var qty = parseInt($(this).val(), 10) || 0;
                if (qty > 0) { pkg.items[String($(this).data('item'))] = qty; }
            });
        });
    }

    function readOptionsFromForm() {
        return {
            sender_location_id: parseInt($('#dex-mb-location').val(), 10) || 0,
            delivery_type:      parseInt($('#dex-mb-delivery-type').val(), 10) || 2,
            payment_type:       parseInt($('#dex-mb-payment-type').val(), 10) || 2,
            return_doc:         parseInt($('#dex-mb-return-doc').val(), 10) || 0,
            self_drop_off:      parseInt($('#dex-mb-self-drop-off').val(), 10) || 0,
            content:            ($('#dex-mb-content').val() || '').trim(),
            note:               ($('#dex-mb-note').val() || '').trim(),
        };
    }

    function validatePkgs() {
        if (!pkgs.every(function (p) { return (p.mass || 0) >= 1; })) {
            setWizardError('Unesite težinu za svaki paket (min. 0.001 kg).');
            return false;
        }
        setWizardError('');
        return true;
    }

    function validateOptions(opts) {
        if (!opts.sender_location_id) { setWizardError('Izaberite lokaciju pošiljaoca.'); return false; }
        if (!opts.content)             { setWizardError('Sadržaj pošiljke je obavezan.'); return false; }
        setWizardError('');
        return true;
    }

    function toPayload(opts) {
        return {
            options:  opts,
            packages: pkgs.map(function (pkg) {
                var items = [];
                Object.keys(pkg.items || {}).forEach(function (k) {
                    items.push({ order_item_id: parseInt(k, 10), qty: parseInt(pkg.items[k], 10) || 0 });
                });
                return { mass: parseInt(pkg.mass, 10) || 0, dim_x: pkg.dim_x || null,
                         dim_y: pkg.dim_y || null, dim_z: pkg.dim_z || null,
                         content: (pkg.content || '').trim(), items: items };
            }),
        };
    }

    // ── Stepper ───────────────────────────────────────────────────

    var currentStep = 1;

    function showStep(n) {
        currentStep = n;
        $('#dex-mb-wizard .dex-mb-stepper__step').each(function () {
            var sn = parseInt($(this).data('step'), 10);
            $(this).removeClass('is-active is-done');
            if (sn < n)      { $(this).addClass('is-done'); }
            else if (sn === n){ $(this).addClass('is-active'); }
        });
        $('#dex-mb-wizard .dex-mb-panel').prop('hidden', true);
        $('#dex-mb-wizard .dex-mb-panel[data-panel="' + n + '"]').prop('hidden', false);
        if (n === 3) { renderSummary(); }
    }

    // ── Summary ───────────────────────────────────────────────────

    function renderSummary() {
        readPkgsFromForm();
        var opts      = readOptionsFromForm();
        var totalMass = 0;
        pkgs.forEach(function (p) { totalMass += parseInt(p.mass, 10) || 0; });

        // Recipient
        var recipientText = mb.recipient || '';
        if (mb.isPackageShop && mb.destination) { recipientText = mb.destination; }

        // Sender location name
        var senderName = '';
        (mb.senderLocations || []).forEach(function (loc) {
            if (loc.id === opts.sender_location_id) { senderName = loc.name; }
        });

        // Payment label
        var paymentLabel = $('#dex-mb-payment-type option:selected').text();
        var selfDropLabel = parseInt($('#dex-mb-self-drop-off').val(), 10)
            ? 'Sam donosim u D-Express' : 'Kurir dolazi po pošiljku';

        var currency = mb.currencySymbol || '';

        // Package rows
        var pkgRows = '';
        pkgs.forEach(function (p, i) {
            var massKg = ((parseInt(p.mass, 10) || 0) / 1000).toFixed(2).replace('.', ',');
            var dims   = (p.dim_x && p.dim_y && p.dim_z)
                ? p.dim_x + '×' + p.dim_y + '×' + p.dim_z + ' cm' : '—';

            // Per-package price from allocated items
            var pkgPrice = 0;
            var itemParts = [];
            Object.keys(p.items || {}).forEach(function (k) {
                var line = (mb.orderLineItems || []).find(function (l) { return String(l.id) === k; });
                if (line) {
                    var qty = parseInt(p.items[k], 10) || 0;
                    pkgPrice += (line.unit_price || 0) * qty;
                    var img = line.image_url
                        ? '<img src="' + esc(line.image_url) + '" width="28" height="28" class="dex-mb-summary-item-img" alt="">'
                        : '';
                    itemParts.push(img + esc(line.name) + ' <strong>×' + qty + '</strong>');
                }
            });
            var priceTag = pkgPrice > 0
                ? '<span class="dex-mb-summary-pkg__price">' + pkgPrice.toFixed(2).replace('.', ',') + ' ' + esc(currency) + '</span>'
                : '';

            pkgRows += '<div class="dex-mb-summary-pkg">'
                + '<div class="dex-mb-summary-pkg__head">'
                + '<span class="dashicons dashicons-archive"></span>'
                + ' <strong>Paket ' + (i + 1) + '</strong>'
                + '<span class="dex-mb-summary-pkg__weight">' + massKg + ' kg</span>'
                + '<span class="dex-mb-summary-pkg__dims">' + dims + '</span>'
                + priceTag
                + '</div>'
                + (itemParts.length
                    ? '<div class="dex-mb-summary-pkg__items">' + itemParts.join('') + '</div>'
                    : '<div class="dex-mb-summary-pkg__items dex-mb-summary-pkg__items--empty">Bez raspodele stavki</div>')
                + '</div>';
        });

        // Payment info from order meta
        var om = mb.orderMeta || {};
        var uplataBadge;
        if (om.paymentMethod === 'cod') {
            uplataBadge = '<span style="color:var(--dex-amber);font-weight:700;">Pouzećem (plaća kuriru)</span>';
        } else if (om.isPaid) {
            uplataBadge = '<span style="color:var(--dex-green);font-weight:700;">Plaćeno</span>';
        } else {
            uplataBadge = '<span style="color:var(--dex-red);font-weight:700;">Čeka plaćanje</span>';
        }

        var html = '<div class="dex-mb-summary">'

            // Packages section
            + '<div class="dex-mb-summary-section">'
            + '<div class="dex-mb-summary-section__title"><span class="dashicons dashicons-archive"></span>'
            + ' Paketi (' + pkgs.length + ') · ukupno ' + (totalMass / 1000).toFixed(2).replace('.', ',') + ' kg</div>'
            + pkgRows
            + '</div>'

            // Opcije + Porudžbina side by side
            + '<div class="dex-mb-summary-cols">'

            + '<div class="dex-mb-summary-section">'
            + '<div class="dex-mb-summary-section__title"><span class="dashicons dashicons-admin-settings"></span> Opcije</div>'
            + '<div class="dex-mb-summary-row"><span>Šalje:</span><span>' + esc(senderName || 'Nije izabrano') + '</span></div>'
            + '<div class="dex-mb-summary-row"><span>Prima:</span><span>' + esc(recipientText || '—') + '</span></div>'
            + '<div class="dex-mb-summary-row"><span>Predaja:</span><span>' + esc(selfDropLabel) + '</span></div>'
            + '<div class="dex-mb-summary-row"><span>Naplata:</span><span>' + esc(paymentLabel) + '</span></div>'
            + '<div class="dex-mb-summary-row"><span>Sadržaj:</span><span>' + esc(opts.content) + '</span></div>'
            + (opts.note ? '<div class="dex-mb-summary-row"><span>Napomena:</span><span>' + esc(opts.note) + '</span></div>' : '')
            + '</div>'

            + '<div class="dex-mb-summary-section">'
            + '<div class="dex-mb-summary-section__title"><span class="dashicons dashicons-money-alt"></span> Porudžbina</div>'
            + '<div class="dex-mb-summary-row"><span>Status:</span><span>' + esc(om.statusLabel || '—') + '</span></div>'
            + '<div class="dex-mb-summary-row"><span>Uplata:</span>' + uplataBadge + '</div>'
            + '<div class="dex-mb-summary-row"><span>Način plaćanja:</span><span>' + esc(om.paymentMethodTitle || '—') + '</span></div>'
            + '<div class="dex-mb-summary-row"><span>Vrednost:</span><span>' + esc(om.total || '—') + '</span></div>'
            + '</div>'

            + '</div>' // summary-cols

            + '</div>';

        $('#dex-mb-summary').html(html);
    }

    // ── Apply initial draft (edit mode) ───────────────────────────

    function applyDraft(draft) {
        if (!draft || !draft.options || !Array.isArray(draft.packages) || !draft.packages.length) { return; }
        pkgs = draft.packages.map(function (p) {
            var items = {};
            if (p.items && typeof p.items === 'object') {
                Object.keys(p.items).forEach(function (k) { items[k] = parseInt(p.items[k], 10) || 0; });
            }
            return { mass: parseInt(p.mass, 10) || 500, dim_x: p.dim_x ? parseInt(p.dim_x, 10) : null,
                     dim_y: p.dim_y ? parseInt(p.dim_y, 10) : null, dim_z: p.dim_z ? parseInt(p.dim_z, 10) : null,
                     content: String(p.content || '').trim(), items: items };
        });
        renderPkgs();

        var o = draft.options;
        if (o.sender_location_id) {
            $('#dex-mb-location').val(String(o.sender_location_id));
            $('#dex-mb-location-wrap .dex-mb-location-option').removeClass('is-selected');
            $('#dex-mb-location-wrap .dex-mb-location-option[data-id="' + o.sender_location_id + '"]').addClass('is-selected');
        }
        $('#dex-mb-delivery-type').val(String(o.delivery_type));
        $('#dex-mb-payment-type').val(String(o.payment_type));
        $('#dex-mb-return-doc').val(String(o.return_doc));
        $('#dex-mb-content').val(String(o.content || ''));
        $('#dex-mb-note').val(String(o.note || ''));

        var sdVal = parseInt(o.self_drop_off, 10) || 0;
        $('#dex-mb-self-drop-off').val(String(sdVal));
        $('#dex-mb-segment-dropoff .dex-mb-dropoff-btn').removeClass('is-active');
        $('#dex-mb-segment-dropoff .dex-mb-dropoff-btn[data-value="' + sdVal + '"]').addClass('is-active');
    }

    // ── Init wizard ───────────────────────────────────────────────

    function initWizard(inEditMode) {
        var $wizard = $('#dex-mb-wizard');
        if (!$wizard.length) { return; }

        if (inEditMode && mb.initialDraft) {
            applyDraft(mb.initialDraft);
        } else {
            if (!pkgs.length) { pkgs.push(defaultPkg()); }
            renderPkgs();
        }
        showStep(1);

        // Location radio cards
        $wizard.on('click', '.dex-mb-location-option', function () {
            $wizard.find('.dex-mb-location-option').removeClass('is-selected');
            $(this).addClass('is-selected');
            $('#dex-mb-location').val(String($(this).data('id')));
        });

        // Dropoff toggle
        $wizard.find('.dex-mb-dropoff-btn').on('click', function () {
            $(this).closest('.dex-mb-dropoff-toggle').find('.dex-mb-dropoff-btn').removeClass('is-active');
            $(this).addClass('is-active');
            $('#dex-mb-self-drop-off').val(String($(this).data('value')));
        });

        // Content field: mark as manual if user types
        $('#dex-mb-content').on('input', function () {
            $(this).attr('data-manual', '1');
        });

        // Add package
        $('#dex-mb-add-pkg').on('click', addPkg);

        // Navigation
        $('#dex-mb-next-1').on('click', function () {
            readPkgsFromForm();
            if (!validatePkgs()) { return; }
            showStep(2);
        });
        $('#dex-mb-back-2').on('click', function () { showStep(1); setWizardError(''); });
        $('#dex-mb-next-2').on('click', function () {
            readPkgsFromForm();
            var opts = readOptionsFromForm();
            if (!validateOptions(opts)) { return; }
            showStep(3);
        });
        $('#dex-mb-back-3').on('click', function () { showStep(2); setWizardError(''); });

        // Create shipment (Step 3)
        $('#dex-mb-create').on('click', function () {
            readPkgsFromForm();
            var opts = readOptionsFromForm();
            if (!validatePkgs() || !validateOptions(opts)) { return; }

            // Pre-open window synchronously (inside click handler) to avoid popup blockers.
            var printWin = window.open('', '_blank');

            var $btn = $(this).prop('disabled', true)
                .html('<span class="dashicons dashicons-update-alt"></span> ' + mb.i18n.creating);
            setWizardError('');
            setMsg('', '');

            $.post(mb.ajaxUrl, {
                action:      'dexpress_save_shipment_local',
                nonce:       mb.nonceSaveLocal,
                order_id:    mb.orderId,
                shipment_id: parseInt(mb.editShipmentId || 0, 10) || 0,
                draft:       JSON.stringify(toPayload(opts)),
            }).done(function (resp) {
                if (!resp.success) {
                    if (printWin) { try { printWin.close(); } catch(e){} }
                    setWizardError((resp.data && resp.data.message) || mb.i18n.error);
                    return;
                }
                var labelUrl = resp.data && resp.data.label_url ? String(resp.data.label_url) : '';
                if (labelUrl && printWin) {
                    printWin.location = labelUrl;
                } else if (printWin) {
                    try { printWin.close(); } catch(e) {}
                }
                setTimeout(function () { window.location.reload(); }, 600);
            }).fail(function () {
                if (printWin) { try { printWin.close(); } catch(e){} }
                setWizardError(mb.i18n.error);
            }).always(function () {
                $btn.prop('disabled', false)
                    .html('<span class="dashicons dashicons-printer"></span> Pakuj i štampaj nalepnicu');
            });
        });
    }

    // ── Init pending (State B) ────────────────────────────────────

    function initPending() {
        if (!$('#dex-mb-pending-view').length) { return; }

        $('#dex-mb-edit-pending').on('click', function () {
            $('#dex-mb-pending-view').hide();
            $('#dex-mb-edit-view').show();
            initWizard(true);
        });

        $('#dex-mb-cancel-edit').on('click', function () {
            $('#dex-mb-edit-view').hide();
            $('#dex-mb-pending-view').show();
        });

        $('#dex-mb-send').on('click', function () {
            var $btn = $(this).prop('disabled', true)
                .html('<span class="dashicons dashicons-update-alt"></span> Slanje...');
            setMsg('', '');
            $.post(mb.ajaxUrl, {
                action:      'dexpress_send_saved_shipment',
                nonce:       mb.nonceSendSaved,
                shipment_id: mb.pendingShipmentId,
            }).done(function (resp) {
                if (!resp.success) {
                    setMsg((resp.data && resp.data.message) || mb.i18n.error, 'error');
                    return;
                }
                setMsg((resp.data && resp.data.message) || 'Pošiljka je poslata.', 'success');
                setTimeout(function () { window.location.reload(); }, 1200);
            }).fail(function () {
                setMsg(mb.i18n.error, 'error');
            }).always(function () {
                $btn.prop('disabled', false)
                    .html('<span class="dashicons dashicons-share"></span> ' + mb.i18n.sendToDexpress);
            });
        });

        $('#dex-mb-delete-pending').on('click', function () {
            if (!window.confirm('Da li ste sigurni? Ovo će trajno obrisati pošiljku i TT kod. Ne može se poništiti.')) {
                return;
            }
            var $btn = $(this).prop('disabled', true)
                .html('<span class="dashicons dashicons-update-alt"></span> Brisanje...');
            $.post(mb.ajaxUrl, {
                action:      'dexpress_delete_pending_shipment',
                nonce:       mb.nonceDeletePending,
                shipment_id: mb.pendingShipmentId,
            }).done(function (resp) {
                if (!resp.success) {
                    setMsg((resp.data && resp.data.message) || mb.i18n.error, 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Obriši pošiljku');
                    return;
                }
                window.location.reload();
            }).fail(function () {
                setMsg(mb.i18n.error, 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Obriši pošiljku');
            });
        });
    }

    // ── Init created (State C) ────────────────────────────────────

    function initCreated() {
        $('.dex-mb-copy-track').on('click', function () {
            var code     = String($(this).data('track') || '').trim();
            if (!code) { return; }
            var done     = function () { setMsg('Kod za praćenje je kopiran.', 'success'); };
            var fallback = function () {
                var $t = $('<input>').val(code).appendTo('body');
                $t.trigger('select');
                try { document.execCommand('copy'); done(); } catch (e) { setMsg('Kopiranje nije uspelo.', 'error'); }
                $t.remove();
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done).catch(fallback);
            } else { fallback(); }
        });
    }

    // ── Boot ──────────────────────────────────────────────────────

    var status = mb.sendStatus || '';
    if      (status === '')            { initWizard(false); }
    else if (status === 'pending_send'){ initPending(); }
    else if (status === 'sent')        { initCreated(); }

}(jQuery));
