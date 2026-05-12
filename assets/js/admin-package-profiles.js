/**
 * D Express — Package Profiles admin JS
 * Rukuje AJAX CRUD-om za profil paketa (bez osvežavanja stranice).
 */
(function ($) {
    'use strict';

    var cfg     = window.dexpressProfiles || {};
    var ajax    = cfg.ajaxUrl || '';
    var nonces  = cfg.nonces || {};
    var i18n    = cfg.i18n || {};
    var iconUrl = cfg.iconUrl || '';

    // ── DOM references ─────────────────────────────────────────────────────────
    var $modal     = $('#dex-pp-modal');
    var $formTitle = $('#dex-pp-form-title');
    var $form      = $('#dex-pp-form');
    var $tableWrap = $('#dex-pp-table-wrap');
    var $msg       = $('#dex-pp-msg');
    var $addBtn    = $('#dex-pp-add-btn');
    var $cancelBtn = $('#dex-pp-cancel-btn');
    var $saveBtn   = $('#dex-pp-save-btn');

    // ── Helpers ────────────────────────────────────────────────────────────────
    function showMsg(text, type) {
        $msg.text(text)
            .removeClass('dex-pp-msg--ok dex-pp-msg--error')
            .addClass(type === 'ok' ? 'dex-pp-msg--ok' : 'dex-pp-msg--error');
    }

    function clearMsg() { $msg.text('').removeClass('dex-pp-msg--ok dex-pp-msg--error'); }

    function resetForm() {
        $form[0].reset();
        $('#dex-pp-id').val(0);
        $formTitle.text(i18n.newTitle || 'Novi profil paketa');
        clearMsg();
    }

    function openForm() {
        $modal.addClass('is-open');
        $('body').addClass('dex-pp-modal-open');
        $('#dex-pp-name').focus();
    }

    function closeForm() {
        $modal.removeClass('is-open');
        $('body').removeClass('dex-pp-modal-open');
        resetForm();
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escAttr(str) { return escHtml(str); }

    /** Gradi HTML jedne kartice profila iz JSON objekta */
    function buildCard(p) {
        var wG       = parseInt(p.weight_grams, 10) || 0;
        var wKg      = wG > 0 ? (wG / 1000).toFixed(3) + ' kg' : '—';
        var dimParts = ['dim_x', 'dim_y', 'dim_z'].map(function (k) {
            return p[k] != null ? parseFloat(p[k] / 10).toFixed(1) : '—';
        });
        var dims      = dimParts.join(' × ') + ' cm';
        var isDefault = parseInt(p.is_default, 10);

        var profileJson = JSON.stringify({
            id:              p.id,
            name:            p.name,
            description:     p.description || '',
            weight_kg:       wG > 0 ? (wG / 1000).toFixed(3) : '',
            dim_x:           p.dim_x != null ? (p.dim_x / 10).toFixed(1) : '',
            dim_y:           p.dim_y != null ? (p.dim_y / 10).toFixed(1) : '',
            dim_z:           p.dim_z != null ? (p.dim_z / 10).toFixed(1) : '',
            default_content: p.default_content || '',
        });

        var badge      = isDefault ? ' <span class="dex-pp-badge dex-pp-badge--default">Podrazumevani</span>' : '';
        var defaultBtn = !isDefault
            ? '<button type="button" class="button button-small dex-pp-default-btn" data-id="' + p.id + '">Podrazumevani</button>'
            : '';
        var descHtml   = p.description
            ? '<p class="dex-pp-card-desc">' + escHtml(p.description) + '</p>'
            : '';
        var contentRow = p.default_content
            ? '<dt>Sadržaj</dt><dd>' + escHtml(p.default_content) + '</dd>'
            : '';

        return '<div class="dex-pp-card" data-profile="' + escAttr(profileJson) + '">'
            +   '<div class="dex-pp-card-inner">'
            +     '<div class="dex-pp-card-header">'
            +       (iconUrl ? '<img src="' + escAttr(iconUrl) + '" class="dex-pp-card-icon" alt="" />' : '')
            +       '<div class="dex-pp-card-title">'
            +         '<strong class="dex-pp-card-name">' + escHtml(p.name) + '</strong>'
            +         badge
            +       '</div>'
            +     '</div>'
            +     '<dl class="dex-pp-card-meta">'
            +       '<dt>Dimenzije</dt><dd>' + escHtml(dims) + '</dd>'
            +       '<dt>Masa</dt><dd>' + escHtml(wKg) + '</dd>'
            +       contentRow
            +     '</dl>'
            +     descHtml
            +   '</div>'
            +   '<div class="dex-pp-card-actions">'
            +     '<button type="button" class="button button-small dex-pp-edit-btn" data-id="' + p.id + '">Izmeni</button>'
            +     defaultBtn
            +     '<button type="button" class="button button-small button-link-delete dex-pp-delete-btn" data-id="' + p.id + '" data-name="' + escAttr(p.name) + '">Obriši</button>'
            +   '</div>'
            + '</div>';
    }

    /** Zamenjuje sadržaj grida svežim listom profila (JSON odgovor servera). */
    function refreshTable(profiles) {
        if (!Array.isArray(profiles) || profiles.length === 0) {
            var emptyIcon = iconUrl ? '<img src="' + escAttr(iconUrl) + '" class="dex-pp-empty-icon" alt="" />' : '';
            $tableWrap.html(
                '<div class="dex-pp-empty">'
                + emptyIcon
                + '<h2 class="dex-pp-empty-title">Još nema profila paketa</h2>'
                + '<p class="dex-pp-empty-desc">Definišite dimenzije i težinu kutija koje koristite za pakovanje. Jednom sačuvan profil možete koristiti za brzo kreiranje pošiljki.</p>'
                + '<button type="button" class="button button-primary dex-pp-open-form">＋ Kreiraj prvi profil</button>'
                + '</div>'
            );
            return;
        }

        var addCard = '<button type="button" class="dex-pp-card dex-pp-card--add dex-pp-open-form">'
            + '<span class="dex-pp-card-add-plus">＋</span>'
            + '<span class="dex-pp-card-add-label">Dodaj profil</span>'
            + '</button>';

        $tableWrap.html(
            '<div class="dex-pp-grid">'
            + profiles.map(buildCard).join('')
            + addCard
            + '</div>'
        );
    }

    // ── Event: otvori modal za novi profil ─────────────────────────────────────
    $addBtn.on('click', function () {
        resetForm();
        openForm();
    });

    // ── Event: zatvori modal — dugme ✕ ─────────────────────────────────────────
    $('#dex-pp-modal-close').on('click', function () { closeForm(); });

    // ── Event: zatvori modal — backdrop ───────────────────────────────────────
    $modal.on('click', function (e) {
        if ($(e.target).hasClass('dex-pp-modal__backdrop')) { closeForm(); }
    });

    // ── Event: zatvori modal — tipka Escape ───────────────────────────────────
    $(document).on('keydown.dex-pp-modal', function (e) {
        if (e.key === 'Escape' && $modal.hasClass('is-open')) { closeForm(); }
    });

    // ── Event: otkaži ──────────────────────────────────────────────────────────
    $cancelBtn.on('click', function () { closeForm(); });

    // ── Event: sačuvaj profil ──────────────────────────────────────────────────
    $form.on('submit', function (e) {
        e.preventDefault();

        var name = $.trim($('#dex-pp-name').val());
        if (!name) {
            showMsg('Naziv je obavezan.', 'error');
            return;
        }

        $saveBtn.prop('disabled', true).text(i18n.saving || 'Čuvanje...');
        clearMsg();

        $.post(ajax, {
            action:          'dexpress_save_package_profile',
            nonce:           nonces.save,
            id:              $('#dex-pp-id').val(),
            name:            name,
            description:     $('#dex-pp-desc').val(),
            weight_kg:       $('#dex-pp-weight').val(),
            dim_x:           $('#dex-pp-dx').val(),
            dim_y:           $('#dex-pp-dy').val(),
            dim_z:           $('#dex-pp-dz').val(),
            default_content: $('#dex-pp-content').val(),
        })
        .done(function (res) {
            if (res.success) {
                closeForm();
                refreshTable(res.data.profiles);
            } else {
                showMsg(res.data.message || i18n.error, 'error');
            }
        })
        .fail(function () { showMsg(i18n.error || 'Greška.', 'error'); })
        .always(function () {
            $saveBtn.prop('disabled', false).text('Sačuvaj profil');
        });
    });

    // ── Event: izmeni (delegirano) ─────────────────────────────────────────────
    $tableWrap.on('click', '.dex-pp-edit-btn', function () {
        var $card = $(this).closest('[data-profile]');
        var data;

        try {
            data = JSON.parse($card.attr('data-profile') || '{}');
        } catch (ex) {
            data = {};
        }

        resetForm();
        $('#dex-pp-id').val(data.id || 0);
        $('#dex-pp-name').val(data.name || '');
        $('#dex-pp-desc').val(data.description || '');
        $('#dex-pp-weight').val(data.weight_kg || '');
        $('#dex-pp-dx').val(data.dim_x || '');
        $('#dex-pp-dy').val(data.dim_y || '');
        $('#dex-pp-dz').val(data.dim_z || '');
        $('#dex-pp-content').val(data.default_content || '');
        $formTitle.text(i18n.editTitle || 'Izmeni profil paketa');
        openForm();
    });

    // ── Event: postavi kao podrazumevani (delegirano) ──────────────────────────
    $tableWrap.on('click', '.dex-pp-default-btn', function () {
        var id = $(this).data('id');
        $.post(ajax, {
            action: 'dexpress_set_default_profile',
            nonce:  nonces.setDefault,
            id:     id,
        })
        .done(function (res) {
            if (res.success) { refreshTable(res.data.profiles); }
        });
    });

    // ── Event: obriši (delegirano) ─────────────────────────────────────────────
    $tableWrap.on('click', '.dex-pp-delete-btn', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        var msg  = (i18n.confirmDelete || 'Obrisati "%s"?').replace('%s', name);
        if (!window.confirm(msg)) { return; }

        $.post(ajax, {
            action: 'dexpress_delete_package_profile',
            nonce:  nonces.delete,
            id:     id,
        })
        .done(function (res) {
            if (res.success) { refreshTable(res.data.profiles); }
        });
    });

})(jQuery);
