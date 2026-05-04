<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Metabox;

use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageItemRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressShippingMethod;

final class OrderShipmentMetabox
{
    public function __construct(
        private readonly WpdbShipmentRepository       $shipments,
        private readonly WpdbSenderLocationRepository $locations,
        private readonly OptionsRepository            $options,
        private readonly WpdbPackageItemRepository   $packageItems,
        private readonly StatusCodeRepository         $statusCodes,
    ) {}

    public function register(): void
    {
        // HPOS / klasični ekran: do_action( 'add_meta_boxes', $screen_id, $post_or_order ).
        // WP podrazumevano prosleđuje samo 1 argument callback-u ako ne navedemo $accepted_args.
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 2);

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * @param string               $screenId     Post type (classic) or WC admin screen id (HPOS).
     * @param \WP_Post|\WC_Order   $postOrOrder  Order post (classic) or order object (HPOS).
     */
    public function addMetaBox(string $screenId, \WP_Post|\WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order
            ? $postOrOrder
            : wc_get_order($postOrOrder->ID);

        if (!$order instanceof \WC_Order) {
            return;
        }

        $this->registerMetaBoxIfDexpress($screenId, $order);
    }

    private function registerMetaBoxIfDexpress(string $screenId, \WC_Order $order): void
    {
        if (!DexpressShippingMethod::orderUsesDexpress($order)) {
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
            'D Express — pošiljke',
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
        if ($order === null || !DexpressShippingMethod::orderUsesDexpress($order)) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'dexpress-admin-metabox',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin.css',
            ['dashicons'],
            DEXPRESS_VERSION,
        );

        wp_enqueue_script(
            'dexpress-metabox',
            DEXPRESS_PLUGIN_URL . 'assets/js/admin-metabox.js',
            ['jquery'],
            DEXPRESS_VERSION,
            true,
        );

        $orderLineItems = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $orderLineItems[] = [
                'id'      => $item->get_id(),
                'name'    => $item->get_name(),
                'qty_max' => (int) $item->get_quantity(),
            ];
        }

