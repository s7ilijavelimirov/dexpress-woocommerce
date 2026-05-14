<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Metabox;

use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressShippingMethod;

final class OrderShipmentMetabox
{
    public function __construct(
        private readonly WpdbShipmentRepository $shipments,
        private readonly WpdbSenderLocationRepository $locations,
        private readonly OptionsRepository $options,
    ) {}

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMetaBox(string $screenId, \WP_Post|\WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
        if (!$order instanceof \WC_Order) {
            return;
        }
        if (!DexpressShippingMethod::orderUsesDexpress($order)
            && !DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return;
        }

        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        if (!in_array($screenId, $screens, true)) {
            return;
        }

        add_meta_box(
            'dexpress-shipment',
            __('D-Express Dostava', 'dexpress-woocommerce'),
            [$this, 'render'],
            $screenId,
            'side',
            'high',
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        $order = $this->getCurrentOrder();
        if ($order === null
            || (!DexpressShippingMethod::orderUsesDexpress($order)
            && !DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order))) {
            return;
        }

        $latest = $this->shipments->findLatestByOrderId($order->get_id());
        $sendStatus = ($latest instanceof Shipment && $latest->id() !== null)
            ? $this->shipments->getSendStatus((int) $latest->id())
            : '';
        $editShipmentId = absint($_GET['dexpress_edit_shipment'] ?? 0);
        $isEditingPending = $latest instanceof Shipment
            && $sendStatus === 'pending_send'
            && (int) $latest->id() === $editShipmentId;

        $orderLineItems = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $orderLineItems[] = [
                    'id' => $item->get_id(),
                    'name' => $item->get_name(),
                    'qty_max' => (int) $item->get_quantity(),
                ];
            }
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'dex-admin',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DEXPRESS_VERSION,
        );
        wp_enqueue_style(
            'dexpress-admin-metabox',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin-metabox.css',
            ['dashicons', 'dex-admin'],
            DEXPRESS_VERSION,
        );
        wp_enqueue_script('dexpress-metabox', DEXPRESS_PLUGIN_URL . 'assets/js/admin-metabox.js', ['jquery'], DEXPRESS_VERSION, true);
        wp_localize_script('dexpress-metabox', 'dexpressMetabox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonceSaveLocal' => wp_create_nonce('dexpress_save_shipment_local'),
            'nonceSendSaved' => wp_create_nonce('dexpress_send_saved_shipment'),
            'orderId' => $order->get_id(),
            'maxPackages' => 30,
            'orderLineItems' => $orderLineItems,
            'defaults' => $this->shipmentWizardDefaults($order),
            'isPackageShop' => DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order),
            'destination' => $this->destinationLine($order),
            'sendStatus' => $sendStatus,
            'pendingShipmentId' => ($latest instanceof Shipment && $sendStatus === 'pending_send') ? (int) $latest->id() : 0,
            'pendingLabelUrl' => ($latest instanceof Shipment && $sendStatus === 'pending_send') ? $this->labelUrlForShipment($latest) : '',
            'editShipmentId' => $isEditingPending ? (int) $latest->id() : 0,
            'initialDraft' => $isEditingPending ? $this->buildInitialDraftFromShipment($latest) : null,
            'i18n' => [
                'savingLocal' => __('Čuvanje i štampa...', 'dexpress-woocommerce'),
                'sending' => __('Slanje...', 'dexpress-woocommerce'),
                'sendToDexpress' => __('Pošalji u D-Express', 'dexpress-woocommerce'),
                'error' => __('Došlo je do greške.', 'dexpress-woocommerce'),
            ],
        ]);
    }

    public function render(\WP_Post|\WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $manualEmailMessage = $this->maybeHandleManualPackageShopEmailAction($order);
        $latest = $this->shipments->findLatestByOrderId($order->get_id());
        $sendStatus = ($latest instanceof Shipment && $latest->id() !== null)
            ? $this->shipments->getSendStatus((int) $latest->id())
            : '';
        $editShipmentId = absint($_GET['dexpress_edit_shipment'] ?? 0);

        echo '<div id="dex-shipment-root" class="dex-metabox">';
        if ($manualEmailMessage !== null) {
            echo '<div class="notice notice-info inline"><p>' . esc_html($manualEmailMessage) . '</p></div>';
        }
        if ($latest instanceof Shipment && $sendStatus === 'pending_send' && (int) $latest->id() === $editShipmentId) {
            $this->renderWizard($order, $this->locations->findAll(), $latest);
        } elseif ($latest instanceof Shipment && $sendStatus === 'pending_send') {
            $this->renderPendingSend($latest);
        } elseif ($latest instanceof Shipment && $sendStatus === 'sent') {
            $this->renderCreated($latest, $order);
        } else {
            $this->renderWizard($order, $this->locations->findAll());
        }
        echo '</div>';
    }

    /** @param array<int,array<string,mixed>> $senderLocations */
    private function renderWizard(\WC_Order $order, array $senderLocations, ?Shipment $prefillShipment = null): void
    {
        if ($senderLocations === []) {
            $settingsUrl = add_query_arg(
                ['page' => 'dexpress-settings', 'tab' => 'sender_locations'],
                admin_url('admin.php'),
            );
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('Niste podesili lokaciju pošiljaoca.', 'dexpress-woocommerce')
                . ' <a href="' . esc_url($settingsUrl) . '">'
                . esc_html__('Dodajte lokaciju ovde', 'dexpress-woocommerce')
                . '</a> '
                . esc_html__('pre kreiranja pošiljke.', 'dexpress-woocommerce')
                . '</p></div>';
            return;
        }
        $defaults = $this->shipmentWizardDefaults($order);
        $wizardTitle = $prefillShipment instanceof Shipment
            ? __('Izmena podataka pošiljke', 'dexpress-woocommerce')
            : __('Paketi', 'dexpress-woocommerce');
        echo '<div class="dex-state dex-state--wizard" data-state="wizard"><div class="dex-steps">'
            . '<span class="dex-step is-active" data-step="1">' . esc_html__('1. Paketi', 'dexpress-woocommerce') . '</span>'
            . '<span class="dex-step" data-step="2">' . esc_html__('2. Opcije pošiljke', 'dexpress-woocommerce') . '</span>'
            . '<span class="dex-step" data-step="3">' . esc_html__('3. Pregled i štampa', 'dexpress-woocommerce') . '</span></div>';
        echo '<section class="dex-step-panel" data-step="1"><h4>' . esc_html($wizardTitle) . '</h4><div id="dex-package-cards" class="dex-package-cards"></div><div class="dex-package-actions"><button type="button" class="button" id="dex-remove-package">− ' . esc_html__('Ukloni paket', 'dexpress-woocommerce') . '</button><button type="button" class="button button-secondary" id="dex-add-package">+ ' . esc_html__('Dodaj paket', 'dexpress-woocommerce') . '</button></div></section>';
        echo '<section class="dex-step-panel" data-step="2" hidden><h4>' . esc_html__('Opcije pošiljke', 'dexpress-woocommerce') . '</h4>';
        $destination = $this->destinationLine($order);
        if (DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order) && $destination !== '') {
            echo '<p class="dex-package-shop-destination">' . esc_html__('Dostava na:', 'dexpress-woocommerce') . ' <strong>' . esc_html($destination) . '</strong></p>';
        }
        echo '<p><label class="dex-field-label" for="dex-sender-location">' . esc_html__('Lokacija pošiljaoca', 'dexpress-woocommerce') . '</label><select id="dex-sender-location" class="widefat">';
        foreach ($senderLocations as $loc) {
            $selected = !empty($loc['is_default']) ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $loc['id']) . '"' . $selected . '>' . esc_html((string) $loc['name']) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label class="dex-field-label" for="dex-delivery-type">' . esc_html__('Tip dostave', 'dexpress-woocommerce') . '</label><select id="dex-delivery-type" class="widefat">';
        foreach (DeliveryType::cases() as $type) {
            $selected = (string) $type->value === $defaults['delivery_type'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $type->value) . '"' . $selected . '>' . esc_html($type->label()) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label class="dex-field-label" for="dex-payment-type">' . esc_html__('Način plaćanja', 'dexpress-woocommerce') . '</label><select id="dex-payment-type" class="widefat">';
        foreach (PaymentType::cases() as $type) {
            $selected = (string) $type->value === $defaults['payment_type'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $type->value) . '"' . $selected . '>' . esc_html($type->label()) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label class="dex-field-label" for="dex-return-doc">' . esc_html__('Povraćaj dokumenta', 'dexpress-woocommerce') . '</label><select id="dex-return-doc" class="widefat">';
        foreach (ReturnDoc::cases() as $doc) {
            $selected = (string) $doc->value === $defaults['return_doc'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $doc->value) . '"' . $selected . '>' . esc_html($doc->label()) . '</option>';
        }
        $checked = !empty($defaults['self_drop_off']) ? ' checked' : '';
        echo '</select></p><p><label><input type="checkbox" id="dex-self-drop-off" value="1"' . $checked . '> ' . esc_html__('Lično predajem kuriru (self drop-off)', 'dexpress-woocommerce') . '</label></p>';
        echo '<p><label class="dex-field-label" for="dex-content">' . esc_html__('Sadržaj pošiljke', 'dexpress-woocommerce') . ' <span class="dex-req">*</span></label><input type="text" id="dex-content" class="widefat" maxlength="50"></p>';
        echo '<p><label class="dex-field-label" for="dex-note">' . esc_html__('Napomena', 'dexpress-woocommerce') . '</label><input type="text" id="dex-note" class="widefat" maxlength="150"></p></section>';
        echo '<section class="dex-step-panel" data-step="3" hidden><h4>' . esc_html__('Pregled i štampa', 'dexpress-woocommerce') . '</h4><div id="dex-step3-summary" class="dex-step3-summary"></div></section>';
        echo '<div id="dex-wizard-error" class="dex-wizard-error" role="alert" hidden></div><div id="dex-wizard-result" class="dex-wizard-result" aria-live="polite"></div><div class="dex-wizard-nav"><button type="button" class="button" id="dex-step-back" hidden>' . esc_html__('Nazad', 'dexpress-woocommerce') . '</button><button type="button" class="button button-primary" id="dex-step-next">' . esc_html__('Dalje', 'dexpress-woocommerce') . '</button><button type="button" class="button button-primary" id="dex-print-label" hidden>' . esc_html__('Štampaj nalepnicu', 'dexpress-woocommerce') . '</button></div></div>';
    }

    private function renderPendingSend(Shipment $shipment): void
    {
        echo '<div class="dex-state dex-state--draft" data-state="pending_send" data-shipment-id="' . esc_attr((string) $shipment->id()) . '" data-label-url="' . esc_url($this->labelUrlForShipment($shipment)) . '">';
        echo '<h4>' . esc_html__('Pošiljka čeka slanje', 'dexpress-woocommerce') . '</h4>';
        echo '<p class="dex-draft-warning">' . esc_html__('Nalepnica je odštampana. Spakujte pošiljku, zalepite nalepnicu i kliknite Pošalji.', 'dexpress-woocommerce') . '</p>';
        echo '<p><strong>' . esc_html__('Kod za praćenje:', 'dexpress-woocommerce') . '</strong> <code>' . esc_html($shipment->trackingCode()) . '</code></p>';
        echo '<div id="dex-wizard-result" class="dex-wizard-result" aria-live="polite"></div><div class="dex-wizard-nav"><button type="button" class="button" id="dex-edit-shipment">' . esc_html__('Izmeni podatke', 'dexpress-woocommerce') . '</button><button type="button" class="button" id="dex-reprint-label">' . esc_html__('Štampaj ponovo', 'dexpress-woocommerce') . '</button><button type="button" class="button button-primary" id="dex-send-shipment">' . esc_html__('Pošalji u D-Express', 'dexpress-woocommerce') . '</button></div></div>';
    }

    private function renderCreated(Shipment $shipment, \WC_Order $order): void
    {
        $trackingCode = $shipment->trackingCode();
        $labelUrl = $this->labelUrlForShipment($shipment);
        $isTest = strtoupper((string) $shipment->apiResponse()) === 'TEST';
        $senderLocation = $this->locations->findById($shipment->senderLocationId);
        $isPackageShopOrder = DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order);
        $packageShopLocationName = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
        $packageShopLocationType = trim((string) ($order->get_meta('_dexpress_package_shop_location_type_label') ?: __('Paket Shop', 'dexpress-woocommerce')));
        $senderText = $this->senderLocationDisplay($senderLocation);
        $recipientText = $this->recipientDisplay($order, $isPackageShopOrder);

        $packageCount = count($shipment->packages);
        $totalMassGrams = 0;
        foreach ($shipment->packages as $pkg) {
            $totalMassGrams += $pkg->mass?->value() ?? 0;
        }
        if ($totalMassGrams <= 0) {
            $totalMassGrams = $shipment->totalMass->value();
        }

        $timeline = $this->statusTimelineForOrder($order->get_id(), $shipment->currentSid(), $shipment->displayStatusLabel());
        if ($timeline === []) {
            $timeline[] = [
                'label' => $shipment->displayStatusLabel() !== '' ? $shipment->displayStatusLabel() : __('Pošiljka je kreirana', 'dexpress-woocommerce'),
                'occurred_at' => $shipment->createdAt->format('Y-m-d H:i:s'),
                'state' => 'current',
            ];
        }

        echo '<div class="dex-state dex-state--created" data-state="created">';
        echo '<div class="dex-sent-banner"><span class="dex-env-badge ' . ($isTest ? 'is-test' : 'is-production') . '">' . esc_html($isTest ? 'TEST' : 'PRODUCTION') . '</span> ' . esc_html__('Pošiljka uspešno poslata u D-Express', 'dexpress-woocommerce') . '</div>';

        echo '<div class="dex-sent-body">';
        echo '<div class="dex-sent-parties">';
        echo '<div class="dex-sent-party"><span class="dex-sent-label">' . esc_html__('Kod', 'dexpress-woocommerce') . ':</span> <code>' . esc_html($trackingCode) . '</code></div>';
        echo '<div class="dex-sent-party"><span class="dex-sent-label">' . esc_html__('Od', 'dexpress-woocommerce') . ':</span> <span>' . esc_html($senderText) . '</span></div>';
        echo '<div class="dex-sent-party"><span class="dex-sent-label">' . esc_html__('Za', 'dexpress-woocommerce') . ':</span> <span>' . esc_html($recipientText) . '</span>';
        if ($isPackageShopOrder) {
            echo ' <span class="dex-pill">' . esc_html($packageShopLocationType) . '</span>';
            if ($packageShopLocationName !== '') {
                echo ' <span>' . esc_html($packageShopLocationName) . '</span>';
            }
        }
        echo '</div>';
        echo '<div class="dex-sent-party"><span class="dex-sent-label">' . esc_html__('Detalji', 'dexpress-woocommerce') . ':</span> <span>' . esc_html((string) $packageCount . ' / ' . number_format($totalMassGrams / 1000, 2, ',', '') . ' kg · ' . $shipment->deliveryType->label() . ' · ' . $shipment->paymentType->label()) . '</span></div>';
        echo '</div>';

        $allocations = $this->packageItemAllocations((int) $shipment->id());
        echo '<div class="dex-sent-packages"><ul>';
        foreach ($shipment->packages as $pkg) {
            $massLabel = $pkg->mass !== null ? number_format($pkg->mass->value() / 1000, 2, ',', '') . ' kg' : '—';
            $packageKey = $pkg->id ?? 0;
            $items = $allocations[$packageKey] ?? [];
            $itemLabel = $items !== [] ? implode(', ', $items) : '—';
            echo '<li><code>' . esc_html($pkg->code->value()) . '</code> | ' . esc_html($massLabel) . ' | ' . esc_html($itemLabel) . '</li>';
        }
        echo '</ul></div>';

        echo '<div class="dex-sent-timeline">';
        echo '<h4>' . esc_html__('Status pošiljke', 'dexpress-woocommerce') . '</h4>';
        echo '<ol class="dex-status-timeline">';
        foreach ($timeline as $step) {
            $stateClass = $step['state'] === 'current'
                ? 'is-current'
                : ($step['state'] === 'completed' ? 'is-completed' : 'is-pending');
            $date = $step['occurred_at'] !== ''
                ? date_i18n('d.m.Y H:i', strtotime($step['occurred_at']))
                : '';
            echo '<li class="dex-status-step ' . esc_attr($stateClass) . '"><span class="dex-status-dot" aria-hidden="true"></span><div class="dex-status-content"><strong>' . esc_html($step['label']) . '</strong>';
            if ($date !== '') {
                echo '<small>' . esc_html($date) . '</small>';
            }
            echo '</div></li>';
        }
        echo '</ol>';
        echo '</div>';
        echo '</div>';

        $manualEmailUrl = $this->manualPackageShopEmailUrl($order);
        echo '<div class="dex-created-actions">';
        echo '<a class="button button-primary" href="' . esc_url($labelUrl) . '" target="_blank" rel="noopener">' . esc_html__('Štampaj nalepnicu', 'dexpress-woocommerce') . '</a>';
        echo '<button type="button" class="button dex-copy-track" data-track="' . esc_attr($trackingCode) . '">' . esc_html__('Kopiraj kod za praćenje', 'dexpress-woocommerce') . '</button>';
        if ($isPackageShopOrder && $manualEmailUrl !== '') {
            echo '<a class="button" href="' . esc_url($manualEmailUrl) . '">' . esc_html__('Pošalji email kupcu', 'dexpress-woocommerce') . '</a>';
        }
        echo '</div>';
        echo '<div id="dex-wizard-result" class="dex-wizard-result" aria-live="polite"></div>';
        echo '</div>';
    }

    private function destinationLine(\WC_Order $order): string
    {
        $name = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
        $city = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
        return trim($name . ($name !== '' && $city !== '' ? ', ' : '') . $city);
    }

    /** @return array{delivery_type:string,payment_type:string,return_doc:string,self_drop_off:bool} */
    private function shipmentWizardDefaults(\WC_Order $order): array
    {
        return [
            'delivery_type' => $this->firstNonEmpty((string) $order->get_meta('_dexpress_delivery_type'), $this->options->getString('shipment.default_delivery_type'), (string) DeliveryType::Regular->value),
            'payment_type' => $this->firstNonEmpty((string) $order->get_meta('_dexpress_payment_type'), $this->options->getString('shipment.default_payment_type'), (string) PaymentType::Invoice->value),
            'return_doc' => $this->firstNonEmpty((string) $order->get_meta('_dexpress_return_doc'), $this->options->getString('shipment.default_return_doc'), (string) ReturnDoc::None->value),
            'self_drop_off' => (bool) ((int) $this->firstNonEmpty((string) $order->get_meta('_dexpress_self_drop_off'), $this->options->getString('shipment.default_self_drop_off'), '0')),
        ];
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function labelUrlForShipment(Shipment $shipment): string
    {
        return add_query_arg(
            ['page' => 'dexpress-label', 'shipment_id' => $shipment->id(), 'nonce' => wp_create_nonce('dexpress_print_label_' . $shipment->id())],
            admin_url('admin.php'),
        );
    }

    private function getCurrentOrder(): ?\WC_Order
    {
        $orderId = absint($_GET['id'] ?? 0) ?: absint($_GET['post'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        $order = wc_get_order($orderId);
        return $order instanceof \WC_Order ? $order : null;
    }

    /** @return array<int, array{label:string,occurred_at:string,state:string}> */
    private function statusTimelineForOrder(int $orderId, int $currentSid, string $snapshot): array
    {
        global $wpdb;
        $historyTable = $wpdb->prefix . 'dexpress_shipment_statuses';
        $shipmentsTable = $wpdb->prefix . 'dexpress_shipments';
        $codesTable = $wpdb->prefix . 'dexpress_status_codes';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.sid, h.status_label_snapshot, h.occurred_at, COALESCE(NULLIF(sc.name_sr,''), NULLIF(sc.name_en,''), '') AS official_label
                 FROM `{$historyTable}` h
                 INNER JOIN `{$shipmentsTable}` s ON s.id = h.shipment_id
                 LEFT JOIN `{$codesTable}` sc ON sc.sid = h.sid
                 WHERE s.order_id = %d
                 ORDER BY h.occurred_at ASC, h.id ASC",
                $orderId,
            ),
            ARRAY_A,
        );
        if (!is_array($rows)) {
            return [];
        }

        $timeline = [];
        $currentIdx = -1;
        foreach ($rows as $idx => $row) {
            $sid = (int) ($row['sid'] ?? 0);
            $label = trim((string) ($row['official_label'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($row['status_label_snapshot'] ?? ''));
            }
            if ($label === '') {
                $label = sprintf(__('Status (sID: %d)', 'dexpress-woocommerce'), $sid);
            }
            if ($sid === $currentSid) {
                $currentIdx = $idx;
            }
            $timeline[] = [
                'label' => $label,
                'occurred_at' => (string) ($row['occurred_at'] ?? ''),
                'state' => 'pending',
            ];
        }

        if ($timeline === []) {
            return [];
        }
        if ($currentIdx < 0) {
            $currentIdx = count($timeline) - 1;
            if ($snapshot !== '') {
                $timeline[$currentIdx]['label'] = $snapshot;
            }
        }

        foreach ($timeline as $idx => &$step) {
            $step['state'] = $idx < $currentIdx ? 'completed' : ($idx === $currentIdx ? 'current' : 'pending');
        }
        unset($step);

        return $timeline;
    }

    private function manualPackageShopEmailUrl(\WC_Order $order): string
    {
        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return '';
        }
        $args = [
            'post' => $order->get_id(),
            'action' => 'edit',
            'dexpress_ps_ready_email' => 1,
        ];

        return wp_nonce_url(add_query_arg($args, admin_url('post.php')), 'dexpress_ps_ready_email_' . $order->get_id());
    }

    private function maybeHandleManualPackageShopEmailAction(\WC_Order $order): ?string
    {
        $trigger = absint($_GET['dexpress_ps_ready_email'] ?? 0);
        if ($trigger !== 1) {
            return null;
        }
        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return __('Paket Shop email može da se pošalje samo za Paket Shop porudžbine.', 'dexpress-woocommerce');
        }
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'dexpress_ps_ready_email_' . $order->get_id())) {
            return __('Nevažeći zahtev za slanje emaila.', 'dexpress-woocommerce');
        }

        do_action('woocommerce_order_action_dexpress_send_package_shop_ready_email', $order);
        return __('Paket Shop email je obrađen. Proverite order napomene za rezultat.', 'dexpress-woocommerce');
    }

    /** @param array<string,mixed>|null $senderLocation */
    private function senderLocationDisplay(?array $senderLocation): string
    {
        if (!is_array($senderLocation)) {
            return __('Nije dostupno', 'dexpress-woocommerce');
        }
        $name = trim((string) ($senderLocation['name'] ?? ''));
        $street = trim((string) ($senderLocation['street_name'] ?? ''));
        $number = trim((string) ($senderLocation['street_number'] ?? ''));
        $townName = '';
        foreach ($this->locations->findAll() as $loc) {
            if ((int) ($loc['id'] ?? 0) === (int) ($senderLocation['id'] ?? 0)) {
                $townName = trim((string) ($loc['town_name'] ?? ''));
                break;
            }
        }
        $parts = array_filter([
            $name,
            trim($street . ($number !== '' ? ' ' . $number : '')),
            $townName,
        ]);

        return $parts !== [] ? implode(', ', $parts) : __('Nije dostupno', 'dexpress-woocommerce');
    }

    private function recipientDisplay(\WC_Order $order, bool $isPackageShopOrder): string
    {
        if ($isPackageShopOrder) {
            $name = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
            $address = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
            $city = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
            $parts = array_filter([$name, $address, $city]);
            return $parts !== [] ? implode(', ', $parts) : __('Paket Shop lokacija nije dostupna', 'dexpress-woocommerce');
        }

        $name = trim((string) $order->get_formatted_shipping_full_name());
        if ($name === '') {
            $name = trim((string) $order->get_formatted_billing_full_name());
        }
        $address = trim((string) $order->get_shipping_address_1());
        if ($address === '') {
            $address = trim((string) $order->get_billing_address_1());
        }
        $city = trim((string) ($order->get_shipping_city() ?: $order->get_billing_city()));
        $parts = array_filter([$name, $address, $city]);
        return $parts !== [] ? implode(', ', $parts) : __('Primalac nije dostupan', 'dexpress-woocommerce');
    }

    /** @return array<int, list<string>> */
    private function packageItemAllocations(int $shipmentId): array
    {
        if ($shipmentId <= 0) {
            return [];
        }
        global $wpdb;
        $packageItemsTable = $wpdb->prefix . 'dexpress_package_items';
        $packagesTable = $wpdb->prefix . 'dexpress_packages';
        $orderItemsTable = $wpdb->prefix . 'woocommerce_order_items';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pi.package_id, pi.quantity, oi.order_item_name
                 FROM `{$packageItemsTable}` pi
                 INNER JOIN `{$packagesTable}` p ON p.id = pi.package_id
                 LEFT JOIN `{$orderItemsTable}` oi ON oi.order_item_id = pi.order_item_id
                 WHERE p.shipment_id = %d
                 ORDER BY pi.package_id ASC, pi.id ASC",
                $shipmentId,
            ),
            ARRAY_A,
        );
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['package_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $name = trim((string) ($row['order_item_name'] ?? ''));
            if ($name === '') {
                $name = __('Stavka', 'dexpress-woocommerce');
            }
            $qty = max(1, (int) ($row['quantity'] ?? 0));
            $out[$pid][] = $name . ' × ' . $qty;
        }

        return $out;
    }

    /** @return array{options: array<string, mixed>, packages: array<int, array<string, mixed>>} */
    private function buildInitialDraftFromShipment(Shipment $shipment): array
    {
        $packages = [];
        foreach ($shipment->packages as $package) {
            $packages[] = [
                'mass' => $package->mass?->value() ?? 0,
                'dim_x' => $package->dimX,
                'dim_y' => $package->dimY,
                'dim_z' => $package->dimZ,
                'content' => $package->contentNote ?? '',
                'items' => [],
            ];
        }

        return [
            'options' => [
                'sender_location_id' => $shipment->senderLocationId,
                'delivery_type' => $shipment->deliveryType->value,
                'payment_type' => $shipment->paymentType->value,
                'return_doc' => $shipment->returnDoc->value,
                'self_drop_off' => $shipment->selfDropOff ? 1 : 0,
                'content' => $shipment->content,
                'note' => $shipment->note,
            ],
            'packages' => $packages,
        ];
    }
}
