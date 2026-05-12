<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;

/**
 * Stranica za grupno kreiranje D-Express pošiljaka (3-koračni wizard).
 *
 * Pristupanje: redirect iz OrdersBulkAction → URL sadrži order_ids + nonce.
 * Registruje se kao hidden submenu (parent_slug = '').
 * Stanje između koraka drži JS (nema DB-a između koraka).
 */
final class BulkShipmentPage
{
    public const PAGE_SLUG = 'dexpress-bulk-shipment';

    public function __construct(
        private readonly WpdbPackageProfileRepository  $profiles,
        private readonly WpdbSenderLocationRepository  $locations,
    ) {}

    /**
     * Registruje admin_init hook koji postavlja $title pre nego što admin-header.php pozove
     * strip_tags(get_admin_page_title()) — hidden submeniji ne dobijaju $title automatski.
     */
    public function register(): void
    {
        add_action('admin_init', function (): void {
            if (($GLOBALS['pagenow'] ?? '') !== 'admin.php') {
                return;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (sanitize_key($_GET['page'] ?? '') !== self::PAGE_SLUG) {
                return;
            }
            global $title;
            if (!isset($title) || !is_string($title) || $title === '') {
                $title = (string) __('D Express — Grupno kreiranje pošiljaka', 'dexpress-woocommerce');
            } else {
                $title = (string) $title;
            }
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        $GLOBALS['title'] = __('D Express — Grupno kreiranje pošiljaka', 'dexpress-woocommerce');

        // Validacija nonce-a pri inicijalnom učitavanju.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'dexpress_bulk_init')) {
            wp_die(esc_html__('Nevažeći zahtev. Pokušajte ponovo sa liste narudžbina.', 'dexpress-woocommerce'));
        }

        // Parsiranje ID-ova narudžbina iz URL-a.
        $rawIds   = sanitize_text_field($_GET['order_ids'] ?? '');
        $orderIds = array_values(array_filter(
            array_map('absint', explode(',', $rawIds)),
            static fn (int $id): bool => $id > 0,
        ));

        if (empty($orderIds)) {
            wp_die(esc_html__('Nema narudžbina za obradu.', 'dexpress-woocommerce'));
        }

        // Učitaj podatke o narudžbinama.
        $orders = $this->loadOrders($orderIds);

        // Učitaj zavisnosti za JS.
        $profiles   = $this->profiles->findAll();
        $locations  = $this->locations->findAll();

        if (empty($locations)) {
            echo '<div class="wrap"><div class="notice notice-error"><p>'
                . esc_html__('Nema podešenih lokacija pošiljaoca. Dodajte lokaciju u Podešavanja → Lokacije pošiljaoca pre nego što koristite grupno kreiranje.', 'dexpress-woocommerce')
                . '</p></div></div>';
            return;
        }

        $defaultLocationId = 0;
        foreach ($locations as $loc) {
            if (!empty($loc['is_default'])) {
                $defaultLocationId = (int) $loc['id'];
                break;
            }
        }
        if ($defaultLocationId === 0 && !empty($locations)) {
            $defaultLocationId = (int) ($locations[0]['id'] ?? 0);
        }

        $skipShop    = absint($_GET['skip_shop'] ?? 0);
        $skipNoMethod = absint($_GET['skip_no_method'] ?? 0);
        $skipExisting = absint($_GET['skip_existing'] ?? 0);

        $ordersUrl = admin_url('edit.php?post_type=shop_order');
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            if (wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()) {
                $ordersUrl = admin_url('admin.php?page=wc-orders');
            }
        }

        // Nonces za AJAX akcije (prosleđuju se JS-u).
        $nonces = [
            'bulkSave' => wp_create_nonce('dexpress_bulk_save_shipment'),
            'bulkSend' => wp_create_nonce('dexpress_bulk_send_shipment'),
            'bulkPrint' => wp_create_nonce('dexpress_bulk_print'),
        ];

        ?>
<div class="wrap dex-bulk-wrap">
    <h1><?= esc_html__('D Express — Grupno kreiranje pošiljaka', 'dexpress-woocommerce') ?></h1>

    <?php if ($skipShop > 0 || $skipNoMethod > 0 || $skipExisting > 0): ?>
    <div class="notice notice-info is-dismissible">
        <p><?php
        $parts = [];
        if ($skipShop > 0) {
            $parts[] = esc_html(sprintf(
                _n('%d Package Shop narudžbina preskočena — kreirati pojedinačno', '%d Package Shop narudžbine preskočene', $skipShop, 'dexpress-woocommerce'),
                $skipShop,
            ));
        }
        if ($skipNoMethod > 0) {
            $parts[] = esc_html(sprintf(
                _n('%d narudžbina bez D-Express metode dostave', '%d narudžbina bez D-Express metode dostave', $skipNoMethod, 'dexpress-woocommerce'),
                $skipNoMethod,
            ));
        }
        if ($skipExist = $skipExisting) {
            $parts[] = esc_html(sprintf(
                _n('%d narudžbina već ima pošiljku', '%d narudžbina već ima pošiljku', $skipExist, 'dexpress-woocommerce'),
                $skipExist,
            ));
        }
        echo implode('<br>', $parts);
        ?></p>
    </div>
    <?php endif; ?>

    <!-- Progress stepper -->
    <div class="dex-bulk-stepper" aria-label="<?= esc_attr__('Koraci', 'dexpress-woocommerce') ?>">
        <div class="dex-bulk-step dex-bulk-step--active" data-step="1">
            <span class="dex-bulk-step__num">1</span>
            <span class="dex-bulk-step__label"><?= esc_html__('Podešavanja', 'dexpress-woocommerce') ?></span>
        </div>
        <div class="dex-bulk-step-line"></div>
        <div class="dex-bulk-step" data-step="2">
            <span class="dex-bulk-step__num">2</span>
            <span class="dex-bulk-step__label"><?= esc_html__('Pregled', 'dexpress-woocommerce') ?></span>
        </div>
        <div class="dex-bulk-step-line"></div>
        <div class="dex-bulk-step" data-step="3">
            <span class="dex-bulk-step__num">3</span>
            <span class="dex-bulk-step__label"><?= esc_html__('Štampa', 'dexpress-woocommerce') ?></span>
        </div>
        <div class="dex-bulk-step-line"></div>
        <div class="dex-bulk-step" data-step="4">
            <span class="dex-bulk-step__num">4</span>
            <span class="dex-bulk-step__label"><?= esc_html__('Slanje', 'dexpress-woocommerce') ?></span>
        </div>
    </div>

    <!-- Korak 1: Globalna podešavanja -->
    <div id="dex-bulk-step1" class="dex-bulk-panel">

        <?php if (!empty($profiles)): ?>
        <div class="dex-bulk-profiles-row">
            <h3><?= esc_html__('Profili paketa — kliknite da primenite na sve', 'dexpress-woocommerce') ?></h3>
            <div class="dex-bulk-profile-cards" id="dex-bulk-profile-cards">
                <?php foreach ($profiles as $p): ?>
                <?php
                $wG  = (int) $p['weight_grams'];
                $dxM = $p['dim_x'] !== null ? number_format((int) $p['dim_x'] / 10, 1, '.', '') : '';
                $dyM = $p['dim_y'] !== null ? number_format((int) $p['dim_y'] / 10, 1, '.', '') : '';
                $dzM = $p['dim_z'] !== null ? number_format((int) $p['dim_z'] / 10, 1, '.', '') : '';
                ?>
                <button type="button" class="dex-profile-card" data-weight-kg="<?= esc_attr($wG > 0 ? number_format($wG / 1000, 3, '.', '') : '') ?>"
                    data-dim-x="<?= esc_attr($dxM) ?>" data-dim-y="<?= esc_attr($dyM) ?>" data-dim-z="<?= esc_attr($dzM) ?>"
                    data-content="<?= esc_attr((string) ($p['default_content'] ?? '')) ?>">
                    <strong><?= esc_html((string) $p['name']) ?></strong>
                    <?php if ($wG > 0): ?><span><?= esc_html(number_format($wG / 1000, 3, ',', '') . ' kg') ?></span><?php endif; ?>
                    <?php if ($dxM !== '' && $dyM !== '' && $dzM !== ''): ?>
                    <span><?= esc_html("{$dxM} × {$dyM} × {$dzM} cm") ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['default_content'])): ?>
                    <span><?= esc_html((string) $p['default_content']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['is_default'])): ?>
                    <span class="dex-pp-badge dex-pp-badge--default"><?= esc_html__('Podrazumevani', 'dexpress-woocommerce') ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="notice notice-info inline">
            <p><?= sprintf(
                /* translators: %s: link to profiles page */
                esc_html__('Nemate sačuvanih profila paketa. %s', 'dexpress-woocommerce'),
                '<a href="' . esc_url(admin_url('admin.php?page=' . PackageProfilesPage::PAGE_SLUG)) . '">'
                    . esc_html__('Kreirajte profil paketa', 'dexpress-woocommerce')
                . '</a>',
            ) ?></p>
        </div>
        <?php endif; ?>

        <div class="dex-bulk-defaults-card">
            <h3><?= esc_html__('Podrazumevane vrednosti za sve narudžbine', 'dexpress-woocommerce') ?></h3>
            <table class="form-table">
                <tr>
                    <th><label for="dex-bulk-location"><?= esc_html__('Lokacija pošiljaoca', 'dexpress-woocommerce') ?> *</label></th>
                    <td>
                        <select id="dex-bulk-location" name="sender_location_id" required>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int) $loc['id'] ?>"
                                <?= (int) $loc['id'] === $defaultLocationId ? 'selected' : '' ?>>
                                <?= esc_html((string) ($loc['name'] ?? '')) ?>
                                <?php if (!empty($loc['is_default'])): ?>(<?= esc_html__('podrazumevana', 'dexpress-woocommerce') ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-bulk-delivery"><?= esc_html__('Tip dostave', 'dexpress-woocommerce') ?></label></th>
                    <td>
                        <select id="dex-bulk-delivery" name="delivery_type">
                            <?php foreach (DeliveryType::cases() as $dt): ?>
                            <option value="<?= $dt->value ?>"<?= $dt === DeliveryType::Regular ? ' selected' : '' ?>><?= esc_html($dt->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-bulk-payment"><?= esc_html__('Način plaćanja dostave', 'dexpress-woocommerce') ?></label></th>
                    <td>
                        <select id="dex-bulk-payment" name="payment_type">
                            <?php foreach (PaymentType::cases() as $pt): ?>
                            <option value="<?= $pt->value ?>"<?= $pt === PaymentType::Invoice ? ' selected' : '' ?>><?= esc_html($pt->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-bulk-returndoc"><?= esc_html__('Povraćaj dokumenta', 'dexpress-woocommerce') ?></label></th>
                    <td>
                        <select id="dex-bulk-returndoc" name="return_doc">
                            <?php foreach (ReturnDoc::cases() as $rd): ?>
                            <option value="<?= $rd->value ?>"><?= esc_html($rd->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?= esc_html__('Lično preuzimanje', 'dexpress-woocommerce') ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="dex-bulk-selfdrop" name="self_drop_off" value="1" />
                            <?= esc_html__('Da', 'dexpress-woocommerce') ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-bulk-weight"><?= esc_html__('Masa (kg)', 'dexpress-woocommerce') ?></label></th>
                    <td>
                        <input type="number" id="dex-bulk-weight" name="weight_kg" min="0.01" step="0.001" class="small-text" placeholder="0.500" />
                    </td>
                </tr>
                <tr>
                    <th><?= esc_html__('Dimenzije D×Š×V (cm)', 'dexpress-woocommerce') ?></th>
                    <td>
                        <span class="dex-bulk-dims-wrap">
                            <input type="number" id="dex-bulk-dx" name="dim_x" min="0" step="0.1" class="small-text" placeholder="30" />
                            <span>×</span>
                            <input type="number" id="dex-bulk-dy" name="dim_y" min="0" step="0.1" class="small-text" placeholder="20" />
                            <span>×</span>
                            <input type="number" id="dex-bulk-dz" name="dim_z" min="0" step="0.1" class="small-text" placeholder="10" />
                        </span>
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-bulk-content"><?= esc_html__('Sadržaj pošiljke', 'dexpress-woocommerce') ?> *</label></th>
                    <td>
                        <input type="text" id="dex-bulk-content" name="content" class="regular-text" maxlength="50" placeholder="<?= esc_attr__('npr. Odeća', 'dexpress-woocommerce') ?>" />
                    </td>
                </tr>
                <tr>
                    <th><label for="dex-bulk-note"><?= esc_html__('Napomena', 'dexpress-woocommerce') ?></label></th>
                    <td>
                        <input type="text" id="dex-bulk-note" name="note" class="regular-text" maxlength="150" />
                    </td>
                </tr>
            </table>
        </div>

        <div class="dex-bulk-step-footer">
            <a href="<?= esc_url(admin_url('admin.php?page=dexpress-shipments')) ?>" class="button"><?= esc_html__('← Nazad na pošiljke', 'dexpress-woocommerce') ?></a>
            <button type="button" class="button button-primary" id="dex-bulk-step1-next">
                <?= esc_html__('Pregled narudžbina →', 'dexpress-woocommerce') ?>
            </button>
        </div>
    </div><!-- #dex-bulk-step1 -->

    <!-- Korak 2: Pregled i izmena po narudžbini -->
    <div id="dex-bulk-step2" class="dex-bulk-panel" style="display:none;">
        <div class="dex-bulk-step2-header">
            <h3 style="margin:0;"><?= esc_html__('Pregled narudžbina', 'dexpress-woocommerce') ?></h3>
            <button type="button" class="button" id="dex-bulk-reset-defaults">
                <?= esc_html__('↺ Primeni default na sve', 'dexpress-woocommerce') ?>
            </button>
        </div>
        <div id="dex-bulk-orders-table-wrap"><!-- popunjava JS --></div>
        <div class="dex-bulk-step-footer">
            <button type="button" class="button" id="dex-bulk-step2-back"><?= esc_html__('← Nazad', 'dexpress-woocommerce') ?></button>
            <button type="button" class="button button-primary" id="dex-bulk-step2-next">
                <?= esc_html__('Sačuvaj i štampaj nalepnice →', 'dexpress-woocommerce') ?>
            </button>
        </div>
    </div><!-- #dex-bulk-step2 -->

    <!-- Korak 3: Kreiranje i štampa nalepnica -->
    <div id="dex-bulk-step3" class="dex-bulk-panel" style="display:none;">
        <h3><?= esc_html__('Kreiranje i štampa nalepnica', 'dexpress-woocommerce') ?></h3>

        <!-- Progress bar (prikazuje se tokom save faze) -->
        <div id="dex-bulk-progress-wrap" style="display:none;">
            <div class="dex-bulk-progress-bar">
                <div id="dex-bulk-progress-fill" class="dex-bulk-progress-fill" style="width:0%"></div>
            </div>
            <p id="dex-bulk-progress-label" class="description" aria-live="polite"></p>
        </div>

        <!-- Rezultati save faze + print akcije -->
        <div id="dex-bulk-results-wrap" style="display:none;">
            <div id="dex-bulk-results-table-wrap"><!-- popunjava JS --></div>
            <div class="dex-bulk-step-footer" id="dex-bulk-print-actions">
                <button type="button" class="button button-primary" id="dex-bulk-print-all">
                    <?= esc_html__('Štampaj sve nalepnice', 'dexpress-woocommerce') ?>
                </button>
                <button type="button" class="button button-primary" id="dex-bulk-step3-continue" disabled>
                    <?= esc_html__('Nastavi na slanje →', 'dexpress-woocommerce') ?>
                </button>
            </div>
        </div>
    </div><!-- #dex-bulk-step3 -->

    <!-- Korak 4: Slanje u D-Express -->
    <div id="dex-bulk-step4" class="dex-bulk-panel" style="display:none;">
        <h3><?= esc_html__('Slanje u D-Express', 'dexpress-woocommerce') ?></h3>
        <div class="notice notice-warning inline" style="margin-bottom:var(--dex-space-4,16px)">
            <p>
                <strong><?= esc_html__('Pažnja:', 'dexpress-woocommerce') ?></strong>
                <?= esc_html__('Ova akcija je nepovratna. Proverite da su nalepnice odštampane i paketi pripremljeni pre predaje kuriru.', 'dexpress-woocommerce') ?>
            </p>
        </div>
        <div id="dex-bulk-send-results-wrap"><!-- popunjava JS --></div>
        <div class="dex-bulk-step-footer">
            <button type="button" class="button button-primary" id="dex-bulk-send-all">
                <?= esc_html__('Pošalji sve u D-Express', 'dexpress-woocommerce') ?>
            </button>
            <a href="<?= esc_url($ordersUrl) ?>" class="button">
                <?= esc_html__('Nazad na narudžbine', 'dexpress-woocommerce') ?>
            </a>
        </div>
        <div id="dex-bulk-final-summary-wrap"></div>
    </div><!-- #dex-bulk-step4 -->
</div><!-- .dex-bulk-wrap -->

        <?php
        // Prosleđuje podatke JS-u.
        $this->enqueueBulkData($orders, $profiles, $nonces, $ordersUrl);
    }

    /**
     * Lokalizuje podatke za admin-bulk-shipment.js.
     *
     * @param list<array<string, mixed>> $orders
     * @param list<array<string, mixed>> $profiles
     * @param array<string, string>      $nonces
     */
    private function enqueueBulkData(array $orders, array $profiles, array $nonces, string $ordersUrl): void
    {
        wp_localize_script('dexpress-bulk-shipment', 'dexpressBulk', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonces'       => $nonces,
            'orders'       => $orders,
            'profiles'     => $profiles,
            'labelBase'    => admin_url('admin.php?page=dexpress-label'),
            'ordersUrl'    => $ordersUrl,
            'shipmentsUrl' => admin_url('admin.php?page=dexpress-shipments'),
            'i18n'         => [
                'saving'             => __('Kreiranje pošiljki...', 'dexpress-woocommerce'),
                'sending'            => __('Slanje u D-Express...', 'dexpress-woocommerce'),
                'saved'              => __('Sačuvano', 'dexpress-woocommerce'),
                'sent'               => __('Poslato', 'dexpress-woocommerce'),
                'error'              => __('Greška', 'dexpress-woocommerce'),
                'retry'              => __('Pokušaj ponovo', 'dexpress-woocommerce'),
                'printLabel'         => __('Nalepnica', 'dexpress-woocommerce'),
                'confirmSend'        => __('Poslati sve pošiljke u D-Express API? Ova akcija je nepovratna.', 'dexpress-woocommerce'),
                'order'              => __('Narudžbina', 'dexpress-woocommerce'),
                'customer'           => __('Kupac', 'dexpress-woocommerce'),
                'total'              => __('Iznos', 'dexpress-woocommerce'),
                'weight'             => __('Masa (kg)', 'dexpress-woocommerce'),
                'dims'               => __('Dim. D×Š×V (cm)', 'dexpress-woocommerce'),
                'content'            => __('Sadržaj', 'dexpress-woocommerce'),
                'note'               => __('Napomena', 'dexpress-woocommerce'),
                'status'             => __('Status', 'dexpress-woocommerce'),
                'trackingCode'       => __('Kod pošiljke', 'dexpress-woocommerce'),
                'weightReq'          => __('Masa mora biti > 0 za sve narudžbine.', 'dexpress-woocommerce'),
                'contentReq'         => __('Sadržaj je obavezan za sve narudžbine.', 'dexpress-woocommerce'),
                'step1Valid'         => __('Unesite masu i sadržaj pre prelaska na pregled.', 'dexpress-woocommerce'),
                'allDone'            => __('Sve pošiljke su obrađene!', 'dexpress-woocommerce'),
                'allSent'            => __('Sve pošiljke su uspešno poslate u D-Express', 'dexpress-woocommerce'),
                'partialSent'        => __('pošiljaka poslato', 'dexpress-woocommerce'),
                'partialFailed'      => __('nije uspelo', 'dexpress-woocommerce'),
                'copyTracking'       => __('Kopiraj kodove', 'dexpress-woocommerce'),
                'copied'             => __('Kopirano!', 'dexpress-woocommerce'),
                'trackingCodesTitle' => __('Kodovi pošiljaka', 'dexpress-woocommerce'),
                'printAllLabels'     => __('Štampaj sve nalepnice', 'dexpress-woocommerce'),
                'backToShipments'    => __('Povratak na pošiljke', 'dexpress-woocommerce'),
            ],
        ]);
    }

    /**
     * Učitava narudžbine i vraća neophodne podatke za prikaz u Step 2.
     * Kalkuliše težinu: baza 500g + suma težina WC stavki (po WC weight unit).
     *
     * @param list<int> $orderIds
     * @return list<array<string, mixed>>
     */
    private function loadOrders(array $orderIds): array
    {
        $result = [];
        $wUnit  = (string) get_option('woocommerce_weight_unit', 'kg');

        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $firstName = (string) $order->get_billing_first_name();
            $lastName  = (string) $order->get_billing_last_name();
            $customer  = trim("{$firstName} {$lastName}");
            if ($customer === '') {
                $customer = (string) $order->get_billing_company();
            }

            // Produkte lista + automatska kalkulacija mase
            $products       = [];
            $productWeightG = 0;
            foreach ($order->get_items() as $item) {
                if (!$item instanceof \WC_Order_Item_Product) {
                    continue;
                }
                $product = $item->get_product();
                $qty     = (int) $item->get_quantity();
                $itemG   = 0;
                if ($product instanceof \WC_Product && $product->get_weight() !== '') {
                    $itemG           = (int) round(wc_get_weight((float) $product->get_weight() * $qty, 'g', $wUnit));
                    $productWeightG += $itemG;
                }
                $products[] = [
                    'name'     => (string) $item->get_name(),
                    'qty'      => $qty,
                    'weight_g' => $itemG,
                ];
            }

            $baseWeightG  = 500;
            $totalWeightG = $baseWeightG + $productWeightG;
            $calcWeightKg = number_format($totalWeightG / 1000, 3, '.', '');

            // Adresa dostave
            $shippingCity  = (string) $order->get_shipping_city();
            $shippingAddr1 = (string) $order->get_shipping_address_1();
            $shippingAddr  = trim($shippingCity . ($shippingAddr1 !== '' ? ', ' . $shippingAddr1 : ''));

            // COD
            $isCod = $order->get_payment_method() === 'cod';

            // Package Shop
            $isShop = false;
            foreach ($order->get_shipping_methods() as $m) {
                if ($m->get_method_id() === 'dexpress_package_shop') {
                    $isShop = true;
                    break;
                }
            }

            $result[] = [
                'id'               => $orderId,
                'number'           => $order->get_order_number(),
                'customer'         => $customer,
                'total'            => html_entity_decode(wp_strip_all_tags((string) ($order->get_formatted_order_total() ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'edit_url'         => get_edit_post_link($orderId) ?? admin_url('post.php?post=' . $orderId . '&action=edit'),
                'products'         => $products,
                'product_weight_g' => $productWeightG,
                'base_weight_g'    => $baseWeightG,
                'calc_weight_kg'   => $calcWeightKg,
                'shipping_address' => $shippingAddr,
                'is_cod'           => $isCod,
                'is_shop'          => $isShop,
            ];
        }

        return $result;
    }
}
