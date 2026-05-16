/* global dexShipments */
(function ($) {
	'use strict';

	var d    = window.dexShipments || {};
	var i18n = d.i18n || {};

	/* ─── Global config state ─────────────────────────────────────── */
	var cfg = {
		senderLocationId : 0,
		defaultContent   : '',
		defaultNote      : '',
		defaultWeightKg  : 0,   /* box/packaging weight from profile */
		defaultDimX      : 0,
		defaultDimY      : 0,
		defaultDimZ      : 0,
		get selfDropOff() { return $('[name="dex_delivery_mode"]:checked').val() === '1'; },
	};

	/* Results map: orderId → { shipmentId, trackingCode, labelUrl, status } */
	var results = {};

	/* ─── Init from DOM ───────────────────────────────────────────── */
	$(function () {
		cfg.senderLocationId = parseInt($('#dex-cfg-location').val(), 10) || 0;

		/* Mark content dirty for any card pre-filled from product names */
		$('.dex-order-card').each(function () {
			var $c = $(this).find('.dex-row-content');
			if ($c.val().trim() !== '') {
				$c.data('dirty', true);
			}
		});

		updateActionBar();

		/* Pre-select profile from URL param (passed from ShipmentsPage redirect) */
		var profileId = new URLSearchParams(window.location.search).get('profile_id');
		if (profileId) {
			$('.dex-profile-btn[data-profile-id="' + parseInt(profileId, 10) + '"]').trigger('click');
		}
	});

	/* ─── Profile card click ──────────────────────────────────────── */
	$(document).on('click', '.dex-profile-btn', function () {
		$('.dex-profile-btn').removeClass('dex-profile-btn--active');
		$(this).addClass('dex-profile-btn--active');

		var wG      = parseFloat($(this).data('weight-g')) || 0;
		var dimX    = $(this).data('dim-x');
		var dimY    = $(this).data('dim-y');
		var dimZ    = $(this).data('dim-z');
		var content = String($(this).data('content') || '');

		if (wG > 0) {
			cfg.defaultWeightKg = wG / 1000;
			$('#dex-cfg-weight').val(cfg.defaultWeightKg.toFixed(2));
		}
		if (dimX !== '' && dimX !== undefined) {
			cfg.defaultDimX = parseFloat(dimX) || 0;
			$('#dex-cfg-dim-x').val(dimX);
		}
		if (dimY !== '' && dimY !== undefined) {
			cfg.defaultDimY = parseFloat(dimY) || 0;
			$('#dex-cfg-dim-y').val(dimY);
		}
		if (dimZ !== '' && dimZ !== undefined) {
			cfg.defaultDimZ = parseFloat(dimZ) || 0;
			$('#dex-cfg-dim-z').val(dimZ);
		}
		if (content !== '') {
			cfg.defaultContent = content;
			$('#dex-cfg-content').val(content);
		}

		applyDefaultsToAllCards();
	});

	/* ─── Config field live-sync ──────────────────────────────────── */
	$(document).on('change input', '#dex-cfg-weight',   function () { cfg.defaultWeightKg = parseFloat($(this).val()) || 0; applyDefaultsToAllCards(); });
	$(document).on('change input', '#dex-cfg-dim-x',    function () { cfg.defaultDimX = parseFloat($(this).val()) || 0; applyDefaultsToAllCards(); });
	$(document).on('change input', '#dex-cfg-dim-y',    function () { cfg.defaultDimY = parseFloat($(this).val()) || 0; applyDefaultsToAllCards(); });
	$(document).on('change input', '#dex-cfg-dim-z',    function () { cfg.defaultDimZ = parseFloat($(this).val()) || 0; applyDefaultsToAllCards(); });
	$(document).on('change input', '#dex-cfg-content',  function () { cfg.defaultContent = $(this).val(); applyDefaultsToAllCards(); });
	$(document).on('change input', '#dex-cfg-note',     function () { cfg.defaultNote = $(this).val(); applyDefaultsToAllCards(); });
	$(document).on('change',       '#dex-cfg-location',     function () { cfg.senderLocationId = parseInt($(this).val(), 10) || 0; });

	/* ─── Apply global defaults to all cards ──────────────────────── */
	function applyDefaultsToAllCards() {
		$('.dex-order-card').each(function () {
			applyDefaultsToCard($(this));
		});
	}

	function applyDefaultsToCard($card) {
		var productWeightKg = parseFloat($card.data('product-weight-kg')) || 0;
		var $w  = $card.find('.dex-row-weight');
		var $dx = $card.find('.dex-row-dim-x');
		var $dy = $card.find('.dex-row-dim-y');
		var $dz = $card.find('.dex-row-dim-z');
		var $c  = $card.find('.dex-row-content');
		var $n  = $card.find('.dex-row-note');

		if (!$w.data('dirty')) {
			var total = productWeightKg + cfg.defaultWeightKg;
			if (total > 0) { $w.val(total.toFixed(2)); }
			else if (productWeightKg > 0) { $w.val(productWeightKg.toFixed(2)); }
		}
		if (!$dx.data('dirty') && cfg.defaultDimX > 0) { $dx.val(cfg.defaultDimX); }
		if (!$dy.data('dirty') && cfg.defaultDimY > 0) { $dy.val(cfg.defaultDimY); }
		if (!$dz.data('dirty') && cfg.defaultDimZ > 0) { $dz.val(cfg.defaultDimZ); }
		if (!$c.data('dirty')  && cfg.defaultContent !== '') { $c.val(cfg.defaultContent); }
		if (!$n.data('dirty'))                               { $n.val(cfg.defaultNote); }
	}

	/* ─── Mark fields dirty on manual edit ───────────────────────── */
	$(document).on('input', '.dex-row-weight, .dex-row-dim-x, .dex-row-dim-y, .dex-row-dim-z, .dex-row-content', function () {
		$(this).data('dirty', true);
	});

	/* ─── Inline validation for paketomat constraints ─────────────── */
	function validateCard($card) {
		if ($card.data('shop') !== 1) { return; }
		var $err = $card.find('.dex-card-valerr');
		var errs = [];

		var w = parseFloat($card.find('.dex-row-weight').val()) || 0;
		if (w > 20) { errs.push('Masa ne sme biti veća od 20 kg za paketomat.'); }

		var dx = parseFloat($card.find('.dex-row-dim-x').val()) || 0;
		var dy = parseFloat($card.find('.dex-row-dim-y').val()) || 0;
		var dz = parseFloat($card.find('.dex-row-dim-z').val()) || 0;
		if ((dx > 0 && dx > 47) || (dy > 0 && dy > 44) || (dz > 0 && dz > 44)) {
			errs.push('Dimenzije prelaze limit za paketomat: max 47×44×44 cm.');
		}

		if (errs.length) {
			$err.html(errs.map(function (e) { return esc(e); }).join('<br>')).removeAttr('hidden');
			$card.addClass('dex-order-card--invalid');
		} else {
			$err.attr('hidden', '').empty();
			$card.removeClass('dex-order-card--invalid');
		}
	}

	$(document).on('input', '.dex-order-card .dex-row-weight, .dex-order-card .dex-row-dim-x, .dex-order-card .dex-row-dim-y, .dex-order-card .dex-row-dim-z', function () {
		validateCard($(this).closest('.dex-order-card'));
	});

	/* ─── Reset card to product defaults + current profile ───────── */
	$(document).on('click', '.dex-row-reset', function () {
		var $card = $(this).closest('.dex-order-card');

		$card.find('.dex-row-weight, .dex-row-dim-x, .dex-row-dim-y, .dex-row-dim-z, .dex-row-note')
			.each(function () { $(this).data('dirty', false); });

		var $c         = $card.find('.dex-row-content');
		var suggestion = String($card.data('content-suggestion') || '');
		if (suggestion) {
			$c.val(suggestion).data('dirty', true);
		} else {
			$c.data('dirty', false);
		}

		applyDefaultsToCard($card);
	});

	/* ─── Filter tabs ─────────────────────────────────────────────── */
	$(document).on('click', '.dex-filter-tab', function () {
		$('.dex-filter-tab').removeClass('dex-filter-tab--active');
		$(this).addClass('dex-filter-tab--active');
		var filter = $(this).data('filter');

		$('.dex-order-card').each(function () {
			var $card = $(this);
			var show  = true;
			if (filter === 'cod'  && $card.data('cod')  !== 1) { show = false; }
			if (filter === 'shop' && $card.data('shop') !== 1) { show = false; }

			if (!show) {
				$card.addClass('dex-order-card--hidden');
				$card.find('.dex-order-cb').prop('checked', false);
			} else {
				$card.removeClass('dex-order-card--hidden');
			}
		});

		syncSelectAll();
		updateActionBar();
	});

	/* ─── Select-all ──────────────────────────────────────────────── */
	$(document).on('change', '#dex-select-all', function () {
		$('.dex-order-card:not(.dex-order-card--hidden) .dex-order-cb').prop('checked', this.checked);
		updateActionBar();
	});

	$(document).on('change', '.dex-order-cb', function () {
		syncSelectAll();
		updateActionBar();
	});

	function syncSelectAll() {
		var $visible = $('.dex-order-card:not(.dex-order-card--hidden) .dex-order-cb');
		var total    = $visible.length;
		var checked  = $visible.filter(':checked').length;
		var $all     = $('#dex-select-all');
		$all.prop('indeterminate', checked > 0 && checked < total);
		$all.prop('checked', total > 0 && checked === total);
	}

	function updateActionBar() {
		var count = $('.dex-order-cb:checked').length;
		var $btn  = $('#dex-create-btn');

		$btn.prop('disabled', count === 0);

		if (count > 0) {
			$btn.text('Pregledaj (' + count + ') →');
			$('#dex-action-info').text(count + ' ' + (count === 1 ? 'narudžbina izabrana' : 'narudžbine izabrane'));
		} else {
			$btn.text('Pregledaj →');
			$('#dex-action-info').text('Izaberite narudžbine za kreiranje pošiljki');
		}
	}

	/* ─── Validation ──────────────────────────────────────────────── */
	function validateConfig() {
		var errors = [];

		if (!cfg.senderLocationId) {
			errors.push(i18n.locationReq || 'Izaberite lokaciju pošiljaoca.');
		}

		$('.dex-order-cb:checked').each(function () {
			var $card  = $(this).closest('.dex-order-card');
			var num    = $card.data('number') || $card.data('id');
			var weight = parseFloat($card.find('.dex-row-weight').val()) || 0;
			var content = $card.find('.dex-row-content').val().trim();
			if (!content && !cfg.defaultContent.trim()) {
				errors.push('Narudžbina #' + num + ': unesite sadržaj paketa.');
			}
			if ($card.data('shop') === 1) {
				if (weight > 20) {
					errors.push('Narudžbina #' + num + ': masa ne sme biti veća od 20 kg za paketomat.');
				}
				var dx = parseFloat($card.find('.dex-row-dim-x').val()) || 0;
				var dy = parseFloat($card.find('.dex-row-dim-y').val()) || 0;
				var dz = parseFloat($card.find('.dex-row-dim-z').val()) || 0;
				if ((dx > 0 && dx > 47) || (dy > 0 && dy > 44) || (dz > 0 && dz > 44)) {
					errors.push('Narudžbina #' + num + ': dimenzije prelaze paketomat limit (47×44×44 cm).');
				}
			}
		});
		if ($('.dex-order-cb:checked').length === 0) {
			errors.push(i18n.noSelection || 'Izaberite bar jednu narudžbinu.');
		}

		var $errEl = $('#dex-config-errors');
		if (errors.length) {
			$errEl.html(
				'<ul>' + errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>'
			);
			$errEl.removeAttr('hidden');
			return false;
		}
		$errEl.attr('hidden', '').empty();
		return true;
	}

	/* ─── Collect selected orders into array ─────────────────────── */
	function collectOrders() {
		var orders = [];
		$('.dex-order-cb:checked').each(function () {
			var $card   = $(this).closest('.dex-order-card');
			var orderId = String($(this).val());
			orders.push({
				orderId    : orderId,
				number     : String($card.data('number') || orderId),
				customer   : String($card.data('customer') || ''),
				isShop     : $card.data('shop') === 1,
				isCod      : $card.data('cod') === 1,
				weightKg   : parseFloat($card.find('.dex-row-weight').val()) || (parseFloat($card.data('product-weight-kg')) || 0) + cfg.defaultWeightKg,
				dimX       : parseFloat($card.find('.dex-row-dim-x').val()) || cfg.defaultDimX || null,
				dimY       : parseFloat($card.find('.dex-row-dim-y').val()) || cfg.defaultDimY || null,
				dimZ       : parseFloat($card.find('.dex-row-dim-z').val()) || cfg.defaultDimZ || null,
				content    : $card.find('.dex-row-content').val().trim() || cfg.defaultContent,
				note       : $card.find('.dex-row-note').val().trim()    || cfg.defaultNote,
				selfDropOff: cfg.selfDropOff,
			});
		});
		return orders;
	}

	/* ─── Create button → show preview (step 2) ───────────────────── */
	$(document).on('click', '#dex-create-btn', function () {
		if (!validateConfig()) {
			var errTop = ($('#dex-config-errors').offset() || {}).top || 0;
			$('html,body').animate({ scrollTop: errTop - 60 }, 250);
			return;
		}

		var toCreate = collectOrders();
		$('#dex-config-card, #dex-orders-card, #dex-action-bar').hide();
		showPreview(toCreate);
	});

	/* ─── Preview: build and show ─────────────────────────────────── */
	function showPreview(orders) {
		var $tbody = $('#dex-preview-tbody').empty();

		orders.forEach(function (o) {
			var weight = o.weightKg ? o.weightKg.toFixed(2) + ' kg' : '—';
			var dims   = (o.dimX && o.dimY && o.dimZ)
				? o.dimX + '×' + o.dimY + '×' + o.dimZ + ' cm'
				: '—';
			var predaja = o.selfDropOff
				? '<span class="dex-badge dex-badge--muted">Sam donosim</span>'
				: '<span class="dex-badge dex-badge--info">Kurir dolazi</span>';
			var payment = o.isCod
				? '<span class="dex-badge dex-badge--warn">Pouzećem</span>'
				: '<span class="dex-badge dex-badge--success">Plaćeno</span>';

			$tbody.append(
				'<tr>' +
				'<td><strong>#' + esc(o.number) + '</strong></td>' +
				'<td>' + esc(o.customer) + '</td>' +
				'<td>' + esc(weight) + '</td>' +
				'<td>' + esc(dims) + '</td>' +
				'<td>' + esc(o.content) + '</td>' +
				'<td>' + predaja + '</td>' +
				'<td>' + payment + '</td>' +
				'</tr>'
			);
		});

		var n = orders.length;
		$('#dex-preview-title').text('Pregled — ' + n + (n === 1 ? ' pošiljka' : (n < 5 ? ' pošiljke' : ' pošiljki')));
		$('#dex-proceed-btn').data('pending', orders);

		var $sec = $('#dex-preview-section');
		$sec.removeAttr('hidden').show();
		$('html,body').animate({ scrollTop: ($sec.offset() || {}).top - 30 }, 250);
	}

	/* ─── Preview back → return to selection ──────────────────────── */
	$(document).on('click', '#dex-preview-back-btn', function () {
		$('#dex-preview-section').hide().attr('hidden', '');
		$('#dex-config-card, #dex-orders-card, #dex-action-bar').show();
	});

	/* ─── Preview proceed → start creation (step 3) ───────────────── */
	$(document).on('click', '#dex-proceed-btn', function () {
		var orders = $(this).data('pending') || [];
		$('#dex-preview-section').hide().attr('hidden', '');
		startCreation(orders);
	});

	/* ─── Creation loop ───────────────────────────────────────────── */
	function startCreation(orders) {
		results = {};

		var $sec = $('#dex-results-section');
		$sec.removeAttr('hidden').show();
		$('#dex-results-footer').attr('hidden', '');
		$('#dex-results-summary').attr('hidden', '');
		$('#dex-results-progress').show();
		$('#dex-step-3').addClass('dex-step--active').removeClass('dex-step--done');
		$('#dex-step-4').removeClass('dex-step--active dex-step--done');
		$('#dex-results-title').text('Kreiranje ' + orders.length + ' ' + (orders.length === 1 ? 'pošiljke' : 'pošiljki') + '…');
		$('#dex-progress-fill').css('width', '0%');
		$('#dex-progress-text').text('0 / ' + orders.length);

		var $tbody = $('#dex-results-tbody').empty();
		orders.forEach(function (o) {
			$tbody.append(buildResultRow(o));
		});

		var secTop = ($sec.offset() || {}).top || 0;
		$('html,body').animate({ scrollTop: secTop - 30 }, 250);
		saveNext(orders, 0, 0);
	}

	function buildResultRow(o) {
		return '<tr id="dex-rrow-' + o.orderId + '">' +
			'<td>#' + esc(o.number) + '</td>' +
			'<td>' + esc(o.customer) + '</td>' +
			'<td class="dex-rrow-track" id="dex-rtrack-' + o.orderId + '">—</td>' +
			'<td><span class="dex-badge dex-badge--muted dex-rrow-status" id="dex-rstatus-' + o.orderId + '">' +
				esc(i18n.saving || 'Kreiranje…') + '</span></td>' +
			'<td id="dex-raction-' + o.orderId + '"></td>' +
			'</tr>';
	}

	function saveNext(orders, idx, doneCount) {
		if (idx >= orders.length) {
			onAllSaved(orders, doneCount);
			return;
		}

		var o = orders[idx];
		setStatus(o.orderId, 'info', i18n.saving || 'Kreiranje…');

		$.ajax({
			url    : d.ajaxUrl,
			method : 'POST',
			data   : {
				action             : 'dexpress_bulk_save_shipment',
				nonce              : d.nonce,
				order_id           : o.orderId,
				sender_location_id : cfg.senderLocationId,
				self_drop_off      : cfg.selfDropOff ? '1' : '0',
				content            : o.content,
				note               : o.note,
				weight_kg          : o.weightKg,
				dim_x              : o.dimX !== null ? o.dimX : '',
				dim_y              : o.dimY !== null ? o.dimY : '',
				dim_z              : o.dimZ !== null ? o.dimZ : '',
			},
			success : function (resp) {
				if (resp.success) {
					var rd = resp.data;
					results[o.orderId] = {
						shipmentId   : rd.shipment_id,
						trackingCode : rd.tracking_code,
						labelUrl     : rd.label_url,
						status       : 'saved',
						number       : o.number,
					};
					setStatus(o.orderId, 'success', i18n.saved || 'Kreirano');
					$('#dex-rtrack-' + o.orderId).text(rd.tracking_code);
					$('#dex-raction-' + o.orderId).html(
						'<a href="' + rd.label_url + '" target="_blank" class="dex-btn dex-btn--xs dex-btn--outline">' +
						esc(i18n.print || 'Štampaj') + '</a>'
					);
					doneCount++;
				} else {
					var msg = (resp.data && resp.data.message) ? resp.data.message : 'Greška';
					results[o.orderId] = { status: 'error', error: msg, number: o.number };
					setStatus(o.orderId, 'error', i18n.error || 'Greška');
					$('#dex-rrow-' + o.orderId).addClass('dex-rrow--error');
					$('#dex-raction-' + o.orderId).html('<span class="dex-rrow-errmsg">' + esc(msg) + '</span>');
				}
			},
			error   : function (jqXHR) {
				var json = jqXHR.responseJSON;
				var msg  = (json && json.data && json.data.message)
					? json.data.message
					: (jqXHR.status === 0 ? 'Mrežna greška' : 'Serverska greška');
				results[o.orderId] = { status: 'error', error: msg, number: o.number };
				setStatus(o.orderId, 'error', i18n.error || 'Greška');
				$('#dex-rrow-' + o.orderId).addClass('dex-rrow--error');
				$('#dex-raction-' + o.orderId).html('<span class="dex-rrow-errmsg">' + esc(msg) + '</span>');
			},
			complete: function () {
				var done = idx + 1;
				$('#dex-progress-fill').css('width', Math.round(done / orders.length * 100) + '%');
				$('#dex-progress-text').text(done + ' / ' + orders.length);
				saveNext(orders, idx + 1, doneCount);
			},
		});
	}

	function onAllSaved(orders, doneCount) {
		var total  = orders.length;
		var failed = total - doneCount;

		$('#dex-results-progress').hide();
		$('#dex-step-3').removeClass('dex-step--active').addClass('dex-step--done');
		$('#dex-step-4').addClass('dex-step--active');
		$('#dex-results-title').text(
			failed === 0
				? (i18n.allDone || 'Sve pošiljke su kreirane.')
				: (doneCount + '/' + total + ' ' + (i18n.createdCount || 'kreirano') +
				   (failed > 0 ? ' — ' + failed + ' grešaka' : ''))
		);

		var $errList = $('#dex-results-errs').attr('hidden', '').empty();
		if (failed > 0) {
			$errList.removeAttr('hidden');
			Object.keys(results).forEach(function (orderId) {
				var r = results[orderId];
				if (r.status === 'error') {
					$errList.append('<li>#' + esc(r.number || orderId) + ': ' + esc(r.error || 'Nepoznata greška') + '</li>');
				}
			});
		}

		var savedIds = Object.values(results)
			.filter(function (r) { return r.status === 'saved'; })
			.map(function (r) { return r.shipmentId; });

		if (savedIds.length > 0) {
			var printUrl = d.labelBaseUrl + '?page=dexpress-label&shipment_ids=' +
				savedIds.join(',') + '&nonce=' + encodeURIComponent(d.bulkPrintNonce);
			$('#dex-print-all-btn')
				.data('print-url', printUrl)
				.text('🖨 ' + (i18n.printAll || 'Štampaj sve etikete') + ' (' + savedIds.length + ')');
		} else {
			$('#dex-print-all-btn').prop('disabled', true);
		}

		$('#dex-send-all-btn')
			.text('→ ' + (i18n.sendAll || 'Pošalji D-Expressu') + ' (' + savedIds.length + ')')
			.prop('disabled', savedIds.length === 0);

		$('#dex-results-footer').removeAttr('hidden');
	}

	/* ─── Print all ───────────────────────────────────────────────── */
	$(document).on('click', '#dex-print-all-btn', function () {
		var url = $(this).data('print-url');
		if (url) { window.open(url, '_blank'); }
	});

	/* ─── Send all ────────────────────────────────────────────────── */
	$(document).on('click', '#dex-send-all-btn', function () {
		if (!window.confirm(i18n.confirmSend || 'Pošaljite pošiljke D-Expressu? Ova akcija je nepovratna.')) { return; }

		$('#dex-results-footer').attr('hidden', '');
		$('#dex-results-progress').show();

		var toSend = Object.keys(results)
			.filter(function (id) { return results[id].status === 'saved'; })
			.map(function (id) { return { orderId: id, shipmentId: results[id].shipmentId }; });

		toSend.forEach(function (item) {
			setStatus(item.orderId, 'info', i18n.sending || 'Slanje…');
			$('#dex-raction-' + item.orderId).empty();
		});

		$('#dex-progress-fill').css('width', '0%');
		$('#dex-progress-text').text('0 / ' + toSend.length);
		$('#dex-results-title').text('Slanje ' + toSend.length + ' pošiljki D-Expressu…');

		sendNext(toSend, 0, 0);
	});

	/* ─── Send loop ───────────────────────────────────────────────── */
	function sendNext(items, idx, doneCount) {
		if (idx >= items.length) {
			onAllSent(items, doneCount);
			return;
		}

		var item = items[idx];

		$.ajax({
			url    : d.ajaxUrl,
			method : 'POST',
			data   : {
				action      : 'dexpress_bulk_send_shipment',
				nonce       : d.sendNonce,
				shipment_id : item.shipmentId,
			},
			success : function (resp) {
				if (resp.success) {
					results[item.orderId].status = 'sent';
					setStatus(item.orderId, 'success', i18n.sent || 'Poslato');
					doneCount++;
				} else {
					var msg = (resp.data && resp.data.message) ? resp.data.message : 'Greška';
					results[item.orderId].status    = 'send_error';
					results[item.orderId].sendError = msg;
					setStatus(item.orderId, 'error', i18n.error || 'Greška');
					$('#dex-rrow-' + item.orderId).addClass('dex-rrow--error');
					$('#dex-raction-' + item.orderId).html('<span class="dex-rrow-errmsg">' + esc(msg) + '</span>');
				}
			},
			error   : function (jqXHR) {
				var json = jqXHR.responseJSON;
				var msg  = (json && json.data && json.data.message)
					? json.data.message
					: (jqXHR.status === 0 ? 'Mrežna greška' : 'Serverska greška');
				results[item.orderId].status    = 'send_error';
				results[item.orderId].sendError = msg;
				setStatus(item.orderId, 'error', i18n.error || 'Greška');
				$('#dex-rrow-' + item.orderId).addClass('dex-rrow--error');
				$('#dex-raction-' + item.orderId).html('<span class="dex-rrow-errmsg">' + esc(msg) + '</span>');
			},
			complete: function () {
				var done = idx + 1;
				$('#dex-progress-fill').css('width', Math.round(done / items.length * 100) + '%');
				$('#dex-progress-text').text(done + ' / ' + items.length);
				sendNext(items, idx + 1, doneCount);
			},
		});
	}

	function onAllSent(items, doneCount) {
		var total  = items.length;
		var failed = total - doneCount;

		$('#dex-results-progress').hide();
		$('#dex-step-4').removeClass('dex-step--active').addClass('dex-step--done');
		$('#dex-results-title').text(
			failed === 0
				? (i18n.allSent || 'Sve pošiljke su poslate D-Expressu.')
				: (doneCount + ' od ' + total + ' pošiljki poslato' +
				   (failed > 0 ? ' — ' + failed + ' grešaka' : ''))
		);

		var $errList = $('#dex-results-errs').attr('hidden', '').empty();
		if (failed > 0) {
			$errList.removeAttr('hidden');
			Object.keys(results).forEach(function (orderId) {
				var r = results[orderId];
				if (r.status === 'send_error') {
					$errList.append('<li>#' + esc(r.number || orderId) + ': ' + esc(r.sendError || 'Greška pri slanju') + '</li>');
				}
			});
		}

		var $stats = $('#dex-summary-stats').empty();
		$stats.append(
			'<span class="dex-stat dex-stat--success">✓ ' + doneCount + ' ' + esc(i18n.sentCount || 'poslato') + '</span>'
		);
		if (failed > 0) {
			$stats.append(
				'<span class="dex-stat dex-stat--error">✕ ' + failed + ' ' + esc(i18n.errorCount || 'greška') + '</span>'
			);
		}

		var codes = Object.values(results)
			.filter(function (r) { return r.status === 'sent' && r.trackingCode; })
			.map(function (r) { return r.trackingCode; });

		if (codes.length > 0) {
			$('#dex-tracking-textarea').val(codes.join('\n'));
			$('#dex-summary-tracking').removeAttr('hidden');
		}

		$('#dex-results-summary').removeAttr('hidden');
	}

	/* ─── Copy tracking codes ─────────────────────────────────────── */
	$(document).on('click', '#dex-copy-btn', function () {
		var text = $('#dex-tracking-textarea').val();
		var $btn = $(this);
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function () {
				$btn.text(i18n.copied || 'Kopirano!');
				setTimeout(function () { $btn.text(i18n.copyTracking || 'Kopiraj kodove'); }, 2000);
			});
		} else {
			$('#dex-tracking-textarea').select();
			document.execCommand('copy');
		}
	});

	/* ═══ PENDING SEND CARD ══════════════════════════════════════════ */

	/* ─── Pending: send all button ───────────────────────────────── */
	$(document).on('click', '#dex-pending-send-btn', function () {
		if (!window.confirm(i18n.confirmPendingSend || 'Poslati sve čekajuće pošiljke D-Expressu? Ova akcija je nepovratna.')) {
			return;
		}

		var items = [];
		$('#dex-pending-tbody .dex-prow').each(function () {
			var id = parseInt($(this).data('shipment-id'), 10);
			if (id > 0) { items.push(id); }
		});

		if (items.length === 0) { return; }

		$('#dex-pending-send-btn, #dex-pending-print-btn').prop('disabled', true);
		$('#dex-pending-progress').removeAttr('hidden');
		$('#dex-pending-fill').css('width', '0%');
		$('#dex-pending-progress-text').text('0 / ' + items.length);

		sendPendingNext(items, 0, 0);
	});

	function sendPendingNext(items, idx, doneCount) {
		if (idx >= items.length) {
			onPendingAllSent(items.length, doneCount);
			return;
		}

		var shipmentId = items[idx];
		setPendingRowStatus(shipmentId, 'info', i18n.sending || 'Slanje…');

		$.ajax({
			url    : d.ajaxUrl,
			method : 'POST',
			data   : {
				action      : 'dexpress_bulk_send_shipment',
				nonce       : d.sendNonce,
				shipment_id : shipmentId,
			},
			success : function (resp) {
				if (resp.success) {
					setPendingRowStatus(shipmentId, 'success', i18n.sent || 'Poslato');
					doneCount++;
				} else {
					var msg = (resp.data && resp.data.message) ? resp.data.message : (i18n.error || 'Greška');
					setPendingRowStatus(shipmentId, 'error', msg);
				}
			},
			error : function (jqXHR) {
				var json = jqXHR.responseJSON;
				var msg  = (json && json.data && json.data.message)
					? json.data.message
					: (jqXHR.status === 0 ? 'Mrežna greška' : 'Serverska greška');
				setPendingRowStatus(shipmentId, 'error', msg);
			},
			complete : function () {
				var done = idx + 1;
				$('#dex-pending-fill').css('width', Math.round(done / items.length * 100) + '%');
				$('#dex-pending-progress-text').text(done + ' / ' + items.length);
				sendPendingNext(items, idx + 1, doneCount);
			},
		});
	}

	function onPendingAllSent(total, doneCount) {
		$('#dex-pending-progress').attr('hidden', '');
		var failed = total - doneCount;
		var $btn   = $('#dex-pending-send-btn');

		if (failed === 0) {
			$btn.html('<span class="dashicons dashicons-yes-alt"></span> ' + esc(i18n.pendingAllSent || 'Sve poslato!'))
				.removeClass('dex-btn--primary')
				.addClass('dex-btn--success');
			$('#dex-pending-card__title-text, .dex-pending-card__title').text(
				i18n.pendingAllSent || 'Sve pošiljke su poslate D-Expressu.'
			);
			$('#dex-pending-count').text('✓').css('background', '#059669');
		} else {
			$btn.text(failed + ' ' + (i18n.errorCount || 'greška') + ' — pokušajte ponovo')
				.prop('disabled', false);
		}
	}

	function setPendingRowStatus(shipmentId, type, text) {
		var cls = {
			info   : 'dex-badge dex-badge--info',
			success: 'dex-badge dex-badge--success',
			error  : 'dex-badge dex-badge--error',
		};
		$('#dex-prow-' + shipmentId + ' .dex-prow__status')
			.html('<span class="' + (cls[type] || 'dex-badge') + '">' + esc(text) + '</span>');
	}

	/* ─── Helpers ─────────────────────────────────────────────────── */
	function setStatus(orderId, type, text) {
		var cls = {
			info   : 'dex-badge dex-badge--info dex-rrow-status',
			success: 'dex-badge dex-badge--success dex-rrow-status',
			error  : 'dex-badge dex-badge--error dex-rrow-status',
			muted  : 'dex-badge dex-badge--muted dex-rrow-status',
		};
		$('#dex-rstatus-' + orderId)
			.attr('class', cls[type] || cls.muted)
			.text(text);
	}

	function esc(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

})(jQuery);
