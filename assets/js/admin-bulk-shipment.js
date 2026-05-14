/* global dexShipments */
(function ($) {
	'use strict';

	var d    = window.dexShipments || {};
	var i18n = d.i18n || {};

	/* ─── Global config state ─────────────────────────────────────── */
	var cfg = {
		senderLocationId : 0,
		deliveryType     : 2,
		paymentType      : 2,
		returnDoc        : 0,
		selfDropOff      : false,
		defaultContent   : '',
		defaultNote      : '',
		defaultWeightKg  : 0,
		defaultDimX      : 0,
		defaultDimY      : 0,
		defaultDimZ      : 0,
	};

	/* Results map: orderId → { shipmentId, trackingCode, labelUrl, status } */
	var results = {};

	/* ─── Init from DOM ───────────────────────────────────────────── */
	$(function () {
		cfg.senderLocationId = parseInt($('#dex-cfg-location').val(), 10) || 0;
		cfg.deliveryType     = parseInt($('#dex-cfg-delivery').val(), 10) || 2;
		cfg.paymentType      = parseInt($('#dex-cfg-payment').val(), 10) || 2;
		cfg.returnDoc        = parseInt($('#dex-cfg-returndoc').val(), 10) || 0;
		cfg.selfDropOff      = $('#dex-cfg-selfdrop').is(':checked');
		updateActionBar();
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

		applyDefaultsToAllRows();
	});

	/* ─── Config field live-sync ──────────────────────────────────── */
	$(document).on('change input', '#dex-cfg-weight',    function () { cfg.defaultWeightKg = parseFloat($(this).val()) || 0; applyDefaultsToAllRows(); });
	$(document).on('change input', '#dex-cfg-dim-x',     function () { cfg.defaultDimX = parseFloat($(this).val()) || 0; applyDefaultsToAllRows(); });
	$(document).on('change input', '#dex-cfg-dim-y',     function () { cfg.defaultDimY = parseFloat($(this).val()) || 0; applyDefaultsToAllRows(); });
	$(document).on('change input', '#dex-cfg-dim-z',     function () { cfg.defaultDimZ = parseFloat($(this).val()) || 0; applyDefaultsToAllRows(); });
	$(document).on('change input', '#dex-cfg-content',   function () { cfg.defaultContent = $(this).val(); applyDefaultsToAllRows(); });
	$(document).on('change input', '#dex-cfg-note',      function () { cfg.defaultNote = $(this).val(); applyDefaultsToAllRows(); });
	$(document).on('change',       '#dex-cfg-location',  function () { cfg.senderLocationId = parseInt($(this).val(), 10) || 0; });
	$(document).on('change',       '#dex-cfg-delivery',  function () { cfg.deliveryType = parseInt($(this).val(), 10) || 2; });
	$(document).on('change',       '#dex-cfg-payment',   function () { cfg.paymentType  = parseInt($(this).val(), 10) || 2; });
	$(document).on('change',       '#dex-cfg-returndoc', function () { cfg.returnDoc    = parseInt($(this).val(), 10) || 0; });
	$(document).on('change',       '#dex-cfg-selfdrop',  function () { cfg.selfDropOff  = this.checked; });

	/* ─── Apply global defaults to row fields ─────────────────────── */
	function applyDefaultsToAllRows() {
		$('.dex-order-row').each(function () {
			applyDefaultsToRow($(this));
		});
	}

	function applyDefaultsToRow($row) {
		var $w  = $row.find('.dex-row-weight');
		var $dx = $row.find('.dex-row-dim-x');
		var $dy = $row.find('.dex-row-dim-y');
		var $dz = $row.find('.dex-row-dim-z');
		var $c  = $row.find('.dex-row-content');
		var $n  = $row.find('.dex-row-note');

		if (!$w.data('dirty')  && cfg.defaultWeightKg > 0) { $w.val(cfg.defaultWeightKg.toFixed(2)); }
		if (!$dx.data('dirty') && cfg.defaultDimX > 0)      { $dx.val(cfg.defaultDimX); }
		if (!$dy.data('dirty') && cfg.defaultDimY > 0)      { $dy.val(cfg.defaultDimY); }
		if (!$dz.data('dirty') && cfg.defaultDimZ > 0)      { $dz.val(cfg.defaultDimZ); }
		if (!$c.data('dirty')  && cfg.defaultContent !== '') { $c.val(cfg.defaultContent); }
		if (!$n.data('dirty'))                               { $n.val(cfg.defaultNote); }
	}

	/* ─── Mark row fields dirty on manual edit ────────────────────── */
	$(document).on('input', '.dex-row-weight, .dex-row-dim-x, .dex-row-dim-y, .dex-row-dim-z, .dex-row-content', function () {
		$(this).data('dirty', true);
	});

	/* ─── Reset row to global defaults ───────────────────────────── */
	$(document).on('click', '.dex-row-reset', function () {
		var $row = $(this).closest('.dex-order-row');
		$row.find('.dex-row-weight, .dex-row-dim-x, .dex-row-dim-y, .dex-row-dim-z, .dex-row-content, .dex-row-note')
			.each(function () { $(this).data('dirty', false); });
		applyDefaultsToRow($row);
	});

	/* ─── Filter tabs ─────────────────────────────────────────────── */
	$(document).on('click', '.dex-filter-tab', function () {
		$('.dex-filter-tab').removeClass('dex-filter-tab--active');
		$(this).addClass('dex-filter-tab--active');
		var filter = $(this).data('filter');

		$('.dex-order-row').each(function () {
			var $row = $(this);
			var show = true;
			if (filter === 'cod'  && $row.data('cod')  !== 1) { show = false; }
			if (filter === 'shop' && $row.data('shop') !== 1) { show = false; }

			if (!show) {
				$row.addClass('dex-order-row--hidden');
				$row.find('.dex-order-cb').prop('checked', false);
			} else {
				$row.removeClass('dex-order-row--hidden');
			}
		});

		syncSelectAll();
		updateActionBar();
	});

	/* ─── Select-all ──────────────────────────────────────────────── */
	$(document).on('change', '#dex-select-all', function () {
		$('.dex-order-row:not(.dex-order-row--hidden) .dex-order-cb').prop('checked', this.checked);
		updateActionBar();
	});

	$(document).on('change', '.dex-order-cb', function () {
		syncSelectAll();
		updateActionBar();
	});

	function syncSelectAll() {
		var $visible = $('.dex-order-row:not(.dex-order-row--hidden) .dex-order-cb');
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
			$btn.text('Kreiraj pošiljke (' + count + ')');
			$('#dex-action-info').text(count + ' ' + (count === 1 ? 'narudžbina izabrana' : 'narudžbine izabrane'));
		} else {
			$btn.text('Kreiraj pošiljke');
			$('#dex-action-info').text('Izaberite narudžbine za kreiranje pošiljki');
		}
	}

	/* ─── Validation ──────────────────────────────────────────────── */
	function validateConfig() {
		var errors = [];

		if (!cfg.senderLocationId) {
			errors.push(i18n.locationReq || 'Izaberite lokaciju pošiljaoca.');
		}
		if (!$('#dex-cfg-content').val().trim()) {
			errors.push(i18n.contentReq || 'Unesite sadržaj paketa.');
		}

		var weightErr = false;
		$('.dex-order-cb:checked').each(function () {
			var $row   = $(this).closest('.dex-order-row');
			var weight = parseFloat($row.find('.dex-row-weight').val()) || 0;
			if (weight <= 0) { weightErr = true; }
		});
		if (weightErr) {
			errors.push(i18n.weightReq || 'Masa mora biti veća od 0 za sve izabrane narudžbine.');
		}

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

	/* ─── Create button ───────────────────────────────────────────── */
	$(document).on('click', '#dex-create-btn', function () {
		if (!validateConfig()) {
			var errTop = ($('#dex-config-errors').offset() || {}).top || 0;
			$('html,body').animate({ scrollTop: errTop - 60 }, 250);
			return;
		}

		var toCreate = [];
		$('.dex-order-cb:checked').each(function () {
			var $row    = $(this).closest('.dex-order-row');
			var orderId = String($(this).val());
			var info    = (d.orders || []).find(function (o) { return String(o.id) === orderId; }) || {};

			toCreate.push({
				orderId  : orderId,
				number   : info.number   || orderId,
				customer : info.customer || '',
				weightKg : parseFloat($row.find('.dex-row-weight').val()) || cfg.defaultWeightKg,
				dimX     : parseFloat($row.find('.dex-row-dim-x').val()) || cfg.defaultDimX || null,
				dimY     : parseFloat($row.find('.dex-row-dim-y').val()) || cfg.defaultDimY || null,
				dimZ     : parseFloat($row.find('.dex-row-dim-z').val()) || cfg.defaultDimZ || null,
				content  : $row.find('.dex-row-content').val().trim() || cfg.defaultContent,
				note     : $row.find('.dex-row-note').val().trim()    || cfg.defaultNote,
			});
		});

		startCreation(toCreate);
	});

	/* ─── Creation loop ───────────────────────────────────────────── */
	function startCreation(orders) {
		results = {};

		$('#dex-orders-card, #dex-action-bar').hide();

		var $sec = $('#dex-results-section');
		$sec.removeAttr('hidden').show();
		$('#dex-results-footer').attr('hidden', '');
		$('#dex-results-summary').attr('hidden', '');
		$('#dex-results-progress').show();
		$('#dex-results-title').text('Kreiranje ' + orders.length + ' pošiljki…');
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
				delivery_type      : cfg.deliveryType,
				payment_type       : cfg.paymentType,
				return_doc         : cfg.returnDoc,
				self_drop_off      : cfg.selfDropOff ? '1' : '',
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
					results[o.orderId] = { status: 'error', error: msg };
					setStatus(o.orderId, 'error', i18n.error || 'Greška');
					$('#dex-rrow-' + o.orderId).addClass('dex-rrow--error');
					$('#dex-raction-' + o.orderId).html('<span class="dex-rrow-errmsg">' + esc(msg) + '</span>');
				}
			},
			error   : function () {
				results[o.orderId] = { status: 'error', error: 'Mrežna greška' };
				setStatus(o.orderId, 'error', i18n.error || 'Greška');
				$('#dex-rrow-' + o.orderId).addClass('dex-rrow--error');
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
		$('#dex-results-title').text(
			failed === 0
				? (i18n.allDone || 'Sve pošiljke su kreirane.')
				: (doneCount + '/' + total + ' ' + (i18n.createdCount || 'kreirano') +
				   (failed > 0 ? ' — ' + failed + ' grešaka' : ''))
		);

		var savedIds = Object.values(results)
			.filter(function (r) { return r.status === 'saved'; })
			.map(function (r) { return r.shipmentId; });

		if (savedIds.length > 0) {
			var printUrl = d.labelBaseUrl + '?page=dexpress-label&shipment_ids=' +
				savedIds.join(',') + '&nonce=' + encodeURIComponent(d.bulkPrintNonce);
			$('#dex-print-all-btn')
				.data('print-url', printUrl)
				.text((i18n.printAll || 'Štampaj sve etikete') + ' (' + savedIds.length + ')');
		} else {
			$('#dex-print-all-btn').prop('disabled', true);
		}

		$('#dex-send-all-btn')
			.text((i18n.sendAll || 'Pošalji D-Expressu') + ' (' + savedIds.length + ')')
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
					results[item.orderId].status = 'send_error';
					setStatus(item.orderId, 'error', i18n.error || 'Greška');
					$('#dex-rrow-' + item.orderId).addClass('dex-rrow--error');
					$('#dex-raction-' + item.orderId).html('<span class="dex-rrow-errmsg">' + esc(msg) + '</span>');
				}
			},
			error   : function () {
				results[item.orderId].status = 'send_error';
				setStatus(item.orderId, 'error', i18n.error || 'Greška');
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
		$('#dex-results-title').text(
			failed === 0
				? (i18n.allSent || 'Sve pošiljke su poslate D-Expressu.')
				: (doneCount + ' od ' + total + ' pošiljki poslato' +
				   (failed > 0 ? ' — ' + failed + ' grešaka' : ''))
		);

		var $stats = $('#dex-summary-stats').empty();
		$stats.append(
			'<span class="dex-stat dex-stat--success">' + doneCount + ' ' + esc(i18n.sentCount || 'poslato') + '</span>'
		);
		if (failed > 0) {
			$stats.append(
				'<span class="dex-stat dex-stat--error">' + failed + ' ' + esc(i18n.errorCount || 'greška') + '</span>'
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

	/* ─── Copy tracking codes ──────────────────────────────────────── */
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
