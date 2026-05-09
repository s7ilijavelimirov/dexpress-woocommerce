/* global jQuery, dexpress_package_shop, google, markerClusterer */
(function ($) {
    'use strict';

    if (typeof dexpress_package_shop === 'undefined' || window.__dexpressPackageShopCheckoutInit === true) {
        return;
    }
    window.__dexpressPackageShopCheckoutInit = true;

    var METHOD_ID = String(dexpress_package_shop.method_id || 'dexpress_package_shop');
    var LOADING_LOGO_URL = String(dexpress_package_shop.loading_logo_url || '');

    var uiState = { activeRateId: '' };
    var mapState = {
        map: null,
        markers: [],
        markerCluster: null,
        markerById: {},
        infoWindow: null,
        clusterProjectionView: null,
        clusterPopupEl: null,
        clusterCleanupListeners: [],
        dispensers: [],
        filtered: [],
        activeDispenserId: null,
        selectedDispenser: null,
    };

    function escapeHtml(text) {
        return $('<div>').text(String(text || '')).html();
    }

    function normalizeSearchText(value) {
        var text = String(value || '');
        var map = {
            'š': 's', 'Š': 's',
            'đ': 'd', 'Đ': 'd',
            'ž': 'z', 'Ž': 'z',
            'č': 'c', 'Č': 'c',
            'ć': 'c', 'Ć': 'c',
            'lj': 'lj', 'Lj': 'lj', 'LJ': 'lj',
            'nj': 'nj', 'Nj': 'nj', 'NJ': 'nj',
        };

        return text.replace(/lj|Lj|LJ|nj|Nj|NJ|[šŠđĐžŽčČćĆ]/g, function (match) {
            return map[match] || match;
        }).toLowerCase();
    }

    function getOnboardingModal() { return $('[data-dexpress-package-shop-onboarding-modal="1"]').first(); }
    function getBrowserModal() { return $('[data-dexpress-package-shop-browser-modal="1"]').first(); }

    function ensureCheckoutHiddenField() {
        var $existing = $('#dexpress-checkout-dispenser-id');
        if ($existing.length) {
            return $existing.first();
        }

        var $targetForm = $('form.checkout').first();
        if (!$targetForm.length) {
            $targetForm = $('form.wc-block-checkout__form').first();
        }
        if (!$targetForm.length) {
            return $();
        }

        var $input = $('<input type="hidden" id="dexpress-checkout-dispenser-id" name="dexpress_checkout_dispenser_id" value="" />');
        $targetForm.append($input);
        return $input;
    }

    function getBrowserHiddenField() {
        return $('#dexpress-selected-dispenser-id').first();
    }

    function getBrowserLocationTypeField() {
        return $('#dexpress-selected-location-type').first();
    }

    function ensureCheckoutLocationTypeField() {
        var $existing = $('#dexpress-checkout-location-type');
        if ($existing.length) {
            return $existing.first();
        }

        var $targetForm = $('form.checkout').first();
        if (!$targetForm.length) {
            $targetForm = $('form.wc-block-checkout__form').first();
        }
        if (!$targetForm.length) {
            return $();
        }

        var $input = $('<input type="hidden" id="dexpress-checkout-location-type" name="dexpress_checkout_location_type" value="" />');
        $targetForm.append($input);
        return $input;
    }

    function getStoredSelectedDispenserId() {
        var browserValue = String(getBrowserHiddenField().val() || '');
        if (browserValue !== '') {
            return Number(browserValue);
        }
        var checkoutValue = String($('#dexpress-checkout-dispenser-id').first().val() || '');
        return checkoutValue !== '' ? Number(checkoutValue) : 0;
    }

    function buildSelectedDispenserPayload(dispenser) {
        if (!dispenser) {
            return null;
        }

        var payment = [];
        if (dispenser.pay_by_card) { payment.push('kartica'); }
        if (dispenser.pay_by_cash) { payment.push('gotovina'); }

        return {
            id: Number(dispenser.id || 0),
            name: String(dispenser.name || ''),
            address: String(dispenser.address || ''),
            city: String(dispenser.town_name || ''),
            location_type: String(dispenser.location_type || ''),
            location_type_label: String(dispenser.location_type_label || ''),
            working_hours: String(dispenser.work_hours || ''),
            working_days: String(dispenser.work_days || ''),
            payment: payment.join(', '),
        };
    }

    function setSelectedDispenser(dispenser) {
        mapState.selectedDispenser = buildSelectedDispenserPayload(dispenser);
        var selectedId = mapState.selectedDispenser ? String(mapState.selectedDispenser.id) : '';
        var locationType = mapState.selectedDispenser ? String(mapState.selectedDispenser.location_type || '') : '';
        getBrowserHiddenField().val(selectedId);
        getBrowserLocationTypeField().val(locationType);
        ensureCheckoutHiddenField().val(selectedId);
        ensureCheckoutLocationTypeField().val(locationType);
        clearPanelValidationError();
        refreshSelectionUI();
    }

    function clearSelectedDispenser() {
        mapState.selectedDispenser = null;
        getBrowserHiddenField().val('');
        getBrowserLocationTypeField().val('');
        ensureCheckoutHiddenField().val('');
        ensureCheckoutLocationTypeField().val('');
        refreshSelectionUI();
    }

    function resolveLocationTypeColors(locationType, isSelected) {
        var type = String(locationType || '2');
        if (type === '1') {
            return isSelected
                ? { fill: '#0D47A1', center: '#ffffff' }
                : { fill: '#D6E6FF', center: '#0D47A1' };
        }
        if (type === '3') {
            return isSelected
                ? { fill: '#1B5E20', center: '#ffffff' }
                : { fill: '#DDF3E1', center: '#1B5E20' };
        }

        return isSelected
            ? { fill: '#000000', center: '#ffffff' }
            : { fill: '#ffffff', center: '#000000' };
    }

    function getMarkerIconSvg(locationType, isSelected) {
        var colors = resolveLocationTypeColors(locationType, isSelected);
        return '' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40">' +
            '<path d="M16 0 C7.16 0 0 7.16 0 16 C0 28 16 40 16 40 C16 40 32 28 32 16 C32 7.16 24.84 0 16 0Z" fill="' + colors.fill + '" stroke="#000000" stroke-width="2" />' +
            '<circle cx="16" cy="16" r="5" fill="' + colors.center + '" />' +
            '</svg>';
    }

    function getMarkerIconConfig(locationType, isSelected) {
        return {
            url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(getMarkerIconSvg(locationType, isSelected)),
            scaledSize: new google.maps.Size(32, 40),
            anchor: new google.maps.Point(16, 40),
        };
    }

    function applySelectedMarkerStyles() {
        if (!(window.google && window.google.maps)) {
            return;
        }
        var selectedId = mapState.selectedDispenser ? Number(mapState.selectedDispenser.id) : 0;
        Object.keys(mapState.markerById).forEach(function (key) {
            var marker = mapState.markerById[key];
            if (!marker || typeof marker.setIcon !== 'function') {
                return;
            }
            var entry = mapState.dispensers.find(function (item) { return Number(item.id) === Number(key); });
            var type = entry ? String(entry.location_type || '2') : '2';
            marker.setIcon(getMarkerIconConfig(type, Number(key) === selectedId));
        });
    }

    function refreshSelectionUI() {
        var selectedId = mapState.selectedDispenser ? Number(mapState.selectedDispenser.id) : 0;
        var $panels = $('[data-dexpress-package-shop-panel="1"]');

        $panels.each(function () {
            var $panel = $(this);
            var $selectedPanel = $panel.find('[data-dexpress-ps-selected-panel="1"]').first();
            var $actions = $panel.find('.dexpress-package-shop-panel__actions').first();

            if (!selectedId || !mapState.selectedDispenser) {
                $selectedPanel.prop('hidden', true);
                $actions.prop('hidden', false);
                return;
            }

            $selectedPanel.find('[data-dexpress-ps-selected-name="1"]').text(mapState.selectedDispenser.name);
            $selectedPanel.find('[data-dexpress-ps-selected-city="1"]').text(mapState.selectedDispenser.city);
            $selectedPanel.find('[data-dexpress-ps-selected-address="1"]').text(mapState.selectedDispenser.address);
            $selectedPanel.prop('hidden', false);
            $actions.prop('hidden', true);
        });

        var $list = getBrowserModal().find('[data-dexpress-package-shop-list="1"]');
        $list.find('[data-dexpress-dispenser-item="1"]').removeClass('is-selected');
        if (selectedId) {
            $list.find('[data-dexpress-dispenser-id="' + selectedId + '"]').addClass('is-selected');
        }

        applySelectedMarkerStyles();
    }

    function restoreSelectedDispenserFromHiddenField() {
        var selectedId = getStoredSelectedDispenserId();
        if (!selectedId) {
            mapState.selectedDispenser = null;
            refreshSelectionUI();
            return;
        }

        var dispenser = mapState.dispensers.find(function (entry) {
            return Number(entry.id) === selectedId;
        });
        if (!dispenser) {
            mapState.selectedDispenser = null;
            refreshSelectionUI();
            return;
        }

        setSelectedDispenser(dispenser);
    }

    function isMobileViewport() {
        return window.matchMedia('(max-width: 768px)').matches;
    }

    function setMobileDrawerOpen(isOpen) {
        var $browser = getBrowserModal();
        if (!$browser.length) {
            return;
        }
        $browser.toggleClass('is-mobile-drawer-open', !!isOpen);
    }

    function getSelectedRateId() {
        var $classic = $('input[name^="shipping_method["]:checked');
        if ($classic.length) { return String($classic.val() || ''); }

        var $block = $('input[name^="wc-block-shipping-method"]:checked');
        if ($block.length) { return String($block.val() || ''); }

        var $fallbackRate = $('.wc-block-checkout input[type="radio"]:checked[value*="' + METHOD_ID + '"]');
        return $fallbackRate.length ? String($fallbackRate.val() || '') : '';
    }

    function isPackageShopRate(rateId) { return rateId.indexOf(METHOD_ID) === 0; }

    function getSelectedPanel() {
        var rateId = getSelectedRateId();
        if (!isPackageShopRate(rateId)) { return $(); }
        return $('[data-dexpress-package-shop-panel="1"][data-rate-id="' + rateId + '"]').first();
    }

    function findSelectedBlockRateContainer() {
        var $selectedInput = $('input[name^="wc-block-shipping-method"]:checked').first();
        if (!$selectedInput.length) { $selectedInput = $('.wc-block-checkout input[type="radio"]:checked').first(); }
        if (!$selectedInput.length) { return $(); }

        var $container = $selectedInput.closest('.wc-block-components-radio-control__option,.wc-block-components-shipping-rates-control__package,li');
        return $container.length ? $container.first() : $selectedInput.parent();
    }

    function mountBlockPanel($panel) {
        var $target = findSelectedBlockRateContainer();
        if ($target.length && $panel.parent()[0] !== $target[0]) { $panel.appendTo($target); }
    }

    function closeModal($modal) {
        if (!$modal.length || $modal.prop('hidden')) { return; }
        $modal.removeClass('is-open').attr('aria-hidden', 'true');
        window.setTimeout(function () { $modal.prop('hidden', true); }, 100);
    }

    function openModal($modal) {
        if (!$modal.length) { return; }
        $modal.prop('hidden', false).attr('aria-hidden', 'false');
        window.setTimeout(function () { $modal.addClass('is-open'); }, 10);
    }

    function closeSmallClusterMenu() {
        if (mapState.clusterPopupEl && mapState.clusterPopupEl.length) {
            mapState.clusterPopupEl.remove();
        }
        mapState.clusterPopupEl = null;
    }

    function clearClusterCleanupListeners() {
        mapState.clusterCleanupListeners.forEach(function (listener) {
            if (!listener) {
                return;
            }
            if (typeof listener.remove === 'function') {
                listener.remove();
                return;
            }
            if (window.google && window.google.maps && window.google.maps.event) {
                window.google.maps.event.removeListener(listener);
            }
        });
        mapState.clusterCleanupListeners = [];
    }

    function ensureClusterProjectionView() {
        if (!mapState.map || !(window.google && window.google.maps)) {
            return null;
        }
        if (mapState.clusterProjectionView) {
            return mapState.clusterProjectionView;
        }

        var overlay = new google.maps.OverlayView();
        overlay.onAdd = function () {};
        overlay.draw = function () {};
        overlay.onRemove = function () {};
        overlay.setMap(mapState.map);
        mapState.clusterProjectionView = overlay;

        return overlay;
    }

    function positionSmallClusterMenu(anchorPosition) {
        if (!anchorPosition || !mapState.clusterPopupEl || !mapState.clusterPopupEl.length) {
            return;
        }

        var overlay = ensureClusterProjectionView();
        if (!overlay || typeof overlay.getProjection !== 'function') {
            return;
        }

        var projection = overlay.getProjection();
        if (!projection) {
            return;
        }

        var pixel = null;
        if (typeof projection.fromLatLngToContainerPixel === 'function') {
            pixel = projection.fromLatLngToContainerPixel(anchorPosition);
        } else if (typeof projection.fromLatLngToDivPixel === 'function') {
            pixel = projection.fromLatLngToDivPixel(anchorPosition);
        }

        if (!pixel) {
            return;
        }

        mapState.clusterPopupEl.css({
            left: String(Math.round(pixel.x)) + 'px',
            top: String(Math.round(pixel.y)) + 'px',
        });
    }

    function bindClusterCleanupHandlers() {
        clearClusterCleanupListeners();
        if (!mapState.map || !(window.google && window.google.maps)) {
            return;
        }

        mapState.clusterCleanupListeners.push(
            mapState.map.addListener('click', function () { closeSmallClusterMenu(); }),
        );
        mapState.clusterCleanupListeners.push(
            mapState.map.addListener('zoom_changed', function () { closeSmallClusterMenu(); }),
        );
        mapState.clusterCleanupListeners.push(
            mapState.map.addListener('dragstart', function () { closeSmallClusterMenu(); }),
        );
    }

    function clearMarkers() {
        closeSmallClusterMenu();

        if (mapState.markerCluster) {
            if (typeof mapState.markerCluster.clearMarkers === 'function') {
                mapState.markerCluster.clearMarkers();
            }
            if (typeof mapState.markerCluster.setMap === 'function') {
                mapState.markerCluster.setMap(null);
            }
            mapState.markerCluster = null;
        }

        mapState.markers.forEach(function (marker) {
            if (marker && typeof marker.setMap === 'function') { marker.setMap(null); }
        });
        mapState.markers = [];
        mapState.markerById = {};
    }

    function destroyMap() {
        closeSmallClusterMenu();
        clearClusterCleanupListeners();
        if (mapState.clusterProjectionView && typeof mapState.clusterProjectionView.setMap === 'function') {
            mapState.clusterProjectionView.setMap(null);
        }
        mapState.clusterProjectionView = null;
        clearMarkers();
        mapState.map = null;
        mapState.infoWindow = null;
        mapState.activeDispenserId = null;
        getBrowserModal().find('[data-dexpress-package-shop-map="1"]').empty();
    }

    function closeBrowserModal() {
        closeModal(getBrowserModal());
        setMobileDrawerOpen(false);
        destroyMap();
    }

    function closeAllModals() {
        closeModal(getOnboardingModal());
        closeBrowserModal();
        $('body').removeClass('dexpress-package-shop-modal-open');
    }

    function fillOnboardingContent($panel) {
        var sourceHtml = $panel.find('[data-dexpress-package-shop-modal-content-source="1"]').first().html() || '';
        getOnboardingModal().find('[data-dexpress-package-shop-modal-content="1"]').html(sourceHtml);
    }

    function setBrowserLoadingState(isLoading) {
        var $modal = getBrowserModal();
        if (!$modal.length) { return; }
        $modal.find('[data-dexpress-package-shop-browser-loading="1"]').prop('hidden', !isLoading);
        $modal.find('[data-dexpress-package-shop-layout="1"]').prop('hidden', isLoading);
    }

    function initLoadingLogo() {
        var $modal = getBrowserModal();
        var $logo = $modal.find('[data-dexpress-package-shop-loading-logo="1"]');
        var $fallback = $modal.find('[data-dexpress-package-shop-loading-fallback="1"]');

        if (LOADING_LOGO_URL !== '') {
            $logo.attr('src', LOADING_LOGO_URL).prop('hidden', false);
            $fallback.prop('hidden', true);
            return;
        }
        $logo.prop('hidden', true);
        $fallback.prop('hidden', false);
    }

    function loadDispensers(query) {
        return $.ajax({
            url: String(dexpress_package_shop.ajax_url || ''),
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'dexpress_package_shop_dispensers',
                nonce: String(dexpress_package_shop.nonce || ''),
                q: query || '',
            },
        });
    }

    function renderMapFallbackMessage(message) {
        var $map = getBrowserModal().find('[data-dexpress-package-shop-map="1"]');
        clearMarkers();
        $map.html('<div class="dexpress-package-shop-modal__empty">' + escapeHtml(message) + '</div>');
    }

    function buildInfoWindowHtml(item) {
        var payment = [];
        if (item.pay_by_cash) { payment.push('gotovina'); }
        if (item.pay_by_card) { payment.push('kartica'); }
        var locationTypeLabel = String(item.location_type_label || '');

        return '<div class="dexpress-package-shop-infowindow">' +
            '<strong>' + escapeHtml(item.name) + '</strong><br>' +
            (locationTypeLabel ? ('<span class="dexpress-package-shop-infowindow__type">' + escapeHtml(locationTypeLabel) + '</span><br>') : '') +
            escapeHtml(item.address) + '<br>' +
            escapeHtml(item.town_name) +
            (item.municipality_name ? ' (' + escapeHtml(item.municipality_name) + ')' : '') + '<br>' +
            (item.work_hours ? ('Radno vreme: ' + escapeHtml(item.work_hours) + '<br>') : '') +
            (item.work_days ? ('Radni dani: ' + escapeHtml(item.work_days) + '<br>') : '') +
            (payment.length ? ('Plaćanje: ' + escapeHtml(payment.join(', ')) + '<br>') : '') +
            '<button type="button" class="dexpress-ps-select-btn" data-dispenser-id="' + Number(item.id || 0) + '">IZABERI</button>' +
            '</div>';
    }

    function ensureMap() {
        var $mapEl = getBrowserModal().find('[data-dexpress-package-shop-map="1"]');
        if (!$mapEl.length || !(window.google && window.google.maps)) { return false; }
        if (mapState.map) { return true; }
        $mapEl.addClass('ps-cluster-marker');

        mapState.map = new google.maps.Map($mapEl[0], {
            center: { lat: 44.0165, lng: 21.0059 },
            zoom: 7,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: false,
            zoomControl: true,
            gestureHandling: 'greedy',
        });
        mapState.infoWindow = new google.maps.InfoWindow();
        bindClusterCleanupHandlers();

        return true;
    }

    function createMarker(item) {
        if (!mapState.map || !(window.google && window.google.maps)) { return null; }
        var isSelected = mapState.selectedDispenser && Number(mapState.selectedDispenser.id) === Number(item.id);

        var marker = new google.maps.Marker({
            position: { lat: Number(item.latitude), lng: Number(item.longitude) },
            map: mapState.map,
            title: String(item.name || ''),
            icon: getMarkerIconConfig(String(item.location_type || '2'), !!isSelected),
        });
        marker.__dexpressDispenserId = Number(item.id || 0);

        marker.addListener('click', function () { focusDispenser(item.id, true); });
        return marker;
    }

    function createClusterRenderer() {
        return {
            render: function (clusterData) {
                var count = Number(clusterData.count || 0);
                var position = clusterData.position;
                var size = count >= 100 ? 52 : (count >= 20 ? 46 : 40);
                var fontSize = count >= 100 ? 15 : 13;
                var mapElement = getBrowserModal().find('[data-dexpress-package-shop-map="1"]')[0];
                var computed = mapElement ? window.getComputedStyle(mapElement) : null;
                var bgColor = computed ? (computed.getPropertyValue('--cluster-bg') || '').trim() : '';
                var textColor = computed ? (computed.getPropertyValue('--cluster-color') || '').trim() : '';
                if (bgColor === '') { bgColor = '#000000'; }
                if (textColor === '') { textColor = '#ffffff'; }
                var svg = '' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">' +
                    '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + ((size - 6) / 2) + '" fill="' + bgColor + '" stroke="#ffffff" stroke-width="3" />' +
                    '<text x="' + (size / 2) + '" y="' + (size / 2 + Math.round(fontSize / 3)) + '" text-anchor="middle" font-family="Arial, sans-serif" font-size="' + fontSize + '" font-weight="700" fill="' + textColor + '">' + count + '</text>' +
                    '</svg>';

                return new google.maps.Marker({
                    position: position,
                    icon: {
                        url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
                        scaledSize: new google.maps.Size(size, size),
                        anchor: new google.maps.Point(size / 2, size / 2),
                    },
                    label: null,
                    zIndex: 1000 + count,
                });
            },
        };
    }

    function renderMarkerClusters() {
        if (!mapState.map || mapState.markers.length === 0) {
            return;
        }
        if (!(window.markerClusterer && window.markerClusterer.MarkerClusterer)) {
            return;
        }

        var onClusterClick = function (event, cluster, map) {
            var clusterMarkers = cluster && Array.isArray(cluster.markers) ? cluster.markers : [];
            if (event && typeof event.stop === 'function') {
                event.stop();
            }

            if (clusterMarkers.length <= 3) {
                openSmallClusterMenu(clusterMarkers);
                return;
            }

            var bounds = new google.maps.LatLngBounds();
            clusterMarkers.forEach(function (marker) {
                if (marker && typeof marker.getPosition === 'function') {
                    bounds.extend(marker.getPosition());
                }
            });
            if (!bounds.isEmpty()) {
                map.fitBounds(bounds);
                if (window.google && window.google.maps && window.google.maps.event) {
                    google.maps.event.addListenerOnce(map, 'idle', function () {
                        var currentZoom = Number(map.getZoom() || 0);
                        if (currentZoom > 14) {
                            map.setZoom(14);
                        }
                    });
                }
            }
        };

        mapState.markerCluster = new window.markerClusterer.MarkerClusterer({
            map: mapState.map,
            markers: mapState.markers,
            renderer: createClusterRenderer(),
            onClusterClick: onClusterClick,
        });
    }

    function openSmallClusterMenu(clusterMarkers) {
        if (!mapState.map || !Array.isArray(clusterMarkers) || clusterMarkers.length === 0) {
            return;
        }

        var items = [];
        var anchorPosition = null;

        clusterMarkers.forEach(function (marker) {
            if (!marker || typeof marker.getPosition !== 'function') {
                return;
            }
            if (!anchorPosition) {
                anchorPosition = marker.getPosition();
            }
            var dispenserId = Number(marker.__dexpressDispenserId || 0);
            if (!dispenserId) {
                return;
            }
            var dispenser = mapState.dispensers.find(function (entry) {
                return Number(entry.id) === dispenserId;
            });
            if (!dispenser) {
                return;
            }
            items.push({
                id: dispenserId,
                name: String(dispenser.name || ''),
            });
        });

        if (items.length === 0 || !anchorPosition) {
            return;
        }

        closeSmallClusterMenu();

        var html = '<div class="dexpress-package-shop-cluster-menu">';
        items.forEach(function (entry) {
            html += '<button type="button" class="dexpress-package-shop-cluster-menu__item" data-dexpress-cluster-dispenser-id="' + entry.id + '">' + escapeHtml(entry.name) + '</button>';
        });
        html += '</div>';

        var $mapEl = getBrowserModal().find('[data-dexpress-package-shop-map="1"]').first();
        if (!$mapEl.length) {
            return;
        }

        mapState.clusterPopupEl = $('<div class="dexpress-package-shop-cluster-popup"></div>').html(html);
        $mapEl.append(mapState.clusterPopupEl);

        positionSmallClusterMenu(anchorPosition);
        window.setTimeout(function () {
            positionSmallClusterMenu(anchorPosition);
        }, 0);
    }

    function renderMarkers() {
        if (!mapState.map) { return; }
        clearMarkers();

        mapState.filtered.forEach(function (item) {
            var marker = createMarker(item);
            if (!marker) { return; }
            mapState.markers.push(marker);
            mapState.markerById[item.id] = marker;
        });

        renderMarkerClusters();
    }

    function updateMapBounds() {
        if (!mapState.map || !(window.google && window.google.maps)) { return; }
        if (mapState.markers.length === 0) {
            mapState.map.setCenter({ lat: 44.0165, lng: 21.0059 });
            mapState.map.setZoom(7);
            return;
        }

        var bounds = new google.maps.LatLngBounds();
        mapState.markers.forEach(function (marker) { bounds.extend(marker.getPosition()); });
        mapState.map.fitBounds(bounds);
    }

    function renderList() {
        var $list = getBrowserModal().find('[data-dexpress-package-shop-list="1"]');
        $list.empty();

        if (mapState.filtered.length === 0) {
            $list.append('<div class="dexpress-package-shop-modal__empty">' + escapeHtml(String((dexpress_package_shop.i18n || {}).empty || 'Nema rezultata.')) + '</div>');
            return;
        }

        mapState.filtered.forEach(function (item) {
            var $row = $('<div class="dexpress-package-shop-modal__item" data-dexpress-dispenser-item="1"></div>');
            $row.attr('data-dexpress-dispenser-id', String(item.id));
            if (mapState.activeDispenserId === item.id) { $row.addClass('is-active'); }
            if (mapState.selectedDispenser && Number(mapState.selectedDispenser.id) === Number(item.id)) { $row.addClass('is-selected'); }

            var municipality = item.municipality_name ? (' • ' + item.municipality_name) : '';
            var locationTypeLabel = String(item.location_type_label || '');
            $row.html(
                '<div class="dexpress-package-shop-modal__item-main">' +
                '<strong>' + escapeHtml(item.name) + '</strong>' +
                (locationTypeLabel ? ('<div class="dexpress-package-shop-modal__item-type">' + escapeHtml(locationTypeLabel) + '</div>') : '') +
                '<div>' + escapeHtml(item.address) + '</div>' +
                '<div>' + escapeHtml(item.town_name) + escapeHtml(municipality) + '</div>' +
                (item.work_hours ? ('<div>Radno vreme: ' + escapeHtml(item.work_hours) + '</div>') : '') +
                (item.work_days ? ('<div>Radni dani: ' + escapeHtml(item.work_days) + '</div>') : '') +
                '</div>' +
                '<button type="button" class="dexpress-ps-select-btn" data-dispenser-id="' + Number(item.id) + '">IZABERI</button>'
            );
            $list.append($row);
        });
    }

    function applyFilter(query) {
        var q = normalizeSearchText($.trim(String(query || '')));
        mapState.filtered = q === '' ? mapState.dispensers.slice() : mapState.dispensers.filter(function (item) {
            var hay = normalizeSearchText([item.name, item.address, item.town_name, item.municipality_name, item.work_hours, item.work_days].join(' '));
            return hay.indexOf(q) !== -1;
        });

        mapState.activeDispenserId = null;
        renderList();
        renderMarkers();

        if (mapState.filtered.length === 1) {
            focusDispenser(mapState.filtered[0].id, false);
            return;
        }

        updateMapBounds();
    }

    function focusDispenser(dispenserId, openInfoWindow) {
        var id = Number(dispenserId);
        if (!id) { return; }

        mapState.activeDispenserId = id;
        var item = mapState.dispensers.find(function (entry) { return Number(entry.id) === id; });
        var marker = mapState.markerById[id];
        if (!item || !marker || !mapState.map) {
            renderList();
            return;
        }

        mapState.map.panTo(marker.getPosition());
        mapState.map.setZoom(13);

        if (openInfoWindow) {
            if (!mapState.infoWindow) { mapState.infoWindow = new google.maps.InfoWindow(); }
            mapState.infoWindow.setContent(buildInfoWindowHtml(item));
            mapState.infoWindow.open({ map: mapState.map, anchor: marker });
        }

        renderList();
    }

    function loadAndRenderDispensers() {
        var $browser = getBrowserModal();
        setBrowserLoadingState(true);

        return $.when(loadDispensers('')).then(function (response) {
            var items = response && response.success && response.data ? response.data.items : [];
            mapState.dispensers = Array.isArray(items) ? items : [];
            mapState.filtered = mapState.dispensers.slice();
            mapState.activeDispenserId = null;
            restoreSelectedDispenserFromHiddenField();
            renderList();
            setBrowserLoadingState(false);

            if (!ensureMap()) {
                renderMapFallbackMessage(String((dexpress_package_shop.i18n || {}).map_init_failed || 'Mapa trenutno nije dostupna.'));
                return;
            }

            renderMarkers();
            refreshSelectionUI();
            updateMapBounds();
        }).fail(function () {
            setBrowserLoadingState(false);
            $browser.find('[data-dexpress-package-shop-list="1"]').html(
                '<div class="dexpress-package-shop-modal__empty">' + escapeHtml(String((dexpress_package_shop.i18n || {}).loading || 'Učitavanje...')) + '</div>'
            );
            renderMapFallbackMessage(String((dexpress_package_shop.i18n || {}).map_init_failed || 'Mapa trenutno nije dostupna.'));
        }).always(function () {
            if (mapState.map && window.google && window.google.maps) {
                window.setTimeout(function () {
                    google.maps.event.trigger(mapState.map, 'resize');
                    updateMapBounds();
                }, 30);
            }
        });
    }

    function openOnboardingModalFromPanel($panel) {
        if (!$panel.length) { return; }
        uiState.activeRateId = String($panel.data('rateId') || '');
        fillOnboardingContent($panel);
        $('body').addClass('dexpress-package-shop-modal-open');
        openModal(getOnboardingModal());
    }

    function openBrowserModalFromActivePanel() {
        var $panel = getSelectedPanel();
        if (!$panel.length && uiState.activeRateId !== '') {
            $panel = $('[data-dexpress-package-shop-panel="1"][data-rate-id="' + uiState.activeRateId + '"]').first();
        }
        if (!$panel.length) {
            closeAllModals();
            return;
        }

        var $browser = getBrowserModal();
        mapState.activeDispenserId = null;
        $browser.find('[data-dexpress-package-shop-search="1"]').val('');
        destroyMap();
        setMobileDrawerOpen(false);
        initLoadingLogo();
        $('body').addClass('dexpress-package-shop-modal-open');
        openModal($browser);
        loadAndRenderDispensers();
    }

    function syncPanels() {
        var selectedRateId = getSelectedRateId();
        var isSelected = isPackageShopRate(selectedRateId);
        ensureCheckoutHiddenField();
        ensureCheckoutLocationTypeField();

        $('[data-dexpress-package-shop-panel="1"]').each(function () {
            var $panel = $(this);
            var shouldShow = isSelected && String($panel.data('rateId') || '') === selectedRateId;

            if (shouldShow) {
                if ($('.wc-block-checkout').length) { mountBlockPanel($panel); }
                $panel.prop('hidden', false);
                return;
            }
            $panel.prop('hidden', true);
        });

        if (!isSelected) {
            closeAllModals();
            clearPanelValidationError();
        }
        refreshSelectionUI();
    }

    function getValidationMessage() {
        return String(((dexpress_package_shop.i18n || {}).select_required) || 'Molimo vas odaberite paketomat pre naručivanja.');
    }

    function ensurePanelErrorNode($panel) {
        var $error = $panel.find('[data-dexpress-ps-validation-error="1"]').first();
        if ($error.length) {
            return $error;
        }

        $error = $('<div class="dexpress-package-shop-panel__validation-error" data-dexpress-ps-validation-error="1" hidden></div>');
        var $actions = $panel.find('.dexpress-package-shop-panel__actions').first();
        if ($actions.length) {
            $error.insertAfter($actions);
        } else {
            $panel.append($error);
        }

        return $error;
    }

    function setPanelValidationError(message) {
        var $panel = getSelectedPanel();
        if (!$panel.length) {
            return;
        }

        var $error = ensurePanelErrorNode($panel);
        $error.text(String(message || '')).prop('hidden', false);
    }

    function clearPanelValidationError() {
        $('[data-dexpress-ps-validation-error="1"]').prop('hidden', true).text('');
    }

    function shouldBlockPlaceOrderForPackageShop() {
        var selectedRateId = getSelectedRateId();
        if (!isPackageShopRate(selectedRateId)) {
            return false;
        }

        var selectedDispenserId = String(ensureCheckoutHiddenField().val() || '').trim();
        return selectedDispenserId === '';
    }

    function runCheckoutPackageShopValidation(event) {
        if (!shouldBlockPlaceOrderForPackageShop()) {
            clearPanelValidationError();
            return true;
        }

        if (event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        setPanelValidationError(getValidationMessage());
        $(document.body).trigger('checkout_error');

        var $firstNotice = $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .wc-block-components-notice-banner.is-error').first();
        if ($firstNotice.length) {
            $('html, body').stop(true).animate({ scrollTop: Math.max($firstNotice.offset().top - 120, 0) }, 220);
        }

        return false;
    }

    function bindCheckoutValidationHandlers() {
        $(document.body)
            .off('checkout_place_order.dexpress-package-shop-validation')
            .on('checkout_place_order.dexpress-package-shop-validation', function () {
                return runCheckoutPackageShopValidation();
            });

        $(document)
            .off('submit.dexpress-package-shop-validation', 'form.checkout, form.wc-block-checkout__form')
            .on('submit.dexpress-package-shop-validation', 'form.checkout, form.wc-block-checkout__form', function (event) {
                return runCheckoutPackageShopValidation(event);
            });

        $(document)
            .off('click.dexpress-package-shop-validation', '#place_order, .wc-block-components-checkout-place-order-button')
            .on('click.dexpress-package-shop-validation', '#place_order, .wc-block-components-checkout-place-order-button', function (event) {
                return runCheckoutPackageShopValidation(event);
            });
    }

    function initEvents() {
        $(document)
            .off('change.dexpress-package-shop-shipping')
            .on('change.dexpress-package-shop-shipping', 'input[name^="shipping_method["], input[name^="wc-block-shipping-method"], .wc-block-checkout input[type="radio"]', function () {
                syncPanels();
            });

        $(document)
            .off('click.dexpress-package-shop-locker-cta', '[data-dexpress-package-shop-locker-cta="1"]')
            .on('click.dexpress-package-shop-locker-cta', '[data-dexpress-package-shop-locker-cta="1"]', function (event) {
                var $button = $(this);
                var $panel = $button.closest('[data-dexpress-package-shop-panel="1"]');
                openOnboardingModalFromPanel($panel);
                $(document.body).trigger('dexpress:package_shop_locker_cta_click', [{
                    button: $button,
                    panel: $panel,
                    selectedRateId: getSelectedRateId(),
                }]);
                event.preventDefault();
            });

        $(document)
            .off('click.dexpress-package-shop-onboarding-close', '[data-dexpress-package-shop-onboarding-close="1"]')
            .on('click.dexpress-package-shop-onboarding-close', '[data-dexpress-package-shop-onboarding-close="1"]', function () {
                closeModal(getOnboardingModal());
                $('body').removeClass('dexpress-package-shop-modal-open');
            });

        $(document)
            .off('click.dexpress-package-shop-onboarding-cta', '[data-dexpress-package-shop-onboarding-cta="1"]')
            .on('click.dexpress-package-shop-onboarding-cta', '[data-dexpress-package-shop-onboarding-cta="1"]', function (event) {
                closeModal(getOnboardingModal());
                openBrowserModalFromActivePanel();
                event.preventDefault();
            });

        $(document)
            .off('click.dexpress-package-shop-browser-close', '[data-dexpress-package-shop-browser-close="1"]')
            .on('click.dexpress-package-shop-browser-close', '[data-dexpress-package-shop-browser-close="1"]', function () {
                closeBrowserModal();
                $('body').removeClass('dexpress-package-shop-modal-open');
            });

        $(document)
            .off('keydown.dexpress-package-shop-modal')
            .on('keydown.dexpress-package-shop-modal', function (event) {
                if (event.key === 'Escape') { closeAllModals(); }
            });

        $(document)
            .off('input.dexpress-package-shop-search', '[data-dexpress-package-shop-search="1"]')
            .on('input.dexpress-package-shop-search', '[data-dexpress-package-shop-search="1"]', function () {
                applyFilter($(this).val());
            });

        $(document)
            .off('click.dexpress-package-shop-list-item', '[data-dexpress-dispenser-item="1"]')
            .on('click.dexpress-package-shop-list-item', '[data-dexpress-dispenser-item="1"]', function (event) {
                if ($(event.target).closest('.dexpress-ps-select-btn').length) {
                    return;
                }
                focusDispenser(Number($(this).attr('data-dexpress-dispenser-id') || '0'), true);
            });

        $(document)
            .off('click.dexpress-package-shop-select-btn', '.dexpress-ps-select-btn')
            .on('click.dexpress-package-shop-select-btn', '.dexpress-ps-select-btn', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var dispenserId = Number($(this).attr('data-dispenser-id') || '0');
                if (!dispenserId) {
                    return;
                }

                var dispenser = mapState.dispensers.find(function (entry) {
                    return Number(entry.id) === dispenserId;
                });
                if (!dispenser) {
                    return;
                }

                setSelectedDispenser(dispenser);
                setMobileDrawerOpen(false);
                closeBrowserModal();
                $('body').removeClass('dexpress-package-shop-modal-open');
            });

        $(document)
            .off('click.dexpress-package-shop-reset', '[data-dexpress-ps-reset="1"]')
            .on('click.dexpress-package-shop-reset', '[data-dexpress-ps-reset="1"]', function () {
                clearSelectedDispenser();
            });

        $(document)
            .off('click.dexpress-package-shop-mobile-toggle', '[data-dexpress-ps-mobile-toggle="1"]')
            .on('click.dexpress-package-shop-mobile-toggle', '[data-dexpress-ps-mobile-toggle="1"]', function () {
                if (!isMobileViewport()) {
                    return;
                }
                var $browser = getBrowserModal();
                setMobileDrawerOpen(!$browser.hasClass('is-mobile-drawer-open'));
            });

        $(document)
            .off('click.dexpress-package-shop-cluster-menu-item', '[data-dexpress-cluster-dispenser-id]')
            .on('click.dexpress-package-shop-cluster-menu-item', '[data-dexpress-cluster-dispenser-id]', function () {
                var id = Number($(this).attr('data-dexpress-cluster-dispenser-id') || '0');
                closeSmallClusterMenu();
                focusDispenser(id, true);
            });

        $(document)
            .off('click.dexpress-package-shop-modal-cta', '[data-dexpress-package-shop-modal-cta="1"]')
            .on('click.dexpress-package-shop-modal-cta', '[data-dexpress-package-shop-modal-cta="1"]', function (event) {
                $(document.body).trigger('dexpress:package_shop_modal_cta_click', [{
                    button: $(this),
                    selectedRateId: getSelectedRateId(),
                    activeDispenserId: mapState.activeDispenserId,
                }]);
                event.preventDefault();
            });

        $(document.body).off('updated_checkout.dexpress-package-shop')
            .on('updated_checkout.dexpress-package-shop', function () {
                syncPanels();
                bindCheckoutValidationHandlers();
            });

        $(window).off('resize.dexpress-package-shop-mobile').on('resize.dexpress-package-shop-mobile', function () {
            if (!isMobileViewport()) {
                setMobileDrawerOpen(false);
            }
            var $toggle = $('[data-dexpress-ps-mobile-toggle="1"]');
            $toggle.prop('hidden', !isMobileViewport());
        });
    }

    function boot() {
        ensureCheckoutHiddenField();
        ensureCheckoutLocationTypeField();
        initEvents();
        bindCheckoutValidationHandlers();
        syncPanels();
        $('[data-dexpress-ps-mobile-toggle="1"]').prop('hidden', !isMobileViewport());
        window.setTimeout(syncPanels, 250);
        window.setTimeout(syncPanels, 800);
    }

    $(document).ready(boot);
})(jQuery);