        wp_localize_script('dexpress-metabox', 'dexpressMetabox', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('dexpress_create_shipment'),
            'orderId'        => $order->get_id(),
            'maxPackages'    => 100,
            'codAmount'      => number_format((float) $order->get_total(), 2, '.', ''),
            'orderLineItems' => $orderLineItems,
            'i18n'           => [
                'creating' => 'Kreiranje...',
                'create'   => 'Kreiraj pošiljku',
                'error'    => 'Greška pri kreiranju pošiljke.',
            ],
        ]);
    }

    public function render(\WP_Post|\WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order
            ? $postOrOrder
            : wc_get_order($postOrOrder->ID);

        if (!$order instanceof \WC_Order) {
            return;
        }

        $existingShipments = $this->shipments->findByOrderId($order->get_id());
        $senderLocations   = $this->locations->findAll();

        $this->renderExistingShipments($existingShipments, $order);
        $this->renderCreateForm($senderLocations, $order);
    }

    // -----------------------------------------------------------------------
    // Private render helpers
    // -----------------------------------------------------------------------

    /**
     * @param Shipment[] $shipments
     */
    private function renderExistingShipments(array $shipments, \WC_Order $order): void
    {
        if (empty($shipments)) {
            echo '<p style="color:#666;font-size:12px;">' .
                 esc_html__('Nema kreiranih pošiljaka za ovu narudžbinu.', 'dexpress-woocommerce') .
                 '</p>';
            return;
        }

        echo '<table class="widefat" style="margin-bottom:12px;font-size:12px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Kod', 'dexpress-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Status', 'dexpress-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Datum', 'dexpress-woocommerce') . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        foreach ($shipments as $shipment) {
            $labelUrl = add_query_arg([
                'page'        => 'dexpress-label',
                'shipment_id' => $shipment->id(),
                'nonce'       => wp_create_nonce('dexpress_print_label_' . $shipment->id()),
            ], admin_url('admin.php'));

            echo '<tr>';
            echo '<td><code>' . esc_html($shipment->trackingCode()) . '</code></td>';
            echo '<td class="dexpress-status-cell" data-shipment-id="' . esc_attr((string) $shipment->id()) . '">'
                 . esc_html($this->statusCodes->resolveOfficialShipmentStatusLabel(
                     $shipment->currentSid(),
                     $shipment->displayStatusLabel(),
                 )) . '</td>';
            echo '<td>' . esc_html($shipment->createdAt->format('d.m.Y H:i')) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($labelUrl) . '" target="_blank" class="button button-small">' .
                 esc_html__('Nalepnica', 'dexpress-woocommerce') . '</a>';
            echo '</td>';
            echo '</tr>';

            $packages = $shipment->packages;
            if ($packages !== []) {
                $allocByPkg = $this->packageItems->quantitiesByPackageId((int) $shipment->id());

                echo '<tr><td colspan="4" style="padding:0;background:#f6f7f7;border-top:none;">';
                echo '<table class="widefat" style="margin:0;font-size:11px;box-shadow:none;">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__('Paket', 'dexpress-woocommerce') . '</th>';
                echo '<th>' . esc_html__('Kod', 'dexpress-woocommerce') . '</th>';
                echo '<th>' . esc_html__('Težina', 'dexpress-woocommerce') . '</th>';
                echo '<th>' . esc_html__('Dimenzije (mm)', 'dexpress-woocommerce') . '</th>';
                echo '<th>' . esc_html__('ReferenceID', 'dexpress-woocommerce') . '</th>';
                echo '<th>' . esc_html__('Sadržaj paketa', 'dexpress-woocommerce') . '</th>';
                echo '<th>' . esc_html__('Stavke', 'dexpress-woocommerce') . '</th>';
                echo '</tr></thead><tbody>';
                foreach ($packages as $pkg) {
                    $pkgLabel = (string) $order->get_id() . '_PKG_' . (string) $pkg->ordinal;
                    $massShow = $pkg->mass !== null ? esc_html((string) $pkg->mass->value()) . ' g' : '—';
                    $dims     = [];
                    if ($pkg->dimX !== null && $pkg->dimX > 0) {
                        $dims[] = 'X=' . $pkg->dimX;
                    }
                    if ($pkg->dimY !== null && $pkg->dimY > 0) {
                        $dims[] = 'Y=' . $pkg->dimY;
                    }
                    if ($pkg->dimZ !== null && $pkg->dimZ > 0) {
                        $dims[] = 'Z=' . $pkg->dimZ;
                    }
                    $dimStr = $dims !== [] ? esc_html(implode(', ', $dims)) : '—';
                    $refShow = $pkg->referenceId !== null && $pkg->referenceId !== ''
                        ? esc_html($pkg->referenceId)
                        : esc_html($pkgLabel);

                    $pid = $pkg->id ?? 0;
                    $linesHtml = '—';
                    if ($pid > 0 && isset($allocByPkg[$pid]) && $allocByPkg[$pid] !== []) {
                        $parts = [];
                        foreach ($allocByPkg[$pid] as $row) {
                            $oi = $order->get_item($row['order_item_id']);
                            $label = $oi instanceof \WC_Order_Item_Product
                                ? $oi->get_name()
                                : '#' . $row['order_item_id'];
                            $parts[] = esc_html($label) . ' × ' . (int) $row['quantity'];
                        }
                        $linesHtml = implode('<br>', $parts);
                    }

                    $contentShow = ($pkg->contentNote !== null && $pkg->contentNote !== '')
                        ? esc_html($pkg->contentNote)
                        : '—';

                    echo '<tr>';
                    echo '<td><code>' . esc_html($pkgLabel) . '</code></td>';
                    echo '<td><code>' . esc_html($pkg->code->value()) . '</code></td>';
                    echo '<td>' . $massShow . '</td>';
                    echo '<td>' . $dimStr . '</td>';
                    echo '<td><code>' . $refShow . '</code></td>';
                    echo '<td>' . $contentShow . '</td>';
                    echo '<td style="max-width:140px;">' . $linesHtml . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</td></tr>';
            }
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int, array<string, mixed>> $senderLocations
     */
    private function renderCreateForm(array $senderLocations, \WC_Order $order): void
    {
        if (empty($senderLocations)) {
            echo '<p style="color:#b32d2e;">' .
                 esc_html__('Dodajte lokaciju pošiljaoca u podešavanjima pre kreiranja pošiljke.', 'dexpress-woocommerce') .
                 '</p>';
            return;
        }

        $defaults = $this->shipmentWizardDefaults($order);

        echo '<div id="dexpress-wizard">';

        // ---- Step indicator (dashicons) ----
        echo '<div id="dexpress-steps-indicator" class="dexpress-steps-indicator" role="navigation" aria-label="'
             . esc_attr__('Koraci čarobnjaka', 'dexpress-woocommerce') . '">';
        $stepsMeta = [
            1 => ['icon' => 'dashicons-archive', 'label' => __('Paketi', 'dexpress-woocommerce')],
            2 => ['icon' => 'dashicons-archive', 'label' => __('Težina i dimenzije', 'dexpress-woocommerce')],
            3 => ['icon' => 'dashicons-admin-generic', 'label' => __('Opcije pošiljke', 'dexpress-woocommerce')],
            4 => ['icon' => 'dashicons-yes', 'label' => __('Pregled', 'dexpress-woocommerce')],
        ];
        foreach ($stepsMeta as $num => $meta) {
            $pillClass = 'dexpress-step-pill' . ($num === 1 ? ' is-active' : '');
            echo '<span class="' . esc_attr($pillClass) . '" data-step="' . (int) $num . '" title="'
                 . esc_attr($meta['label']) . '">';
            echo '<span class="dashicons ' . esc_attr($meta['icon']) . '" aria-hidden="true"></span>';
            echo '<span class="dexpress-step-pill-num">' . (int) $num . '</span>';
            echo '</span>';
            if ($num < 4) {
                echo '<span class="dexpress-step-conn" aria-hidden="true"></span>';
            }
        }
        echo '</div>';

        // ---- Step 1: Package count ----
        echo '<div class="dexpress-wizard-step dexpress-metabox-card" id="dexpress-step-1">';
        echo '<div class="dexpress-metabox-card-head"><span class="dashicons dashicons-archive" aria-hidden="true"></span> ';
        echo esc_html__('Broj paketa', 'dexpress-woocommerce') . '</div>';
        echo '<div class="dexpress-metabox-card-body">';
        echo '<p class="dexpress-metabox-lead">' .
             esc_html__('Koliko fizičkih paketa šaljete u ovoj jednoj D Express pošiljci? (do 100)', 'dexpress-woocommerce') . '</p>';
        echo '<div class="dexpress-pkg-counter">';
        echo '<button type="button" id="dexpress-pkg-minus" class="button" aria-label="-">&minus;</button>';
        echo '<input type="number" id="dexpress-pkg-count" value="1" min="1" max="100" '
             . 'aria-label="' . esc_attr__('Broj paketa', 'dexpress-woocommerce') . '">';
        echo '<button type="button" id="dexpress-pkg-plus" class="button" aria-label="+">+</button>';
        echo '</div>';
        echo '</div></div>';

        // ---- Step 2: Package weight / dimensions ----
        echo '<div class="dexpress-wizard-step dexpress-metabox-card" id="dexpress-step-2" style="display:none;">';
        echo '<div class="dexpress-metabox-card-head"><span class="dashicons dashicons-archive" aria-hidden="true"></span> ';
        echo esc_html__('Težina i dimenzije po paketu', 'dexpress-woocommerce') . '</div>';
        echo '<div class="dexpress-metabox-card-body"><div id="dexpress-packages-list"></div></div>';
        echo '</div>';

        // ---- Step 3: Shipment options ----
        echo '<div class="dexpress-wizard-step dexpress-metabox-card" id="dexpress-step-3" style="display:none;">';
        echo '<div class="dexpress-metabox-card-head"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> ';
        echo esc_html__('Opcije pošiljke', 'dexpress-woocommerce') . '</div>';
        echo '<div class="dexpress-metabox-card-body">';

        echo '<p><label class="dexpress-field-label">' . esc_html__('Lokacija pošiljaoca', 'dexpress-woocommerce') . '</label>';
        echo '<select id="dexpress-sender-location" class="widefat">';
        foreach ($senderLocations as $loc) {
            $selected = $loc['is_default'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $loc['id']) . '"' . $selected . '>'
                 . esc_html((string) $loc['name']) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label class="dexpress-field-label">' . esc_html__('Tip dostave', 'dexpress-woocommerce') . '</label>';
        echo '<select id="dexpress-delivery-type" class="widefat">';
        foreach (DeliveryType::cases() as $type) {
            $selected = (string) $type->value === $defaults['delivery_type'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $type->value) . '"' . $selected . '>'
                 . esc_html($type->label()) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label class="dexpress-field-label">' . esc_html__('Način plaćanja', 'dexpress-woocommerce') . '</label>';
        echo '<select id="dexpress-payment-type" class="widefat">';
        foreach (PaymentType::cases() as $type) {
            $selected = (string) $type->value === $defaults['payment_type'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $type->value) . '"' . $selected . '>'
                 . esc_html($type->label()) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label class="dexpress-field-label">' . esc_html__('Povraćaj dokumenta', 'dexpress-woocommerce') . '</label>';
        echo '<select id="dexpress-return-doc" class="widefat">';
        foreach (ReturnDoc::cases() as $doc) {
            $selected = (string) $doc->value === $defaults['return_doc'] ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $doc->value) . '"' . $selected . '>'
                 . esc_html($doc->label()) . '</option>';
        }
        echo '</select></p>';

        $checked = $defaults['self_drop_off'] ? ' checked' : '';
        echo '<p><label>';
        echo '<input type="checkbox" id="dexpress-self-drop-off" value="1"' . $checked . '> ';
        echo esc_html__('Lično predajem kuriru (self drop-off)', 'dexpress-woocommerce');
        echo '</label></p>';

        echo '<p><label class="dexpress-field-label">' . esc_html__('Sadržaj', 'dexpress-woocommerce')
             . ' <span class="dexpress-req">*</span></label>';
        echo '<input type="text" id="dexpress-content" class="widefat" maxlength="50" '
             . 'placeholder="' . esc_attr__('npr. Odeća, elektronika...', 'dexpress-woocommerce') . '"></p>';

        echo '<p><label class="dexpress-field-label">' . esc_html__('Napomena', 'dexpress-woocommerce') . '</label>';
        echo '<input type="text" id="dexpress-note" class="widefat" maxlength="150" '
             . 'placeholder="' . esc_attr__('Opciono', 'dexpress-woocommerce') . '"></p>';

        echo '</div></div>';

        // ---- Step 4: Summary ----
        echo '<div class="dexpress-wizard-step dexpress-metabox-card" id="dexpress-step-4" style="display:none;">';
        echo '<div class="dexpress-metabox-card-head"><span class="dashicons dashicons-yes" aria-hidden="true"></span> ';
        echo esc_html__('Pregled i potvrda', 'dexpress-woocommerce') . '</div>';
        echo '<div class="dexpress-metabox-card-body"><div id="dexpress-summary"></div></div>';
        echo '</div>';

        echo '<div id="dexpress-wizard-error" class="dexpress-wizard-error" style="display:none;" role="alert"></div>';
        echo '<div id="dexpress-create-result" class="dexpress-create-result"></div>';

        echo '<div class="dexpress-wizard-nav">';
        echo '<button type="button" id="dexpress-wizard-back" class="button" style="display:none;">&larr; '
             . esc_html__('Nazad', 'dexpress-woocommerce') . '</button>';
        echo '<button type="button" id="dexpress-wizard-next" class="button button-primary">' . esc_html__('Dalje', 'dexpress-woocommerce')
             . ' &rarr;</button>';
        echo '<button type="button" id="dexpress-create-shipment-btn" class="button button-primary" style="display:none;">'
             . esc_html__('Kreiraj pošiljku', 'dexpress-woocommerce') . '</button>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Order meta overrides plugin defaults (from Settings → API).
     *
     * @return array{delivery_type: string, payment_type: string, return_doc: string, self_drop_off: bool}
     */
    private function shipmentWizardDefaults(\WC_Order $order): array
    {
        return [
            'delivery_type' => $this->resolveDefault(
                (string) $order->get_meta('_dexpress_delivery_type'),
                $this->options->getString('shipment.default_delivery_type'),
                (string) DeliveryType::Regular->value,
            ),
            'payment_type' => $this->resolveDefault(
                (string) $order->get_meta('_dexpress_payment_type'),
                $this->options->getString('shipment.default_payment_type'),
                (string) PaymentType::Invoice->value,
            ),
            'return_doc' => $this->resolveDefault(
                (string) $order->get_meta('_dexpress_return_doc'),
                $this->options->getString('shipment.default_return_doc'),
                (string) ReturnDoc::None->value,
            ),
            'self_drop_off' => (bool) ((int) $this->resolveDefault(
                (string) $order->get_meta('_dexpress_self_drop_off'),
                $this->options->getString('shipment.default_self_drop_off'),
                '0',
            )),
        ];
    }

    /**
     * Returns the first non-empty value from the provided candidates.
     */
    private function resolveDefault(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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
}
