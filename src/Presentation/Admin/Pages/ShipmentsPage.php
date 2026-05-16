<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;

final class ShipmentsPage
{
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly WpdbPackageProfileRepository $profiles,
        private readonly WpdbSenderLocationRepository $senderLocations,
        private readonly OptionsRepository $options,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        $orders          = $this->getPendingOrders();
        $pendingShipments = $this->getPendingShipments();
        $profiles         = $this->profiles->findAll();
        $locations        = $this->senderLocations->findAll();

        $pendingIds = array_column($pendingShipments, 'shipment_id');
        $pendingBulkPrintUrl = !empty($pendingIds)
            ? add_query_arg([
                'page'         => 'dexpress-label',
                'shipment_ids' => implode(',', $pendingIds),
                'nonce'        => wp_create_nonce('dexpress_bulk_print'),
            ], admin_url('admin.php'))
            : '';

        wp_localize_script('dex-shipments', 'dexShipments', [
            'ajaxUrl'              => admin_url('admin-ajax.php'),
            'labelBaseUrl'         => admin_url('admin.php'),
            'nonce'                => wp_create_nonce('dexpress_bulk_save_shipment'),
            'sendNonce'            => wp_create_nonce('dexpress_bulk_send_shipment'),
            'bulkPrintNonce'       => wp_create_nonce('dexpress_bulk_print'),
            'pendingBulkPrintUrl'  => $pendingBulkPrintUrl,
            'defaultSelfDropOff'   => $this->options->getBool('shipment.default_self_drop_off') ? '1' : '0',
            'i18n'           => [
                'saving'        => __('Kreiranje...', 'dexpress-woocommerce'),
                'sending'       => __('Slanje...', 'dexpress-woocommerce'),
                'saved'         => __('Kreirano', 'dexpress-woocommerce'),
                'sent'          => __('Poslato', 'dexpress-woocommerce'),
                'error'         => __('Greška', 'dexpress-woocommerce'),
                'print'         => __('Štampaj', 'dexpress-woocommerce'),
                'printAll'      => __('Štampaj sve etikete', 'dexpress-woocommerce'),
                'sendAll'       => __('Pošalji D-Expressu', 'dexpress-woocommerce'),
                'locationReq'   => __('Izaberite lokaciju pošiljaoca.', 'dexpress-woocommerce'),
                'noSelection'   => __('Izaberite bar jednu narudžbinu.', 'dexpress-woocommerce'),
                'confirmSend'   => __('Pošaljite pošiljke D-Expressu? Ova akcija je nepovratna.', 'dexpress-woocommerce'),
                'allDone'       => __('Sve pošiljke su kreirane.', 'dexpress-woocommerce'),
                'allSent'       => __('Sve pošiljke su poslate D-Expressu.', 'dexpress-woocommerce'),
                'copyTracking'  => __('Kopiraj kodove', 'dexpress-woocommerce'),
                'copied'        => __('Kopirano!', 'dexpress-woocommerce'),
                'sentCount'     => __('poslato', 'dexpress-woocommerce'),
                'errorCount'    => __('greška', 'dexpress-woocommerce'),
                'createdCount'  => __('kreirano', 'dexpress-woocommerce'),
                'confirmPendingSend' => __('Poslati sve čekajuće pošiljke D-Expressu? Ova akcija je nepovratna.', 'dexpress-woocommerce'),
                'pendingAllSent'    => __('Sve pošiljke su poslate D-Expressu.', 'dexpress-woocommerce'),
            ],
        ]);

        $codCount  = count(array_filter($orders, static fn($o) => $o['payment_method'] === 'cod'));
        $shopCount = count(array_filter($orders, static fn($o) => $o['is_shop']));
        ?>
        <div class="wrap dex-shipments-wrap">

            <div class="dex-page-header dex-page-header--shipments">
                <div class="dex-page-header__brand">
                    <img src="<?= esc_url(DEXPRESS_PLUGIN_URL . 'assets/images/Dexpress-logo.jpg') ?>"
                         alt="D Express" class="dex-page-header__logo">
                </div>
                <div class="dex-page-header__intro">
                    <h1 class="dex-page-header__title"><?= esc_html__('Pošiljke', 'dexpress-woocommerce') ?></h1>
                    <p class="dex-page-header__subtitle">
                        <?= esc_html__('Kreirajte D-Express pošiljke za narudžbine na čekanju', 'dexpress-woocommerce') ?>
                    </p>
                </div>
                <?php if (!empty($orders)): ?>
                <div class="dex-page-header__badge">
                    <span class="dashicons dashicons-cart" aria-hidden="true"></span>
                    <?= esc_html(sprintf(
                        /* translators: %d: number of pending orders */
                        _n('%d narudžbina', '%d narudžbine', count($orders), 'dexpress-woocommerce'),
                        count($orders),
                    )) ?>
                </div>
                <?php endif; ?>
            </div>

<?php if (empty($locations)): ?>
            <div class="dex-notice dex-notice--warning" style="margin-bottom:var(--dex-space-5);">
                <?= sprintf(
                    /* translators: %s: link to sender locations settings */
                    esc_html__('Nema podešenih lokacija pošiljaoca. %s', 'dexpress-woocommerce'),
                    '<a href="' . esc_url(admin_url('admin.php?page=dexpress-settings&tab=sender_locations')) . '">' . esc_html__('Podesite lokaciju', 'dexpress-woocommerce') . '</a>',
                ) ?>
            </div>
            <?php endif; ?>

