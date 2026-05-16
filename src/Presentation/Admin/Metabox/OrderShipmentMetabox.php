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
            'normal',
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
        $isPendingSend = $latest instanceof Shipment && $sendStatus === 'pending_send';

        $currencySymbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_HTML5 | ENT_QUOTES, 'UTF-8');

        $orderLineItems = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $product  = $item->get_product();
                $imageUrl = '';
                $category = '';
                $unitPrice = 0.0;
                $priceDisplay = '';
                if ($product instanceof \WC_Product) {
                    $imgId = (int) $product->get_image_id();
                    if ($imgId > 0) {
                        $src = wp_get_attachment_image_src($imgId, [48, 48]);
                        $imageUrl = $src ? (string) $src[0] : '';
                    }
                    $unitPrice    = $item->get_quantity() > 0 ? (float) $item->get_subtotal() / (float) $item->get_quantity() : 0.0;
                    $priceDisplay = number_format($unitPrice, 2, ',', '.') . ' ' . $currencySymbol;
                    $terms = get_the_terms($product->get_id(), 'product_cat');
                    if (is_array($terms) && !empty($terms)) {
                        $category = (string) ($terms[0]->name ?? '');
                    }
                }
                $orderLineItems[] = [
                    'id'            => $item->get_id(),
                    'name'          => $item->get_name(),
                    'qty_max'       => (int) $item->get_quantity(),
                    'image_url'     => $imageUrl,
                    'price_display' => $priceDisplay,
                    'unit_price'    => round($unitPrice, 2),
                    'category'      => $category,
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
            'dex-metabox',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin-metabox.css',
            ['dashicons', 'dex-admin'],
            DEXPRESS_VERSION,
        );
        wp_enqueue_script(
            'dex-metabox',
            DEXPRESS_PLUGIN_URL . 'assets/js/admin-metabox.js',
            ['jquery'],
            DEXPRESS_VERSION,
            true,
        );
        $isPackageShop = DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order);
        $allLocations  = $this->locations->findAll();
        wp_localize_script('dex-metabox', 'dexpressMetabox', [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'nonceSaveLocal'    => wp_create_nonce('dexpress_save_shipment_local'),
            'nonceSendSaved'    => wp_create_nonce('dexpress_send_saved_shipment'),
            'orderId'           => $order->get_id(),
            'orderLineItems'    => $orderLineItems,
            'defaults'          => $this->wizardDefaults($order),
            'isPackageShop'     => $isPackageShop,
            'destination'       => $this->destinationLine($order),
            'recipient'         => $this->recipientDisplay($order, $isPackageShop),
            'senderLocations'   => array_values(array_map(
                static fn (array $l): array => ['id' => (int) $l['id'], 'name' => (string) $l['name']],
                $allLocations,
            )),
            'sendStatus'           => $sendStatus,
            'pendingShipmentId'    => $isPendingSend ? (int) $latest->id() : 0,
            'editShipmentId'       => $isPendingSend ? (int) $latest->id() : 0,
            'initialDraft'         => $isPendingSend ? $this->buildDraftFromShipment($latest) : null,
            'nonceDeletePending'   => wp_create_nonce('dexpress_delete_pending_shipment'),
            'currencySymbol'       => $currencySymbol,
            'orderMeta'            => [
                'isPaid'             => $order->is_paid(),
                'paymentMethod'      => $order->get_payment_method(),
                'paymentMethodTitle' => $order->get_payment_method_title(),
                'statusLabel'        => wc_get_order_status_name($order->get_status()),
                'total'              => number_format((float) $order->get_total(), 2, ',', '.') . ' ' . $currencySymbol,
            ],
            'i18n' => [
                'creating'      => __('Kreiranje...', 'dexpress-woocommerce'),
                'sending'       => __('Slanje...', 'dexpress-woocommerce'),
                'sendToDexpress'=> __('Pošalji u D-Express', 'dexpress-woocommerce'),
                'error'         => __('Došlo je do greške.', 'dexpress-woocommerce'),
            ],
        ]);
    }

    public function render(\WP_Post|\WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $manualEmailMsg = $this->maybeHandleManualPackageShopEmail($order);
        $latest = $this->shipments->findLatestByOrderId($order->get_id());
        $sendStatus = ($latest instanceof Shipment && $latest->id() !== null)
            ? $this->shipments->getSendStatus((int) $latest->id())
            : '';

        echo '<div id="dex-mb-root">';

        if ($manualEmailMsg !== null) {
            echo '<div class="notice notice-info inline" style="margin:0 0 12px"><p>' . esc_html($manualEmailMsg) . '</p></div>';
        }

        if ($latest instanceof Shipment && $sendStatus === 'pending_send') {
            $this->renderPending($latest, $order);
        } elseif ($latest instanceof Shipment && $sendStatus === 'sent') {
            $this->renderCreated($latest, $order);
        } else {
            $this->renderWizard($order, $this->locations->findAll());
        }

        echo '<div id="dex-mb-msg" class="dex-mb-msg" aria-live="polite"></div>';
        echo '</div>';
    }

    // ── Wizard (State A + edit mode for State B) ──────────────────

    /** @param array<int,array<string,mixed>> $locations */
    private function renderWizard(\WC_Order $order, array $locations, bool $editMode = false): void
    {
        if ($locations === []) {
            $url = add_query_arg(['page' => 'dexpress-settings', 'tab' => 'sender_locations'], admin_url('admin.php'));
            echo '<div class="dex-mb-empty">';
            echo '<span class="dashicons dashicons-location-alt dex-mb-empty__icon"></span>';
            echo '<p class="dex-mb-empty__title">' . esc_html__('Lokacija pošiljaoca nije podešena', 'dexpress-woocommerce') . '</p>';
            echo '<p class="dex-mb-empty__desc">' . esc_html__('Pre kreiranja pošiljke dodajte barem jednu lokaciju pošiljaoca.', 'dexpress-woocommerce') . '</p>';
            echo '<a href="' . esc_url($url) . '" class="dex-mb-btn dex-mb-btn--primary">' . esc_html__('Dodaj lokaciju', 'dexpress-woocommerce') . '</a>';
            echo '</div>';
            return;
        }

        $defaults = $this->wizardDefaults($order);
        $isPackageShop = DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order);
        $destination = $this->destinationLine($order);

        echo '<div class="dex-mb-wizard" id="dex-mb-wizard">';

        // Step indicator
        $steps = [
            1 => ['icon' => 'dashicons-archive', 'label' => __('Paketi', 'dexpress-woocommerce')],
            2 => ['icon' => 'dashicons-admin-settings', 'label' => __('Opcije', 'dexpress-woocommerce')],
            3 => ['icon' => 'dashicons-visibility', 'label' => __('Pregled', 'dexpress-woocommerce')],
        ];
        echo '<div class="dex-mb-stepper" id="dex-mb-stepper">';
        foreach ($steps as $n => $s) {
            $activeClass = $n === 1 ? ' is-active' : '';
            echo '<div class="dex-mb-stepper__step' . $activeClass . '" data-step="' . $n . '">';
            echo '<div class="dex-mb-stepper__circle">' . $n . '</div>';
            echo '<span class="dex-mb-stepper__label">' . esc_html($s['label']) . '</span>';
            echo '</div>';
            if ($n < 3) {
                echo '<div class="dex-mb-stepper__line"></div>';
            }
        }
        echo '</div>';

        // ── Panel 1: Paketi ──────────────────────────────────────
        echo '<div class="dex-mb-panel" data-panel="1">';
        echo '<div class="dex-mb-panel__header">';
        echo '<div class="dex-mb-panel__icon"><span class="dashicons dashicons-archive"></span></div>';
        echo '<div class="dex-mb-panel__header-text"><h3>' . esc_html__('Paketi', 'dexpress-woocommerce') . '</h3>';
        echo '<p>' . esc_html__('Svaka kutija ili paket koji šaljete je jedan unos. Možete dodati više paketa za istu pošiljku.', 'dexpress-woocommerce') . '</p></div>';
        echo '</div>';

        if ($isPackageShop && $destination !== '') {
            echo '<div class="dex-mb-notice dex-mb-notice--info">';
            echo '<span class="dashicons dashicons-location"></span>';
            echo ' <strong>' . esc_html__('Dostava na Paket Shop:', 'dexpress-woocommerce') . '</strong> ' . esc_html($destination);
            echo '</div>';
        }

        echo '<div id="dex-mb-pkg-list"></div>';
        echo '<button type="button" id="dex-mb-add-pkg" class="dex-mb-add-btn">';
        echo '<span class="dashicons dashicons-plus-alt2"></span> ' . esc_html__('Dodaj još jedan paket', 'dexpress-woocommerce');
        echo '</button>';
        echo '<div class="dex-mb-panel__nav">';
        echo '<span></span>';
        echo '<button type="button" id="dex-mb-next-1" class="dex-mb-btn dex-mb-btn--primary">';
        echo esc_html__('Dalje', 'dexpress-woocommerce') . ' <span class="dashicons dashicons-arrow-right-alt"></span>';
        echo '</button>';
        echo '</div>';
        echo '</div>'; // panel 1

        // ── Panel 2: Opcije ──────────────────────────────────────
        echo '<div class="dex-mb-panel" data-panel="2" hidden>';
        echo '<div class="dex-mb-panel__header">';
        echo '<div class="dex-mb-panel__icon"><span class="dashicons dashicons-admin-settings"></span></div>';
        echo '<div class="dex-mb-panel__header-text"><h3>' . esc_html__('Opcije pošiljke', 'dexpress-woocommerce') . '</h3>';
        echo '<p>' . esc_html__('Vrednosti su automatski popunjene prema vašim podešavanjima. Promenite samo ako je potrebno.', 'dexpress-woocommerce') . '</p></div>';
        echo '</div>';

        // Hidden system inputs (delivery type from defaults, not editable)
        echo '<input type="hidden" id="dex-mb-delivery-type" value="' . esc_attr($defaults['delivery_type']) . '">';

        // Resolve default location
        $defaultLocId = 0;
        foreach ($locations as $loc) {
            if (!empty($loc['is_default'])) {
                $defaultLocId = (int) $loc['id'];
                break;
            }
        }
        if ($defaultLocId === 0 && !empty($locations)) {
            $defaultLocId = (int) $locations[0]['id'];
        }
        $selfDropOff = !empty($defaults['self_drop_off']) ? 1 : 0;

        // 6-field 2-column grid
        echo '<div class="dex-mb-options-grid">';

        // 1. Lokacija pošiljaoca
        echo '<div class="dex-mb-field">';
        echo '<label class="dex-mb-field__label"><span class="dashicons dashicons-store"></span> ';
        echo esc_html__('Lokacija pošiljaoca', 'dexpress-woocommerce') . ' <span class="dex-mb-req">*</span></label>';
        echo '<div class="dex-mb-location-list" id="dex-mb-location-wrap">';
        foreach ($locations as $loc) {
            $locId  = (int) $loc['id'];
            $active = $locId === $defaultLocId ? ' is-selected' : '';
            echo '<button type="button" class="dex-mb-location-option' . $active . '" data-id="' . $locId . '">';
            echo '<span class="dashicons dashicons-store"></span>';
            echo '<span class="dex-mb-location-name">' . esc_html((string) $loc['name']) . '</span>';
            echo '<span class="dex-mb-location-check dashicons dashicons-yes"></span>';
            echo '</button>';
        }
        echo '</div>';
        echo '<input type="hidden" id="dex-mb-location" value="' . esc_attr((string) $defaultLocId) . '">';
        echo '</div>';

        // 2. Predaja pošiljke
        echo '<div class="dex-mb-field">';
        echo '<label class="dex-mb-field__label"><span class="dashicons dashicons-car"></span> ';
        echo esc_html__('Predaja pošiljke', 'dexpress-woocommerce') . '</label>';
        echo '<div class="dex-mb-dropoff-toggle" id="dex-mb-segment-dropoff">';
        echo '<button type="button" class="dex-mb-dropoff-btn' . ($selfDropOff === 0 ? ' is-active' : '') . '" data-value="0">';
        echo '<span class="dashicons dashicons-car"></span>';
        echo '<span>' . esc_html__('Kurir dolazi', 'dexpress-woocommerce') . '</span>';
        echo '</button>';
        echo '<button type="button" class="dex-mb-dropoff-btn' . ($selfDropOff === 1 ? ' is-active' : '') . '" data-value="1">';
        echo '<span class="dashicons dashicons-businessman"></span>';
        echo '<span>' . esc_html__('Sam donosim', 'dexpress-woocommerce') . '</span>';
        echo '</button>';
        echo '</div>';
        echo '<input type="hidden" id="dex-mb-self-drop-off" value="' . $selfDropOff . '">';
        echo '</div>';

        // 3. Naplata
        echo '<div class="dex-mb-field">';
        echo '<label class="dex-mb-field__label" for="dex-mb-payment-type">';
        echo '<span class="dashicons dashicons-money-alt"></span> ' . esc_html__('Naplata', 'dexpress-woocommerce');
        echo '</label>';
        echo '<select id="dex-mb-payment-type" class="dex-mb-select">';
        foreach (PaymentType::cases() as $type) {
            $sel = (string) $type->value === $defaults['payment_type'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $type->value) . '"' . $sel . '>' . esc_html($type->label()) . '</option>';
        }
        echo '</select></div>';

        // 4. Povraćaj dokumenta
        echo '<div class="dex-mb-field">';
        echo '<label class="dex-mb-field__label" for="dex-mb-return-doc">';
        echo '<span class="dashicons dashicons-undo"></span> ' . esc_html__('Povraćaj dokumenta', 'dexpress-woocommerce');
        echo '</label>';
        echo '<select id="dex-mb-return-doc" class="dex-mb-select">';
        foreach (ReturnDoc::cases() as $doc) {
            $sel = (string) $doc->value === $defaults['return_doc'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $doc->value) . '"' . $sel . '>' . esc_html($doc->label()) . '</option>';
        }
        echo '</select></div>';

        // 5. Sadržaj (required)
        echo '<div class="dex-mb-field">';
        echo '<label class="dex-mb-field__label" for="dex-mb-content">';
        echo '<span class="dashicons dashicons-tag"></span> ' . esc_html__('Sadržaj pošiljke', 'dexpress-woocommerce');
        echo ' <span class="dex-mb-req">*</span></label>';
        echo '<input type="text" id="dex-mb-content" class="dex-mb-input" maxlength="50" placeholder="' . esc_attr__('npr. Odeća, Elektronika, Knjige...', 'dexpress-woocommerce') . '">';
        echo '<span class="dex-mb-field__hint">' . esc_html__('Auto-popunjava se iz stavki. Maks. 50 kar.', 'dexpress-woocommerce') . '</span>';
        echo '</div>';

        // 6. Napomena kuriru
        echo '<div class="dex-mb-field">';
        echo '<label class="dex-mb-field__label" for="dex-mb-note">';
        echo '<span class="dashicons dashicons-edit-page"></span> ' . esc_html__('Napomena kuriru', 'dexpress-woocommerce');
        echo '</label>';
        echo '<input type="text" id="dex-mb-note" class="dex-mb-input" maxlength="150" placeholder="' . esc_attr__('Opciono — vidljivo kuriru', 'dexpress-woocommerce') . '">';
        echo '</div>';

        echo '</div>'; // options-grid

        echo '<div class="dex-mb-panel__nav">';
        echo '<button type="button" id="dex-mb-back-2" class="dex-mb-btn dex-mb-btn--ghost">';
        echo '<span class="dashicons dashicons-arrow-left-alt"></span> ' . esc_html__('Nazad', 'dexpress-woocommerce');
        echo '</button>';
        echo '<button type="button" id="dex-mb-next-2" class="dex-mb-btn dex-mb-btn--primary">';
        echo esc_html__('Dalje', 'dexpress-woocommerce') . ' <span class="dashicons dashicons-arrow-right-alt"></span>';
        echo '</button>';
        echo '</div>';
        echo '</div>'; // panel 2

        // ── Panel 3: Pregled ─────────────────────────────────────
        echo '<div class="dex-mb-panel" data-panel="3" hidden>';
        echo '<div class="dex-mb-panel__header">';
        echo '<div class="dex-mb-panel__icon"><span class="dashicons dashicons-visibility"></span></div>';
        echo '<div class="dex-mb-panel__header-text"><h3>' . esc_html__('Pregled i kreiranje', 'dexpress-woocommerce') . '</h3>';
        echo '<p>' . esc_html__('Proverite detalje. Kliknite Kreiraj da dobijete TT kod i nalepnicu za štampu.', 'dexpress-woocommerce') . '</p></div>';
        echo '</div>';

        echo '<div id="dex-mb-summary"></div>';

        echo '<div class="dex-mb-notice dex-mb-notice--tip">';
        echo '<span class="dashicons dashicons-info-outline"></span> ';
        echo esc_html__('Nakon kreiranja: odštampajte nalepnicu, zalepite je na paket i spakujte. Tek tada pošaljite u D-Express.', 'dexpress-woocommerce');
        echo '</div>';

        echo '<div id="dex-mb-wizard-error" class="dex-mb-error" hidden></div>';

        echo '<div class="dex-mb-panel__nav">';
        echo '<button type="button" id="dex-mb-back-3" class="dex-mb-btn dex-mb-btn--ghost">';
        echo '<span class="dashicons dashicons-arrow-left-alt"></span> ' . esc_html__('Izmeni', 'dexpress-woocommerce');
        echo '</button>';
        echo '<button type="button" id="dex-mb-create" class="dex-mb-btn dex-mb-btn--primary dex-mb-btn--create">';
        echo '<span class="dashicons dashicons-printer"></span> ' . esc_html__('Pakuj i štampaj nalepnicu', 'dexpress-woocommerce');
        echo '</button>';
        echo '</div>';
        echo '</div>'; // panel 3

        echo '</div>'; // wizard
    }

    // ── State B: Pending send ─────────────────────────────────────

    private function renderPending(Shipment $shipment, \WC_Order $order): void
    {
        $labelUrl = $this->labelUrlForShipment($shipment);
        $allocations = $this->packageItemAllocations((int) $shipment->id());
        $isPackageShop = DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order);
        $destination = $this->destinationLine($order);

        // ── Pending view (visible by default) ────────────────────
        echo '<div id="dex-mb-pending-view">';

        // Banner
        echo '<div class="dex-mb-pending-banner">';
        echo '<span class="dex-mb-pulse-dot" aria-hidden="true"></span>';
        echo '<div class="dex-mb-pending-banner__left">';
        echo '<div class="dex-mb-pending-banner__title">' . esc_html__('Pošiljka čeka slanje u D-Express', 'dexpress-woocommerce') . '</div>';
        echo '<code class="dex-mb-code">' . esc_html($shipment->trackingCode()) . '</code>';
        echo '</div>';
        echo '<div class="dex-mb-pending-banner__actions">';
        echo '<a href="' . esc_url($labelUrl) . '" target="_blank" rel="noopener" class="dex-mb-btn dex-mb-btn--outline">';
        echo '<span class="dashicons dashicons-printer"></span> ' . esc_html__('Štampaj nalepnicu', 'dexpress-woocommerce');
        echo '</a>';
        echo '<button type="button" id="dex-mb-edit-pending" class="dex-mb-btn dex-mb-btn--ghost">';
        echo '<span class="dashicons dashicons-edit"></span> ' . esc_html__('Izmeni pošiljku', 'dexpress-woocommerce');
        echo '</button>';
        echo '<button type="button" id="dex-mb-delete-pending" class="dex-mb-btn dex-mb-btn--ghost" style="color:var(--dex-red)">';
        echo '<span class="dashicons dashicons-trash"></span> ' . esc_html__('Obriši pošiljku', 'dexpress-woocommerce');
        echo '</button>';
        echo '<button type="button" id="dex-mb-send" class="dex-mb-btn dex-mb-btn--danger">';
        echo '<span class="dashicons dashicons-share"></span> ' . esc_html__('Pošalji u D-Express', 'dexpress-woocommerce');
        echo '</button>';
        echo '</div>';
        echo '</div>'; // banner

        // Packages summary
        echo '<div class="dex-mb-pending-packages">';
        echo '<p class="dex-mb-pending-packages__header">' . sprintf(esc_html__('%d paket(a)', 'dexpress-woocommerce'), count($shipment->packages)) . '</p>';
        echo '<ul class="dex-mb-pkg-summary-list">';
        foreach ($shipment->packages as $pkg) {
            $massLabel = $pkg->mass !== null ? number_format($pkg->mass->value() / 1000, 2, ',', '') . ' kg' : '—';
            $dims = ($pkg->dimX && $pkg->dimY && $pkg->dimZ) ? $pkg->dimX . '×' . $pkg->dimY . '×' . $pkg->dimZ . ' cm' : '';
            $itemLabel = '';
            $pkgKey = $pkg->id ?? 0;
            if (!empty($allocations[$pkgKey])) {
                $itemLabel = implode(', ', $allocations[$pkgKey]);
            }
            echo '<li class="dex-mb-pkg-summary-item">';
            echo '<div class="dex-mb-pkg-summary-icon"><span class="dashicons dashicons-archive"></span></div>';
            echo '<div class="dex-mb-pkg-summary-meta">';
            echo '<span class="dex-mb-pkg-summary-code">' . esc_html($pkg->code->value()) . '</span>';
            echo '<span>' . esc_html($massLabel) . ($dims !== '' ? ' · ' . esc_html($dims) : '') . '</span>';
            if ($itemLabel !== '') {
                echo '<span class="dex-mb-pkg-summary-items">' . esc_html($itemLabel) . '</span>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>'; // packages summary

        // Shipment info row
        echo '<div class="dex-mb-pending-info">';
        echo '<div class="dex-mb-info-item"><span class="dex-mb-info-label">' . esc_html__('Tip dostave', 'dexpress-woocommerce') . '</span><span>' . esc_html($shipment->deliveryType->label()) . '</span></div>';
        echo '<div class="dex-mb-info-item"><span class="dex-mb-info-label">' . esc_html__('Naplata', 'dexpress-woocommerce') . '</span><span>' . esc_html($shipment->paymentType->label()) . '</span></div>';
        if ($isPackageShop && $destination !== '') {
            echo '<div class="dex-mb-info-item"><span class="dex-mb-info-label">' . esc_html__('Primalac', 'dexpress-woocommerce') . '</span><span>' . esc_html($destination) . '</span></div>';
        }
        if ($shipment->content !== '') {
            echo '<div class="dex-mb-info-item"><span class="dex-mb-info-label">' . esc_html__('Sadržaj', 'dexpress-woocommerce') . '</span><span>' . esc_html($shipment->content) . '</span></div>';
        }
        echo '</div>';

        echo '</div>'; // pending-view

        // ── Edit wizard (hidden, shown when "Izmeni" is clicked) ──
        echo '<div id="dex-mb-edit-view" hidden>';
        echo '<div class="dex-mb-edit-back-bar">';
        echo '<button type="button" id="dex-mb-cancel-edit" class="dex-mb-btn dex-mb-btn--ghost">';
        echo '<span class="dashicons dashicons-arrow-left-alt"></span> ' . esc_html__('Otkaži izmene', 'dexpress-woocommerce');
        echo '</button>';
        echo '</div>';
        $this->renderWizard($order, $this->locations->findAll(), true);
        echo '</div>';
    }

    // ── State C: Sent ─────────────────────────────────────────────

    private function renderCreated(Shipment $shipment, \WC_Order $order): void
    {
        $trackingCode       = $shipment->trackingCode();
        $labelUrl           = $this->labelUrlForShipment($shipment);
        $apiResp            = strtoupper((string) $shipment->apiResponse());
        $isTest             = $apiResp === 'TEST';
        $isDryRun           = $apiResp === 'DRYRUN';
        $senderLocation     = $this->locations->findById($shipment->senderLocationId);
        $isPackageShopOrder = DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order);
        $packageShopName    = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
        $packageShopTypeLabel = trim((string) ($order->get_meta('_dexpress_package_shop_location_type_label') ?: __('Paket Shop', 'dexpress-woocommerce')));
        $recipientText      = $this->recipientDisplay($order, $isPackageShopOrder);
        $senderText         = $this->senderLocationDisplay($senderLocation);
        $manualEmailUrl     = $this->manualPackageShopEmailUrl($order);
        $allocations        = $this->packageItemAllocationsWithImages((int) $shipment->id());

        $timeline = $this->statusTimeline($order->get_id(), $shipment->currentSid(), $shipment->displayStatusLabel());
        if ($timeline === []) {
            $timeline[] = [
                'label'       => $shipment->displayStatusLabel() ?: __('Pošiljka je kreirana', 'dexpress-woocommerce'),
                'occurred_at' => $shipment->createdAt->format('Y-m-d H:i:s'),
                'state'       => 'current',
            ];
        }

        echo '<div class="dex-mb-created">';

        // ── Header: TT code + date/env left, action buttons right
        echo '<div class="dex-mb-created-header">';
        echo '<div class="dex-mb-created-header__left">';
        echo '<code class="dex-mb-code dex-mb-code--xl">' . esc_html($trackingCode) . '</code>';
        echo '<div class="dex-mb-created-header__meta">';
        echo '<span>' . esc_html(date_i18n('d.m.Y · H:i', $shipment->createdAt->getTimestamp())) . '</span>';
        $badgeKey   = $isDryRun ? 'dryrun' : ($isTest ? 'test' : 'prod');
        $badgeLabel = $isDryRun ? __('Probni rad', 'dexpress-woocommerce') : ($isTest ? 'TEST' : 'PROD');
        echo '<span class="dex-mb-env-badge dex-mb-env-badge--' . esc_attr($badgeKey) . '">' . esc_html($badgeLabel) . '</span>';
        echo '</div></div>'; // header left
        echo '<div class="dex-mb-created-header__actions">';
        echo '<button type="button" class="dex-mb-btn dex-mb-btn--outline dex-mb-copy-track" data-track="' . esc_attr($trackingCode) . '">';
        echo '<span class="dashicons dashicons-clipboard"></span> ' . esc_html__('Kopiraj kod', 'dexpress-woocommerce');
        echo '</button>';
        echo '<a class="dex-mb-btn dex-mb-btn--primary" href="' . esc_url($labelUrl) . '" target="_blank" rel="noopener">';
        echo '<span class="dashicons dashicons-printer"></span> ' . esc_html__('Štampaj nalepnicu', 'dexpress-woocommerce');
        echo '</a>';
        echo '</div></div>'; // header actions + header

        // ── Od / Za
        echo '<div class="dex-mb-created-parties">';
        echo '<div class="dex-mb-created-party">';
        echo '<div class="dex-mb-created-party__label">' . esc_html__('Od', 'dexpress-woocommerce') . '</div>';
        echo '<div class="dex-mb-created-party__value">' . esc_html($senderText) . '</div>';
        echo '</div>';
        echo '<div class="dex-mb-created-party">';
        echo '<div class="dex-mb-created-party__label">' . esc_html__('Za', 'dexpress-woocommerce') . '</div>';
        echo '<div class="dex-mb-created-party__value">' . esc_html($recipientText);
        if ($isPackageShopOrder) {
            echo ' <span class="dex-mb-pill">' . esc_html($packageShopTypeLabel) . '</span>';
            if ($packageShopName !== '') {
                echo '<br><small>' . esc_html($packageShopName) . '</small>';
            }
        }
        echo '</div></div></div>'; // parties

        // ── Packages with items
        echo '<div class="dex-mb-created-packages">';
        foreach ($shipment->packages as $pkgIdx => $pkg) {
            $pkgKey    = $pkg->id ?? 0;
            $massLabel = $pkg->mass !== null ? number_format($pkg->mass->value() / 1000, 2, ',', '') . ' kg' : '—';
            $dims      = ($pkg->dimX && $pkg->dimY && $pkg->dimZ) ? $pkg->dimX . '×' . $pkg->dimY . '×' . $pkg->dimZ . ' cm' : '';
            $items     = $allocations[$pkgKey] ?? [];

            echo '<div class="dex-mb-created-pkg">';
            echo '<div class="dex-mb-created-pkg__head">';
            echo '<span class="dashicons dashicons-archive"></span>';
            echo ' <strong>' . sprintf(esc_html__('Paket %d', 'dexpress-woocommerce'), $pkgIdx + 1) . '</strong>';
            echo '<code class="dex-mb-code">' . esc_html($pkg->code->value()) . '</code>';
            echo '<span class="dex-mb-created-pkg__weight">' . esc_html($massLabel) . '</span>';
            if ($dims !== '') {
                echo '<span class="dex-mb-created-pkg__dims">' . esc_html($dims) . '</span>';
            }
            echo '</div>'; // head

            if ($items !== []) {
                echo '<div class="dex-mb-created-pkg__items">';
                foreach ($items as $item) {
                    $img = $item['image_url'] !== ''
                        ? '<img src="' . esc_url($item['image_url']) . '" width="32" height="32" class="dex-mb-created-pkg__item-img" alt="">'
                        : '<span class="dex-mb-created-pkg__item-img dex-mb-created-pkg__item-img--placeholder dashicons dashicons-cart"></span>';
                    echo '<div class="dex-mb-created-pkg__item">';
                    echo $img;
                    echo '<span class="dex-mb-created-pkg__item-name">' . esc_html($item['name']) . '</span>';
                    echo '<span class="dex-mb-created-pkg__item-qty">×' . (int) $item['qty'] . '</span>';
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</div>'; // pkg
        }
        echo '</div>'; // packages

        // ── Timeline
        echo '<div class="dex-mb-timeline">';
        echo '<h4 class="dex-mb-timeline__title"><span class="dashicons dashicons-location"></span> ' . esc_html__('Status pošiljke', 'dexpress-woocommerce') . '</h4>';
        echo '<ol class="dex-mb-timeline__list">';
        foreach ($timeline as $step) {
            $cls  = $step['state'] === 'current' ? 'is-current' : ($step['state'] === 'completed' ? 'is-completed' : 'is-pending');
            $date = $step['occurred_at'] !== '' ? date_i18n('d.m.Y H:i', strtotime($step['occurred_at'])) : '';
            echo '<li class="dex-mb-timeline__item ' . esc_attr($cls) . '">';
            echo '<span class="dex-mb-timeline__dot" aria-hidden="true"></span>';
            echo '<div class="dex-mb-timeline__content">';
            echo '<strong>' . esc_html($step['label']) . '</strong>';
            if ($date !== '') {
                echo '<span>' . esc_html($date) . '</span>';
            }
            echo '</div></li>';
        }
        echo '</ol></div>';

        // ── Package Shop email (optional)
        if ($isPackageShopOrder && $manualEmailUrl !== '') {
            echo '<div>';
            echo '<a class="dex-mb-btn dex-mb-btn--ghost" href="' . esc_url($manualEmailUrl) . '">';
            echo '<span class="dashicons dashicons-email-alt"></span> ' . esc_html__('Pošalji email kupcu', 'dexpress-woocommerce');
            echo '</a></div>';
        }

        echo '</div>'; // created
    }

    /** @return array<int,list<array{name:string,qty:int,image_url:string}>> */
    private function packageItemAllocationsWithImages(int $shipmentId): array
    {
        if ($shipmentId <= 0) {
            return [];
        }
        global $wpdb;
        $piTable  = $wpdb->prefix . 'dexpress_package_items';
        $pkgTable = $wpdb->prefix . 'dexpress_packages';
        $oiTable  = $wpdb->prefix . 'woocommerce_order_items';
        $oimTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pi.package_id, pi.quantity, oi.order_item_name,
                        oim.meta_value AS product_id
                 FROM `{$piTable}` pi
                 INNER JOIN `{$pkgTable}` p ON p.id = pi.package_id
                 LEFT JOIN `{$oiTable}` oi ON oi.order_item_id = pi.order_item_id
                 LEFT JOIN `{$oimTable}` oim ON oim.order_item_id = pi.order_item_id AND oim.meta_key = '_product_id'
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
            $name      = trim((string) ($row['order_item_name'] ?? '')) ?: __('Stavka', 'dexpress-woocommerce');
            $qty       = max(1, (int) ($row['quantity'] ?? 0));
            $imageUrl  = '';
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0) {
                $imgId = (int) get_post_thumbnail_id($productId);
                if ($imgId > 0) {
                    $src = wp_get_attachment_image_src($imgId, [48, 48]);
                    $imageUrl = $src ? (string) $src[0] : '';
                }
            }
            $out[$pid][] = ['name' => $name, 'qty' => $qty, 'image_url' => $imageUrl];
        }
        return $out;
    }

    // ── Private helpers ───────────────────────────────────────────

    private function destinationLine(\WC_Order $order): string
    {
        $name = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
        $city = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
        return trim($name . ($name !== '' && $city !== '' ? ', ' : '') . $city);
    }

    /** @return array{delivery_type:string,payment_type:string,return_doc:string,self_drop_off:bool} */
    private function wizardDefaults(\WC_Order $order): array
    {
        return [
            'delivery_type' => $this->firstNonEmpty(
                (string) $order->get_meta('_dexpress_delivery_type'),
                $this->options->getString('shipment.default_delivery_type'),
                (string) DeliveryType::Regular->value,
            ),
            'payment_type' => $this->firstNonEmpty(
                (string) $order->get_meta('_dexpress_payment_type'),
                $this->options->getString('shipment.default_payment_type'),
                (string) PaymentType::Invoice->value,
            ),
            'return_doc' => $this->firstNonEmpty(
                (string) $order->get_meta('_dexpress_return_doc'),
                $this->options->getString('shipment.default_return_doc'),
                (string) ReturnDoc::None->value,
            ),
            'self_drop_off' => (bool) ((int) $this->firstNonEmpty(
                (string) $order->get_meta('_dexpress_self_drop_off'),
                $this->options->getString('shipment.default_self_drop_off'),
                '0',
            )),
        ];
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $v) {
            if ($v !== '') {
                return $v;
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

    /** @return array<int,array{label:string,occurred_at:string,state:string}> */
    private function statusTimeline(int $orderId, int $currentSid, string $snapshot): array
    {
        global $wpdb;
        $historyTable = $wpdb->prefix . 'dexpress_shipment_statuses';
        $shipmentsTable = $wpdb->prefix . 'dexpress_shipments';
        $codesTable = $wpdb->prefix . 'dexpress_status_codes';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.sid, h.status_label_snapshot, h.occurred_at,
                        COALESCE(NULLIF(sc.name_sr,''), NULLIF(sc.name_en,''), '') AS official_label
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
            $label = trim((string) ($row['official_label'] ?? '')) ?: trim((string) ($row['status_label_snapshot'] ?? ''));
            if ($label === '') {
                $label = sprintf(__('Status (sID: %d)', 'dexpress-woocommerce'), $sid);
            }
            if ($sid === $currentSid) {
                $currentIdx = $idx;
            }
            $timeline[] = ['label' => $label, 'occurred_at' => (string) ($row['occurred_at'] ?? ''), 'state' => 'pending'];
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

    /** @return array<int,list<string>> */
    private function packageItemAllocations(int $shipmentId): array
    {
        if ($shipmentId <= 0) {
            return [];
        }
        global $wpdb;
        $piTable = $wpdb->prefix . 'dexpress_package_items';
        $pkgTable = $wpdb->prefix . 'dexpress_packages';
        $oiTable = $wpdb->prefix . 'woocommerce_order_items';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pi.package_id, pi.quantity, oi.order_item_name
                 FROM `{$piTable}` pi
                 INNER JOIN `{$pkgTable}` p ON p.id = pi.package_id
                 LEFT JOIN `{$oiTable}` oi ON oi.order_item_id = pi.order_item_id
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
            $name = trim((string) ($row['order_item_name'] ?? '')) ?: __('Stavka', 'dexpress-woocommerce');
            $out[$pid][] = $name . ' ×' . max(1, (int) ($row['quantity'] ?? 0));
        }
        return $out;
    }

    /** @return array{options:array<string,mixed>,packages:array<int,array<string,mixed>>} */
    private function buildDraftFromShipment(Shipment $shipment): array
    {
        $packages = [];
        foreach ($shipment->packages as $pkg) {
            $packages[] = [
                'mass' => $pkg->mass?->value() ?? 500,
                'dim_x' => $pkg->dimX,
                'dim_y' => $pkg->dimY,
                'dim_z' => $pkg->dimZ,
                'content' => $pkg->contentNote ?? '',
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

    /** @param array<string,mixed>|null $loc */
    private function senderLocationDisplay(?array $loc): string
    {
        if (!is_array($loc)) {
            return __('Nije dostupno', 'dexpress-woocommerce');
        }
        $name = trim((string) ($loc['name'] ?? ''));
        $street = trim((string) ($loc['street_name'] ?? ''));
        $number = trim((string) ($loc['street_number'] ?? ''));
        $town = '';
        foreach ($this->locations->findAll() as $l) {
            if ((int) ($l['id'] ?? 0) === (int) ($loc['id'] ?? 0)) {
                $town = trim((string) ($l['town_name'] ?? ''));
                break;
            }
        }
        $parts = array_filter([$name, trim($street . ($number !== '' ? ' ' . $number : '')), $town]);
        return $parts !== [] ? implode(', ', $parts) : __('Nije dostupno', 'dexpress-woocommerce');
    }

    private function recipientDisplay(\WC_Order $order, bool $isPackageShop): string
    {
        if ($isPackageShop) {
            $name = trim((string) $order->get_meta('_dexpress_package_shop_location_name'));
            $address = trim((string) $order->get_meta('_dexpress_package_shop_location_address'));
            $city = trim((string) $order->get_meta('_dexpress_package_shop_location_city'));
            $parts = array_filter([$name, $address, $city]);
            return $parts !== [] ? implode(', ', $parts) : __('Lokacija nije dostupna', 'dexpress-woocommerce');
        }
        $name = trim((string) $order->get_formatted_shipping_full_name()) ?: trim((string) $order->get_formatted_billing_full_name());
        $address = trim((string) $order->get_shipping_address_1()) ?: trim((string) $order->get_billing_address_1());
        $city = trim((string) ($order->get_shipping_city() ?: $order->get_billing_city()));
        $parts = array_filter([$name, $address, $city]);
        return $parts !== [] ? implode(', ', $parts) : __('Primalac nije dostupan', 'dexpress-woocommerce');
    }

    private function manualPackageShopEmailUrl(\WC_Order $order): string
    {
        if (!DexpressPackageShopShippingMethod::orderUsesDexpressPackageShop($order)) {
            return '';
        }
        return wp_nonce_url(
            add_query_arg(['post' => $order->get_id(), 'action' => 'edit', 'dexpress_ps_ready_email' => 1], admin_url('post.php')),
            'dexpress_ps_ready_email_' . $order->get_id(),
        );
    }

    private function maybeHandleManualPackageShopEmail(\WC_Order $order): ?string
    {
        if (absint($_GET['dexpress_ps_ready_email'] ?? 0) !== 1) {
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
}