            <!-- ═══ PENDING SEND CARD ════════════════════════════════════ -->
            <?php if (!empty($pendingShipments)): ?>
            <div class="dex-card dex-pending-card" id="dex-pending-card">

                <div class="dex-pending-card__header">
                    <div class="dex-pending-card__hd-icon" aria-hidden="true">
                        <span class="dashicons dashicons-upload"></span>
                    </div>
                    <div class="dex-pending-card__hd-text">
                        <h2 class="dex-pending-card__title">
                            <?= esc_html__('Čekaju slanje D-Expressu', 'dexpress-woocommerce') ?>
                            <span class="dex-pending-card__count" id="dex-pending-count"><?= count($pendingShipments) ?></span>
                        </h2>
                        <p class="dex-pending-card__sub">
                            <?= esc_html__('Pošiljke su spakovane i etikete su odštampane. Kada ste spremni, pošaljite ih D-Expressu.', 'dexpress-woocommerce') ?>
                        </p>
                    </div>
                </div>

                <div class="dex-pending-table-wrap">
                    <table class="dex-pending-table">
                        <thead>
                            <tr>
                                <th><?= esc_html__('Narudžbina', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Kupac', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Kod pošiljke', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Spakovano', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Etiketa', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Status', 'dexpress-woocommerce') ?></th>
                            </tr>
                        </thead>
                        <tbody id="dex-pending-tbody">
                            <?php foreach ($pendingShipments as $ps): ?>
                            <tr class="dex-prow" id="dex-prow-<?= (int) $ps['shipment_id'] ?>"
                                data-shipment-id="<?= (int) $ps['shipment_id'] ?>">
                                <td>
                                    <?php if ($ps['edit_url'] !== ''): ?>
                                    <a href="<?= esc_url((string) $ps['edit_url']) ?>" target="_blank"
                                       class="dex-prow__order">#<?= esc_html((string) $ps['order_number']) ?></a>
                                    <?php else: ?>
                                    <span class="dex-prow__order">#<?= esc_html((string) $ps['order_number']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="dex-prow__customer"><?= esc_html((string) $ps['customer']) ?></td>
                                <td class="dex-prow__track"><?= esc_html((string) $ps['tracking_code']) ?></td>
                                <td class="dex-prow__date"><?= esc_html((string) $ps['created_at']) ?></td>
                                <td>
                                    <a href="<?= esc_url((string) $ps['label_url']) ?>" target="_blank"
                                       class="dex-btn dex-btn--xs dex-btn--outline">
                                        <span class="dashicons dashicons-printer" aria-hidden="true"></span>
                                        <?= esc_html__('Štampaj', 'dexpress-woocommerce') ?>
                                    </a>
                                </td>
                                <td class="dex-prow__status">
                                    <span class="dex-badge dex-badge--neutral"><?= esc_html__('Čeka slanje', 'dexpress-woocommerce') ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="dex-pending-card__footer">
                    <div class="dex-pending-card__progress" id="dex-pending-progress" hidden>
                        <div class="dex-progress-bar">
                            <div class="dex-progress-bar__fill" id="dex-pending-fill" style="width:0%"></div>
                        </div>
                        <span class="dex-progress-text" id="dex-pending-progress-text">0 / 0</span>
                    </div>
                    <div class="dex-pending-card__actions">
                        <?php if ($pendingBulkPrintUrl !== ''): ?>
                        <a href="<?= esc_url($pendingBulkPrintUrl) ?>" target="_blank"
                           class="dex-btn dex-btn--outline" id="dex-pending-print-btn">
                            <span class="dashicons dashicons-printer" aria-hidden="true"></span>
                            <?= esc_html__('Štampaj sve etikete', 'dexpress-woocommerce') ?>
                        </a>
                        <?php endif; ?>
                        <button type="button" class="dex-btn dex-btn--primary" id="dex-pending-send-btn">
                            <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                            <?= esc_html(sprintf(
                                /* translators: %d: number of pending shipments */
                                __('Pošalji D-Expressu (%d)', 'dexpress-woocommerce'),
                                count($pendingShipments),
                            )) ?>
                        </button>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <!-- ═══ CONFIG CARD ═══════════════════════════════════════════ -->
            <div class="dex-card dex-shipments-config" id="dex-config-card">
                <div class="dex-shipments-config__header">
                    <div class="dex-shipments-config__hd-icon" aria-hidden="true">
                        <span class="dashicons dashicons-archive"></span>
                    </div>
                    <div class="dex-shipments-config__hd-text">
                        <h2 class="dex-shipments-config__title">
                            <?= esc_html__('Konfiguracija pošiljke', 'dexpress-woocommerce') ?>
                        </h2>
                        <p class="dex-shipments-config__subtitle">
                            <?= esc_html__('Podrazumevane vrednosti za sve narudžbine. Masu i dimenzije možete prilagoditi po redu ispod.', 'dexpress-woocommerce') ?>
                        </p>
                    </div>
                </div>

                <div class="dex-shipments-config__body">

                    <?php if (!empty($profiles)): ?>
                    <div class="dex-shipments-config__left">
                        <span class="dex-config-profiles-label">
                            <span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
                            <?= esc_html__('Profili paketa', 'dexpress-woocommerce') ?>
                        </span>
                        <div class="dex-config-profiles-grid">
                            <?php foreach ($profiles as $p):
                                $dimXCm = $p['dim_x'] !== null ? round((float) $p['dim_x'] / 10, 1) : '';
                                $dimYCm = $p['dim_y'] !== null ? round((float) $p['dim_y'] / 10, 1) : '';
                                $dimZCm = $p['dim_z'] !== null ? round((float) $p['dim_z'] / 10, 1) : '';
                            ?>
                            <button type="button"
                                    class="dex-profile-btn<?= !empty($p['is_default']) ? ' dex-profile-btn--active' : '' ?>"
                                    data-profile-id="<?= (int) $p['id'] ?>"
                                    data-weight-g="<?= (int) $p['weight_grams'] ?>"
                                    data-dim-x="<?= esc_attr((string) $dimXCm) ?>"
                                    data-dim-y="<?= esc_attr((string) $dimYCm) ?>"
                                    data-dim-z="<?= esc_attr((string) $dimZCm) ?>"
                                    data-content="<?= esc_attr((string) ($p['default_content'] ?? '')) ?>">
                                <span class="dex-profile-btn__name"><?= esc_html((string) $p['name']) ?></span>
                                <span class="dex-profile-btn__meta">
                                    <?php if ((int) $p['weight_grams'] > 0): ?>
                                    <?= esc_html(number_format((int) $p['weight_grams'] / 1000, 2, ',', '.') . ' kg') ?>
                                    <?php endif; ?>
                                    <?php if ($dimXCm !== '' && $dimYCm !== '' && $dimZCm !== ''): ?>
                                    · <?= esc_html($dimXCm . '×' . $dimYCm . '×' . $dimZCm . ' cm') ?>
                                    <?php endif; ?>
                                </span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="dex-shipments-config__right<?= empty($profiles) ? ' dex-shipments-config__right--full' : '' ?>">
                        <div class="dex-config-form">
                            <div class="dex-config-section">
                            <div class="dex-config-grid">

                                <div class="dex-field">
                                    <label class="dex-field__label" for="dex-cfg-location">
                                        <span class="dashicons dashicons-location" aria-hidden="true"></span>
                                        <?= esc_html__('Odakle šaljemo', 'dexpress-woocommerce') ?>
                                        <span class="dex-field__req" aria-hidden="true">*</span>
                                    </label>
                                    <select id="dex-cfg-location" class="dex-select">
                                        <option value="">— <?= esc_html__('izaberite', 'dexpress-woocommerce') ?> —</option>
                                        <?php foreach ($locations as $loc): ?>
                                        <option value="<?= (int) $loc['id'] ?>"<?= !empty($loc['is_default']) ? ' selected' : '' ?>>
                                            <?= esc_html((string) $loc['name']) ?>
                                            <?php if ((string) ($loc['town_name'] ?? '') !== ''): ?>
                                            — <?= esc_html((string) $loc['town_name']) ?>
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="dex-field">
                                    <label class="dex-field__label" for="dex-cfg-weight">
                                        <span class="dashicons dashicons-image-flip-vertical" aria-hidden="true"></span>
                                        <?= esc_html__('Masa (kg)', 'dexpress-woocommerce') ?>
                                    </label>
                                    <input type="number" id="dex-cfg-weight" class="dex-input dex-input--number"
                                           step="0.01" min="0" placeholder="0.00">
                                    <span class="dex-field__hint"><?= esc_html__('Masa ambalaže, sabira se sa artiklima.', 'dexpress-woocommerce') ?></span>
                                </div>

                                <div class="dex-field">
                                    <label class="dex-field__label">
                                        <span class="dashicons dashicons-editor-expand" aria-hidden="true"></span>
                                        <?= esc_html__('Dimenzije D×Š×V (cm)', 'dexpress-woocommerce') ?>
                                    </label>
                                    <div class="dex-dims-row">
                                        <input type="number" id="dex-cfg-dim-x" class="dex-input dex-input--dim" step="0.1" min="0" placeholder="D">
                                        <span class="dex-dims-sep">×</span>
                                        <input type="number" id="dex-cfg-dim-y" class="dex-input dex-input--dim" step="0.1" min="0" placeholder="Š">
                                        <span class="dex-dims-sep">×</span>
                                        <input type="number" id="dex-cfg-dim-z" class="dex-input dex-input--dim" step="0.1" min="0" placeholder="V">
                                    </div>
                                    <span class="dex-field__hint"><?= esc_html__('Spoljašnje dimenzije ambalaže.', 'dexpress-woocommerce') ?></span>
                                </div>

                                <div class="dex-field">
                                    <label class="dex-field__label" for="dex-cfg-content">
                                        <span class="dashicons dashicons-tag" aria-hidden="true"></span>
                                        <?= esc_html__('Sadržaj paketa', 'dexpress-woocommerce') ?>
                                        <span class="dex-field__req" aria-hidden="true">*</span>
                                    </label>
                                    <input type="text" id="dex-cfg-content" class="dex-input" maxlength="50"
                                           placeholder="<?= esc_attr__('npr. Odeća, Elektronika...', 'dexpress-woocommerce') ?>">
                                </div>

                                <div class="dex-field">
                                    <label class="dex-field__label" for="dex-cfg-note">
                                        <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                        <?= esc_html__('Napomena', 'dexpress-woocommerce') ?>
                                    </label>
                                    <input type="text" id="dex-cfg-note" class="dex-input" maxlength="150"
                                           placeholder="<?= esc_attr__('opciono', 'dexpress-woocommerce') ?>">
                                </div>

                                <div class="dex-field">
                                    <span class="dex-field__label">
                                        <?= esc_html__('Način predaje', 'dexpress-woocommerce') ?>
                                    </span>
                                    <div class="dex-dm">
                                        <label class="dex-dm__opt" for="dex-dm-courier">
                                            <input type="radio" id="dex-dm-courier" name="dex_delivery_mode"
                                                   class="dex-dm__input" value="0"
                                                   <?= !$this->options->getBool('shipment.default_self_drop_off') ? 'checked' : '' ?>>
                                            <span class="dex-dm__card">
                                                <span class="dex-dm__icon dashicons dashicons-location" aria-hidden="true"></span>
                                                <span class="dex-dm__title"><?= esc_html__('Kurir dolazi', 'dexpress-woocommerce') ?></span>
                                                <span class="dex-dm__sub"><?= esc_html__('na moju adresu', 'dexpress-woocommerce') ?></span>
                                            </span>
                                        </label>
                                        <label class="dex-dm__opt" for="dex-cfg-self-drop-off">
                                            <input type="radio" id="dex-cfg-self-drop-off" name="dex_delivery_mode"
                                                   class="dex-dm__input" value="1"
                                                   <?= $this->options->getBool('shipment.default_self_drop_off') ? 'checked' : '' ?>>
                                            <span class="dex-dm__card">
                                                <span class="dex-dm__icon dashicons dashicons-store" aria-hidden="true"></span>
                                                <span class="dex-dm__title"><?= esc_html__('Sam donosim', 'dexpress-woocommerce') ?></span>
                                                <span class="dex-dm__sub"><?= esc_html__('u D-Express', 'dexpress-woocommerce') ?></span>
                                            </span>
                                        </label>
                                    </div>
                                </div>

                            </div>
                            </div><!-- /.dex-config-section -->

                            <div class="dex-config-errors" id="dex-config-errors" hidden></div>
                        </div>
                    </div>

                </div>
            </div>

            <?php if (empty($orders)): ?>
            <!-- ═══ EMPTY STATE ══════════════════════════════════════════ -->
            <div class="dex-card dex-shipments-empty">
                <div class="dex-shipments-empty__body">
                    <div class="dex-shipments-empty__icon">
                        <img src="<?= esc_url(DEXPRESS_PLUGIN_URL . 'assets/images/package-box.svg') ?>" alt="" width="48" height="48">
                    </div>
                    <h3><?= esc_html__('Nema narudžbina na čekanju', 'dexpress-woocommerce') ?></h3>
                    <p><?= esc_html__('Sve narudžbine sa D-Express metodom dostave su već obrađene.', 'dexpress-woocommerce') ?></p>
                </div>
            </div>

            <?php else: ?>
            <!-- ═══ ORDERS CARD ══════════════════════════════════════════ -->
            <div class="dex-card dex-shipments-orders" id="dex-orders-card">

                <div class="dex-orders-header">
                    <div class="dex-orders-header__left">
                        <label class="dex-select-all-label">
                            <input type="checkbox" id="dex-select-all">
                        </label>
                        <div class="dex-orders-header__meta">
                            <span class="dex-orders-header__title"><?= esc_html__('Narudžbine za slanje', 'dexpress-woocommerce') ?></span>
                            <span class="dex-orders-header__count" id="dex-selected-label"><?= count($orders) ?></span>
                        </div>
                    </div>

                    <div class="dex-filter-tabs" id="dex-filter-tabs">
                        <button type="button" class="dex-filter-tab dex-filter-tab--active" data-filter="all">
                            <?= esc_html__('Sve', 'dexpress-woocommerce') ?>
                            <span class="dex-filter-tab__count"><?= count($orders) ?></span>
                        </button>
                        <?php if ($codCount > 0): ?>
                        <button type="button" class="dex-filter-tab" data-filter="cod">
                            <span class="dex-filter-tab__dot dex-filter-tab__dot--cod"></span>
                            <?= esc_html__('Pouzećem', 'dexpress-woocommerce') ?>
                            <span class="dex-filter-tab__count"><?= $codCount ?></span>
                        </button>
                        <?php endif; ?>
                        <?php if ($shopCount > 0): ?>
                        <button type="button" class="dex-filter-tab" data-filter="shop">
                            <span class="dex-filter-tab__dot dex-filter-tab__dot--shop"></span>
                            <?= esc_html__('Paket Shop', 'dexpress-woocommerce') ?>
                            <span class="dex-filter-tab__count"><?= $shopCount ?></span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dex-orders-grid" id="dex-orders-grid">
                    <?php foreach ($orders as $o): ?>
                    <div class="dex-order-card<?= $o['is_shop'] ? ' dex-order-card--shop' : '' ?>"
                         data-id="<?= (int) $o['id'] ?>"
                         data-number="<?= esc_attr((string) $o['number']) ?>"
                         data-customer="<?= esc_attr($o['customer']) ?>"
                         data-cod="<?= $o['payment_method'] === 'cod' ? '1' : '0' ?>"
                         data-shop="<?= $o['is_shop'] ? '1' : '0' ?>"
                         data-product-weight-kg="<?= esc_attr($o['weight_g'] > 0 ? number_format($o['weight_g'] / 1000, 3, '.', '') : '0') ?>"
                         data-content-suggestion="<?= esc_attr($o['content_suggestion']) ?>">

                        <!-- ── Naglavak kartice ───────────────────────────────────── -->
                        <div class="dex-order-card__header">
                            <label class="dex-order-card__check-wrap">
                                <input type="checkbox" class="dex-order-cb" value="<?= (int) $o['id'] ?>">
                            </label>
                            <div class="dex-order-card__hinfo">
                                <div class="dex-order-card__num-row">
                                    <a href="<?= esc_url((string) $o['edit_url']) ?>"
                                       class="dex-order-card__number"
                                       target="_blank">#<?= esc_html((string) $o['number']) ?></a>
                                    <?php if ($o['is_shop']): ?>
                                    <span class="dex-order-card__type-badge dex-order-card__type-badge--shop">Paket Shop</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ── Primalac (adresa) ──────────────────────────────────── -->
                        <div class="dex-order-card__recipient">
                            <div class="dex-order-card__customer"><?= esc_html((string) $o['customer']) ?></div>
                            <?php if ($o['shipping_address'] !== ''): ?>
                            <div class="dex-order-card__address">
                                <span class="dashicons dashicons-location" aria-hidden="true"></span><?= esc_html((string) $o['shipping_address']) ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($o['customer_phone'] !== ''): ?>
                            <div class="dex-order-card__phone">
                                <span class="dashicons dashicons-smartphone" aria-hidden="true"></span><?= esc_html((string) $o['customer_phone']) ?>
                            </div>
                            <?php endif; ?>

                            <div class="dex-order-card__financials">
                                <?php if ($o['payment_method'] === 'cod'): ?>
                                <div class="dex-order-card__cod">
                                    <span class="dex-order-card__cod-label"><?= esc_html__('Otkupnina', 'dexpress-woocommerce') ?></span>
                                    <span class="dex-order-card__cod-amount"><?= esc_html((string) $o['order_total']) ?></span>
                                </div>
                                <?php else: ?>
                                <div class="dex-order-card__paid">
                                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                    <?= esc_html__('Plaćeno online', 'dexpress-woocommerce') ?>
                                    <span class="dex-order-card__paid-amount"><?= esc_html((string) $o['order_total']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($o['items'])): ?>
                            <div class="dex-order-card__items">
                                <?php foreach ($o['items'] as $idx => $item): ?>
                                <?php if ($idx > 0): ?>, <?php endif; ?>
                                <?php if ($item['qty'] > 0): ?><?= (int) $item['qty'] ?>× <?php endif; ?><?= esc_html((string) $item['name']) ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- ── Polja za unos ──────────────────────────────────────── -->
                        <div class="dex-order-card__fields">
                            <div class="dex-card-valerr" hidden></div>
                            <div class="dex-order-card__fields-row">
                                <div class="dex-row-fg">
                                    <label class="dex-row-fg__label"><?= esc_html__('Masa (kg)', 'dexpress-woocommerce') ?></label>
                                    <input type="number"
                                           class="dex-input dex-input--number dex-row-weight"
                                           step="0.01" min="0"
                                           value="<?= $o['weight_g'] > 0 ? esc_attr(number_format($o['weight_g'] / 1000, 2, '.', '')) : '' ?>"
                                           placeholder="0.50">
                                </div>
                                <div class="dex-row-fg">
                                    <label class="dex-row-fg__label"><?= esc_html__('D×Š×V (cm)', 'dexpress-woocommerce') ?></label>
                                    <div class="dex-dims-row">
                                        <input type="number" class="dex-input dex-input--dim dex-row-dim-x" step="0.1" min="0" placeholder="D">
                                        <span class="dex-dims-sep">×</span>
                                        <input type="number" class="dex-input dex-input--dim dex-row-dim-y" step="0.1" min="0" placeholder="Š">
                                        <span class="dex-dims-sep">×</span>
                                        <input type="number" class="dex-input dex-input--dim dex-row-dim-z" step="0.1" min="0" placeholder="V">
                                    </div>
                                </div>
                                <div class="dex-row-fg dex-row-fg--reset">
                                    <button type="button" class="dex-row-reset"
                                            title="<?= esc_attr__('Resetuj na podrazumevane vrednosti', 'dexpress-woocommerce') ?>">↺</button>
                                </div>
                            </div>
                            <div class="dex-row-fg dex-row-fg--content">
                                <label class="dex-row-fg__label"><?= esc_html__('Sadržaj', 'dexpress-woocommerce') ?></label>
                                <input type="text" class="dex-input dex-row-content" maxlength="50"
                                       value="<?= esc_attr($o['content_suggestion']) ?>"
                                       placeholder="<?= esc_attr__('Sadržaj paketa', 'dexpress-woocommerce') ?>">
                            </div>
                            <div class="dex-row-fg dex-row-fg--note">
                                <label class="dex-row-fg__label"><?= esc_html__('Napomena', 'dexpress-woocommerce') ?></label>
                                <input type="text" class="dex-input dex-row-note" maxlength="150"
                                       placeholder="<?= esc_attr__('opciono', 'dexpress-woocommerce') ?>">
                            </div>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══ ACTION BAR ═══════════════════════════════════════════ -->
            <div class="dex-action-bar" id="dex-action-bar">
                <span class="dex-action-bar__info" id="dex-action-info">
                    <?= esc_html__('Izaberite narudžbine za kreiranje pošiljki', 'dexpress-woocommerce') ?>
                </span>
                <button type="button" class="dex-btn dex-btn--primary" id="dex-create-btn" disabled>
                    <?= esc_html__('Pregledaj →', 'dexpress-woocommerce') ?>
                </button>
            </div>
            <?php endif; ?>

            <!-- ═══ PREVIEW SECTION (step 2) ══════════════════════════════ -->
            <div class="dex-card dex-shipments-preview" id="dex-preview-section" hidden>

                <div class="dex-preview-header">
                    <div class="dex-preview-header__text">
                        <h2 class="dex-preview-header__title" id="dex-preview-title">
                            <?= esc_html__('Pregled pošiljki', 'dexpress-woocommerce') ?>
                        </h2>
                        <p class="dex-preview-header__sub">
                            <?= esc_html__('Proverite podatke. Nakon pakovanja i štampanja etiketa, šaljete D-Expressu.', 'dexpress-woocommerce') ?>
                        </p>
                    </div>
                    <div class="dex-preview-header__steps">
                        <span class="dex-step dex-step--done">
                            <span class="dex-step__num">1</span>
                            <?= esc_html__('Odabir', 'dexpress-woocommerce') ?>
                        </span>
                        <span class="dex-step dex-step--active">
                            <span class="dex-step__num">2</span>
                            <?= esc_html__('Pregled', 'dexpress-woocommerce') ?>
                        </span>
                        <span class="dex-step">
                            <span class="dex-step__num">3</span>
                            <?= esc_html__('Pakuj &amp; Štampaj', 'dexpress-woocommerce') ?>
                        </span>
                        <span class="dex-step">
                            <span class="dex-step__num">4</span>
                            <?= esc_html__('Pošalji', 'dexpress-woocommerce') ?>
                        </span>
                    </div>
                </div>

                <div class="dex-preview-table-wrap">
                    <table class="dex-preview-table">
                        <thead>
                            <tr>
                                <th><?= esc_html__('Narudžbina', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Kupac', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Masa', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Dimenzije (cm)', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Sadržaj', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Predaja', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Plaćanje', 'dexpress-woocommerce') ?></th>
                            </tr>
                        </thead>
                        <tbody id="dex-preview-tbody"></tbody>
                    </table>
                </div>

                <div class="dex-preview-footer">
                    <button type="button" class="dex-btn dex-btn--ghost" id="dex-preview-back-btn">
                        <span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
                        <?= esc_html__('Izmeni', 'dexpress-woocommerce') ?>
                    </button>
                    <button type="button" class="dex-btn dex-btn--primary" id="dex-proceed-btn">
                        <?= esc_html__('Pakuj i štampaj', 'dexpress-woocommerce') ?>
                    </button>
                </div>

            </div>

            <!-- ═══ RESULTS SECTION (step 3+4) ═══════════════════════════ -->
            <div class="dex-card dex-shipments-results" id="dex-results-section" hidden>

                <div class="dex-results-header">
                    <div class="dex-results-header__text">
                        <h2 class="dex-results-header__title" id="dex-results-title"></h2>
                    </div>
                    <div class="dex-preview-header__steps" id="dex-results-steps">
                        <span class="dex-step dex-step--done">
                            <span class="dex-step__num">1</span>
                            <?= esc_html__('Odabir', 'dexpress-woocommerce') ?>
                        </span>
                        <span class="dex-step dex-step--done">
                            <span class="dex-step__num">2</span>
                            <?= esc_html__('Pregled', 'dexpress-woocommerce') ?>
                        </span>
                        <span class="dex-step dex-step--active" id="dex-step-3">
                            <span class="dex-step__num">3</span>
                            <?= esc_html__('Pakuj &amp; Štampaj', 'dexpress-woocommerce') ?>
                        </span>
                        <span class="dex-step" id="dex-step-4">
                            <span class="dex-step__num">4</span>
                            <?= esc_html__('Pošalji', 'dexpress-woocommerce') ?>
                        </span>
                    </div>
                    <ul class="dex-results-errs" id="dex-results-errs" hidden></ul>
                </div>

                <div class="dex-results-progress" id="dex-results-progress">
                    <div class="dex-progress-bar">
                        <div class="dex-progress-bar__fill" id="dex-progress-fill" style="width:0%"></div>
                    </div>
                    <span class="dex-progress-text" id="dex-progress-text">0 / 0</span>
                </div>

                <div class="dex-results-table-wrap">
                    <table class="dex-results-table">
                        <thead>
                            <tr>
                                <th><?= esc_html__('Narudžbina', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Kupac', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Kod pošiljke', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Status', 'dexpress-woocommerce') ?></th>
                                <th><?= esc_html__('Akcije', 'dexpress-woocommerce') ?></th>
                            </tr>
                        </thead>
                        <tbody id="dex-results-tbody"></tbody>
                    </table>
                </div>

                <div class="dex-results-footer" id="dex-results-footer" hidden>
                    <div class="dex-results-footer__actions">
                        <button type="button" class="dex-btn dex-btn--outline" id="dex-print-all-btn">
                            <?= esc_html__('Štampaj sve etikete', 'dexpress-woocommerce') ?>
                        </button>
                        <button type="button" class="dex-btn dex-btn--primary" id="dex-send-all-btn">
                            <?= esc_html__('Pošalji D-Expressu', 'dexpress-woocommerce') ?>
                        </button>
                    </div>
                </div>

                <div class="dex-results-summary" id="dex-results-summary" hidden>
                    <div class="dex-summary-stats" id="dex-summary-stats"></div>
                    <div class="dex-summary-tracking" id="dex-summary-tracking" hidden>
                        <label class="dex-field__label">
                            <?= esc_html__('Kodovi pošiljki', 'dexpress-woocommerce') ?>
                        </label>
                        <textarea class="dex-tracking-codes" id="dex-tracking-textarea" readonly rows="4"></textarea>
                        <button type="button" class="dex-btn dex-btn--outline dex-btn--sm" id="dex-copy-btn">
                            <?= esc_html__('Kopiraj kodove', 'dexpress-woocommerce') ?>
                        </button>
                    </div>
                    <div class="dex-summary-back">
                        <a href="<?= esc_url(admin_url('admin.php?page=dexpress-shipments')) ?>"
                           class="dex-btn dex-btn--outline">
                            <?= esc_html__('Nova serija pošiljki', 'dexpress-woocommerce') ?>
                        </a>
                    </div>
                </div>

            </div>

        </div>
        <?php
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getPendingOrders(): array
    {
        $orderItemsTable    = $this->wpdb->prefix . 'woocommerce_order_items';
        $orderItemMetaTable = $this->wpdb->prefix . 'woocommerce_order_itemmeta';
        $shipmentsTable     = $this->wpdb->prefix . 'dexpress_shipments';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orderIds = $this->wpdb->get_col(
            "SELECT DISTINCT oi.order_id
             FROM `{$orderItemsTable}` oi
             INNER JOIN `{$orderItemMetaTable}` oim ON oim.order_item_id = oi.order_item_id
             LEFT JOIN `{$shipmentsTable}` sx ON sx.order_id = oi.order_id AND sx.deleted_at IS NULL
             WHERE oi.order_item_type = 'shipping'
               AND oim.meta_key = 'method_id'
               AND oim.meta_value IN ('dexpress', 'dexpress_package_shop')
               AND sx.id IS NULL
             ORDER BY oi.order_id DESC
             LIMIT 100",
        );

        if (empty($orderIds)) {
            return [];
        }

        $wcOrders = wc_get_orders([
            'include' => array_map('intval', $orderIds),
            'status'  => ['processing', 'on-hold'],
            'limit'   => -1,
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        if (empty($wcOrders)) {
            return [];
        }

        // PHP-level safety net: exclude any orders that already have a shipment record,
        // in case the SQL LEFT JOIN missed them due to caching or replication lag.
        $fetchedIds = array_map(static fn($o) => (int) $o->get_id(), $wcOrders);
        $ph         = implode(',', array_fill(0, count($fetchedIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $alreadyShipped = array_map('intval', (array) $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT DISTINCT order_id FROM `{$shipmentsTable}` WHERE deleted_at IS NULL AND order_id IN ({$ph})",
                ...$fetchedIds,
            ),
        ));
        if (!empty($alreadyShipped)) {
            $wcOrders = array_values(array_filter(
                $wcOrders,
                static fn($o) => !in_array((int) $o->get_id(), $alreadyShipped, true),
            ));
        }

        if (empty($wcOrders)) {
            return [];
        }

        $townIds = [];
        foreach ($wcOrders as $order) {
            $townId = (string) ($order->get_meta('_shipping_town_id') ?? '');
            if ($townId !== '') {
                $townIds[] = (int) $townId;
            }
        }
        $townMap = [];
        if (!empty($townIds)) {
            $uniqueTownIds = array_unique($townIds);
            $townsTable    = $this->wpdb->prefix . 'dexpress_towns';
            $placeholders  = implode(',', array_fill(0, count($uniqueTownIds), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, name FROM `{$townsTable}` WHERE id IN ({$placeholders})",
                    ...$uniqueTownIds,
                ),
                ARRAY_A,
            );
            foreach ((array) $rows as $row) {
                $townMap[(int) $row['id']] = (string) $row['name'];
            }
        }

        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $result = [];
        foreach ($wcOrders as $order) {
            $isShop = false;
            foreach ($order->get_shipping_methods() as $m) {
                if ($m->get_method_id() === 'dexpress_package_shop') {
                    $isShop = true;
                    break;
                }
            }

            $firstName = (string) $order->get_billing_first_name();
            $lastName  = (string) $order->get_billing_last_name();
            $customer  = trim("{$firstName} {$lastName}");
            if ($customer === '') {
                $customer = (string) $order->get_billing_company();
            }

            $orderId = $order->get_id();
            $editUrl = $hpos
                ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId)
                : admin_url('post.php?post=' . $orderId . '&action=edit');

            $townId   = (string) ($order->get_meta('_shipping_town_id') ?? '');
            $townName = $townId !== '' && isset($townMap[(int) $townId])
                ? $townMap[(int) $townId]
                : (string) $order->get_shipping_city();

            $streetName   = (string) ($order->get_meta('_shipping_street_name') ?? '');
            $streetNumber = (string) ($order->get_meta('_shipping_street_number') ?? '');
            $street       = trim($streetName . ($streetNumber !== '' ? ' ' . $streetNumber : ''));
            $postcode     = (string) $order->get_shipping_postcode();

            $addressParts    = array_filter([$townName, $street, $postcode], static fn(string $p) => $p !== '');
            $shippingAddress = implode(', ', $addressParts);

            $rawItems     = $order->get_items();
            $items        = [];
            $itemCount    = 0;
            $weightG      = 0;
            $allItemNames = [];

            foreach ($rawItems as $item) {
                if ($itemCount < 3) {
                    $items[] = [
                        'name' => (string) $item->get_name(),
                        'qty'  => (int) $item->get_quantity(),
                    ];
                }
                $allItemNames[] = (string) $item->get_name();
                $itemCount++;

                $product = $item->get_product();
                if ($product instanceof \WC_Product) {
                    $rawWeight = (float) $product->get_weight();
                    if ($rawWeight > 0) {
                        $weightG += (int) round(wc_get_weight($rawWeight, 'g') * $item->get_quantity());
                    }
                }
            }
            if ($itemCount > 3) {
                $items[] = [
                    'name' => sprintf(
                        /* translators: %d: number of additional items */
                        __('+ %d više', 'dexpress-woocommerce'),
                        $itemCount - 3,
                    ),
                    'qty' => 0,
                ];
            }

            $contentSuggestion = implode(', ', $allItemNames);
            if (mb_strlen($contentSuggestion) > 50) {
                $contentSuggestion = mb_substr($contentSuggestion, 0, 47) . '...';
            }

            $result[] = [
                'id'                 => $orderId,
                'number'             => $order->get_order_number(),
                'customer'           => $customer,
                'customer_email'     => (string) $order->get_billing_email(),
                'customer_phone'     => (string) $order->get_billing_phone(),
                'order_total'        => wp_strip_all_tags((string) wc_price($order->get_total())),
                'edit_url'           => $editUrl,
                'is_shop'            => $isShop,
                'shipping_address'   => $shippingAddress,
                'payment_method'     => (string) $order->get_payment_method(),
                'is_paid'            => $order->is_paid(),
                'items'              => $items,
                'weight_g'           => $weightG,
                'content_suggestion' => $contentSuggestion,
            ];
        }

        return $result;
    }

    /**
     * Shipments saved to DB (pending_send) but not yet sent to the D-Express API.
     * Used to build the persistent "Čekaju slanje" card on page load.
     *
     * @return list<array<string, mixed>>
     */
    private function getPendingShipments(): array
    {
        $shipmentsTable = $this->wpdb->prefix . 'dexpress_shipments';

        $packagesTable = $this->wpdb->prefix . 'dexpress_packages';
        $ordersTable   = $this->wpdb->prefix . 'wc_orders';
        $useHpos       = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($useHpos) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $this->wpdb->get_results(
                "SELECT s.id, s.order_id, s.created_at,
                        (SELECT MIN(pk.code) FROM `{$packagesTable}` pk WHERE pk.shipment_id = s.id) AS tracking_code
                 FROM `{$shipmentsTable}` s
                 INNER JOIN `{$ordersTable}` o ON o.id = s.order_id
                 WHERE s.send_status = 'pending_send' AND s.deleted_at IS NULL
                   AND o.status IN ('wc-processing','wc-on-hold')
                 ORDER BY s.created_at ASC
                 LIMIT 200",
                ARRAY_A,
            );
        } else {
            $postTable = $this->wpdb->prefix . 'posts';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $this->wpdb->get_results(
                "SELECT s.id, s.order_id, s.created_at,
                        (SELECT MIN(pk.code) FROM `{$packagesTable}` pk WHERE pk.shipment_id = s.id) AS tracking_code
                 FROM `{$shipmentsTable}` s
                 INNER JOIN `{$postTable}` p ON p.ID = s.order_id
                 WHERE s.send_status = 'pending_send' AND s.deleted_at IS NULL
                   AND p.post_status IN ('wc-processing','wc-on-hold')
                 ORDER BY s.created_at ASC
                 LIMIT 200",
                ARRAY_A,
            );
        }

        if (empty($rows)) {
            return [];
        }

        $orderIds = array_unique(array_map(static fn($r) => (int) $r['order_id'], $rows));

        $wcOrders = wc_get_orders(['include' => $orderIds, 'limit' => -1]);

        $orderMap = [];
        foreach ($wcOrders as $order) {
            $orderMap[$order->get_id()] = $order;
        }

        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $result = [];
        foreach ($rows as $row) {
            $shipmentId = (int) $row['id'];
            $orderId    = (int) $row['order_id'];
            $order      = $orderMap[$orderId] ?? null;

            $customer = '';
            $editUrl  = '';
            if ($order !== null) {
                $fn       = (string) $order->get_billing_first_name();
                $ln       = (string) $order->get_billing_last_name();
                $customer = trim("{$fn} {$ln}") ?: (string) $order->get_billing_company();
                $editUrl  = $hpos
                    ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId)
                    : admin_url('post.php?post=' . $orderId . '&action=edit');
            }

            $ts        = !empty($row['created_at']) ? strtotime((string) $row['created_at']) : 0;
            $createdAt = $ts > 0 ? (string) wp_date('d.m. H:i', $ts) : '';

            $result[] = [
                'shipment_id'   => $shipmentId,
                'order_id'      => $orderId,
                'order_number'  => $order ? (string) $order->get_order_number() : (string) $orderId,
                'customer'      => $customer,
                'tracking_code' => (string) ($row['tracking_code'] ?? ''),
                'created_at'    => $createdAt,
                'edit_url'      => $editUrl,
                'label_url'     => add_query_arg([
                    'page'        => 'dexpress-label',
                    'shipment_id' => $shipmentId,
                    'nonce'       => wp_create_nonce('dexpress_print_label_' . $shipmentId),
                ], admin_url('admin.php')),
            ];
        }

        return $result;
    }
}
