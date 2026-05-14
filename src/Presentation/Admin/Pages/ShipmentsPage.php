<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;

final class ShipmentsPage
{
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly WpdbPackageProfileRepository $profiles,
        private readonly WpdbSenderLocationRepository $senderLocations,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        $orders    = $this->getPendingOrders();
        $profiles  = $this->profiles->findAll();
        $locations = $this->senderLocations->findAll();

        wp_localize_script('dex-shipments', 'dexShipments', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'labelBaseUrl'   => admin_url('admin.php'),
            'nonce'          => wp_create_nonce('dexpress_bulk_save_shipment'),
            'sendNonce'      => wp_create_nonce('dexpress_bulk_send_shipment'),
            'bulkPrintNonce' => wp_create_nonce('dexpress_bulk_print'),
            'i18n'           => [
                'saving'       => __('Kreiranje...', 'dexpress-woocommerce'),
                'sending'      => __('Slanje...', 'dexpress-woocommerce'),
                'saved'        => __('Kreirano', 'dexpress-woocommerce'),
                'sent'         => __('Poslato', 'dexpress-woocommerce'),
                'error'        => __('Greška', 'dexpress-woocommerce'),
                'print'        => __('Štampaj', 'dexpress-woocommerce'),
                'printAll'     => __('Štampaj sve etikete', 'dexpress-woocommerce'),
                'sendAll'      => __('Pošalji D-Expressu', 'dexpress-woocommerce'),
                'locationReq'  => __('Izaberite lokaciju pošiljaoca.', 'dexpress-woocommerce'),
                'contentReq'   => __('Unesite sadržaj paketa.', 'dexpress-woocommerce'),
                'weightReq'    => __('Masa mora biti veća od 0 za sve izabrane narudžbine.', 'dexpress-woocommerce'),
                'noSelection'  => __('Izaberite bar jednu narudžbinu.', 'dexpress-woocommerce'),
                'confirmSend'  => __('Pošaljite pošiljke D-Expressu? Ova akcija je nepovratna.', 'dexpress-woocommerce'),
                'allDone'      => __('Sve pošiljke su kreirane.', 'dexpress-woocommerce'),
                'partialDone'  => __('Neke pošiljke nisu mogle biti kreirane.', 'dexpress-woocommerce'),
                'allSent'      => __('Sve pošiljke su poslate D-Expressu.', 'dexpress-woocommerce'),
                'partialSent'  => __('Neke pošiljke nisu mogle biti poslate.', 'dexpress-woocommerce'),
                'copyTracking' => __('Kopiraj kodove', 'dexpress-woocommerce'),
                'copied'       => __('Kopirano!', 'dexpress-woocommerce'),
                'orderNum'     => __('Narudžbina', 'dexpress-woocommerce'),
                'customer'     => __('Kupac', 'dexpress-woocommerce'),
                'tracking'     => __('Kod pošiljke', 'dexpress-woocommerce'),
                'status'       => __('Status', 'dexpress-woocommerce'),
                'actions'      => __('Akcije', 'dexpress-woocommerce'),
                'sentCount'    => __('poslato', 'dexpress-woocommerce'),
                'errorCount'   => __('greška', 'dexpress-woocommerce'),
                'createdCount' => __('kreirano', 'dexpress-woocommerce'),
            ],
        ]);

        $codCount  = count(array_filter($orders, static fn($o) => $o['payment_method'] === 'cod'));
        $shopCount = count(array_filter($orders, static fn($o) => $o['is_shop']));
        ?>
        <div class="wrap dex-shipments-wrap">

            <div class="dex-page-header">
                <div class="dex-page-header__left">
                    <h1 class="dex-page-header__title"><?= esc_html__('Pošiljke', 'dexpress-woocommerce') ?></h1>
                    <p class="dex-page-header__subtitle">
                        <?= esc_html__('Kreirajte D-Express pošiljke za narudžbine na čekanju', 'dexpress-woocommerce') ?>
                    </p>
                </div>
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

            <!-- ═══ CONFIG CARD ═══════════════════════════════════════════ -->
            <div class="dex-card dex-shipments-config" id="dex-config-card">
                <div class="dex-shipments-config__header">
                    <h2 class="dex-shipments-config__title">
                        <?= esc_html__('Konfiguracija pošiljke', 'dexpress-woocommerce') ?>
                    </h2>
                    <p class="dex-shipments-config__subtitle">
                        <?= esc_html__('Važi za sve izabrane narudžbine — možete prilagoditi po redu ispod.', 'dexpress-woocommerce') ?>
                    </p>
                </div>

                <?php if (!empty($profiles)): ?>
                <div class="dex-shipments-config__profiles">
                    <span class="dex-config-profiles-label">
                        <?= esc_html__('Profili paketa:', 'dexpress-woocommerce') ?>
                    </span>
                    <div class="dex-config-profiles-grid">
                        <?php foreach ($profiles as $p):
                            $dimXCm = $p['dim_x'] !== null ? round((float) $p['dim_x'] / 10, 1) : '';
                            $dimYCm = $p['dim_y'] !== null ? round((float) $p['dim_y'] / 10, 1) : '';
                            $dimZCm = $p['dim_z'] !== null ? round((float) $p['dim_z'] / 10, 1) : '';
                        ?>
                        <button type="button"
                                class="dex-profile-btn<?= !empty($p['is_default']) ? ' dex-profile-btn--active' : '' ?>"
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

                <div class="dex-config-form">
                    <div class="dex-config-grid">

                        <div class="dex-field">
                            <label class="dex-field__label" for="dex-cfg-location">
                                <?= esc_html__('Pošiljalac', 'dexpress-woocommerce') ?>
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
                            <label class="dex-field__label" for="dex-cfg-delivery">
                                <?= esc_html__('Vrsta dostave', 'dexpress-woocommerce') ?>
                            </label>
                            <select id="dex-cfg-delivery" class="dex-select">
                                <option value="2" selected><?= esc_html__('Standardna dostava', 'dexpress-woocommerce') ?></option>
                                <option value="1"><?= esc_html__('Hitno (isti dan)', 'dexpress-woocommerce') ?></option>
                            </select>
                        </div>

                        <div class="dex-field">
                            <label class="dex-field__label" for="dex-cfg-payment">
                                <?= esc_html__('Naplata dostave', 'dexpress-woocommerce') ?>
                            </label>
                            <select id="dex-cfg-payment" class="dex-select">
                                <option value="2" selected><?= esc_html__('Faktura', 'dexpress-woocommerce') ?></option>
                                <option value="1"><?= esc_html__('Gotovina', 'dexpress-woocommerce') ?></option>
                            </select>
                        </div>

                        <div class="dex-field">
                            <label class="dex-field__label" for="dex-cfg-returndoc">
                                <?= esc_html__('Povratna dokumentacija', 'dexpress-woocommerce') ?>
                            </label>
                            <select id="dex-cfg-returndoc" class="dex-select">
                                <option value="0" selected><?= esc_html__('Bez povraćaja', 'dexpress-woocommerce') ?></option>
                                <option value="1"><?= esc_html__('Povraćaj dokumenata', 'dexpress-woocommerce') ?></option>
                                <option value="3"><?= esc_html__('Potvrda o isporuci (POD)', 'dexpress-woocommerce') ?></option>
                            </select>
                        </div>

                        <div class="dex-field dex-field--span2">
                            <label class="dex-field__label" for="dex-cfg-content">
                                <?= esc_html__('Sadržaj paketa', 'dexpress-woocommerce') ?>
                                <span class="dex-field__req" aria-hidden="true">*</span>
                            </label>
                            <input type="text" id="dex-cfg-content" class="dex-input" maxlength="50"
                                   placeholder="<?= esc_attr__('npr. Odeća, Elektronika, Kozmetika...', 'dexpress-woocommerce') ?>">
                        </div>

                        <div class="dex-field dex-field--span2">
                            <label class="dex-field__label" for="dex-cfg-note">
                                <?= esc_html__('Napomena', 'dexpress-woocommerce') ?>
                            </label>
                            <input type="text" id="dex-cfg-note" class="dex-input" maxlength="150"
                                   placeholder="<?= esc_attr__('opciono', 'dexpress-woocommerce') ?>">
                        </div>

                        <div class="dex-field">
                            <label class="dex-field__label" for="dex-cfg-weight">
                                <?= esc_html__('Masa (kg)', 'dexpress-woocommerce') ?>
                            </label>
                            <input type="number" id="dex-cfg-weight" class="dex-input dex-input--number"
                                   step="0.01" min="0.01" placeholder="0.50">
                        </div>

                        <div class="dex-field">
                            <label class="dex-field__label">
                                <?= esc_html__('Dimenzije D×Š×V (cm)', 'dexpress-woocommerce') ?>
                            </label>
                            <div class="dex-dims-row">
                                <input type="number" id="dex-cfg-dim-x" class="dex-input dex-input--dim" step="0.1" min="0" placeholder="D">
                                <span class="dex-dims-sep">×</span>
                                <input type="number" id="dex-cfg-dim-y" class="dex-input dex-input--dim" step="0.1" min="0" placeholder="Š">
                                <span class="dex-dims-sep">×</span>
                                <input type="number" id="dex-cfg-dim-z" class="dex-input dex-input--dim" step="0.1" min="0" placeholder="V">
                            </div>
                        </div>

                        <div class="dex-field dex-field--checkbox-row">
                            <label class="dex-checkbox-label">
                                <input type="checkbox" id="dex-cfg-selfdrop">
                                <span><?= esc_html__('Samopredaja', 'dexpress-woocommerce') ?></span>
                            </label>
                            <span class="dex-field__hint">
                                <?= esc_html__('Pošiljalac sam donosi pošiljku u depo', 'dexpress-woocommerce') ?>
                            </span>
                        </div>

                    </div>

                    <div class="dex-config-errors" id="dex-config-errors" hidden></div>
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
                    <label class="dex-select-all-label">
                        <input type="checkbox" id="dex-select-all">
                        <span id="dex-selected-label">
                            <?= esc_html(sprintf(
                                /* translators: %d: number of orders */
                                __('Narudžbine za slanje (%d)', 'dexpress-woocommerce'),
                                count($orders),
                            )) ?>
                        </span>
                    </label>

                    <div class="dex-filter-tabs" id="dex-filter-tabs">
                        <button type="button" class="dex-filter-tab dex-filter-tab--active" data-filter="all">
                            <?= esc_html__('Sve', 'dexpress-woocommerce') ?>
                            <span class="dex-filter-tab__count"><?= count($orders) ?></span>
                        </button>
                        <?php if ($codCount > 0): ?>
                        <button type="button" class="dex-filter-tab" data-filter="cod">
                            <?= esc_html__('Pouzećem', 'dexpress-woocommerce') ?>
                            <span class="dex-filter-tab__count"><?= $codCount ?></span>
                        </button>
                        <?php endif; ?>
                        <?php if ($shopCount > 0): ?>
                        <button type="button" class="dex-filter-tab" data-filter="shop">
                            <?= esc_html__('Paket Shop', 'dexpress-woocommerce') ?>
                            <span class="dex-filter-tab__count"><?= $shopCount ?></span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dex-orders-list" id="dex-orders-list">
                    <?php foreach ($orders as $o): ?>
                    <div class="dex-order-row<?= $o['is_shop'] ? ' dex-order-row--shop' : '' ?>"
                         data-id="<?= (int) $o['id'] ?>"
                         data-cod="<?= $o['payment_method'] === 'cod' ? '1' : '0' ?>"
                         data-shop="<?= $o['is_shop'] ? '1' : '0' ?>"
                         data-product-weight-kg="<?= esc_attr($o['weight_g'] > 0 ? number_format($o['weight_g'] / 1000, 3, '.', '') : '0') ?>"
                         data-content-suggestion="<?= esc_attr($o['content_suggestion']) ?>">

                        <div class="dex-order-row__check">
                            <input type="checkbox" class="dex-order-cb" value="<?= (int) $o['id'] ?>">
                        </div>

                        <div class="dex-order-row__body">

                            <div class="dex-order-row__info">
                                <div class="dex-order-row__head">
                                    <a href="<?= esc_url((string) $o['edit_url']) ?>"
                                       class="dex-order-row__number"
                                       target="_blank">#<?= esc_html((string) $o['number']) ?></a>
                                    <span class="dex-order-row__customer"><?= esc_html((string) $o['customer']) ?></span>
                                    <div class="dex-order-row__badges">
                                        <?php if ($o['payment_method'] === 'cod'): ?>
                                        <span class="dex-badge dex-badge--warning"><?= esc_html__('Pouzećem', 'dexpress-woocommerce') ?></span>
                                        <?php elseif ($o['is_paid']): ?>
                                        <span class="dex-badge dex-badge--success"><?= esc_html__('Plaćeno', 'dexpress-woocommerce') ?></span>
                                        <?php endif; ?>
                                        <?php if ($o['is_shop']): ?>
                                        <span class="dex-badge dex-badge--info"><?= esc_html__('Paket Shop', 'dexpress-woocommerce') ?></span>
                                        <span class="dex-badge dex-badge--muted"><?= esc_html__('Bez povraćaja', 'dexpress-woocommerce') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="dex-order-row__meta">
                                    <?php if ($o['shipping_address'] !== ''): ?>
                                    <span class="dex-order-meta-item">
                                        <span class="dex-order-meta-label"><?= esc_html__('Adresa:', 'dexpress-woocommerce') ?></span>
                                        <?= esc_html((string) $o['shipping_address']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="dex-order-meta-item dex-order-meta-item--total">
                                        <?= esc_html((string) $o['order_total']) ?>
                                    </span>
                                    <?php if (!empty($o['items'])): ?>
                                    <span class="dex-order-meta-item dex-order-meta-item--items">
                                        <?php foreach ($o['items'] as $idx => $item): ?>
                                        <?php if ($idx > 0): ?>, <?php endif; ?>
                                        <?php if ($item['qty'] > 0): ?><?= (int) $item['qty'] ?>× <?php endif; ?><?= esc_html((string) $item['name']) ?>
                                        <?php endforeach; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="dex-order-row__fields">
                                <div class="dex-row-fg">
                                    <label class="dex-row-fg__label"><?= esc_html__('Masa (kg)', 'dexpress-woocommerce') ?></label>
                                    <input type="number"
                                           class="dex-input dex-input--number dex-row-weight"
                                           step="0.01" min="0.01"
                                           value="<?= $o['weight_g'] > 0 ? esc_attr(number_format($o['weight_g'] / 1000, 2, '.', '')) : '' ?>"
                                           placeholder="0.50">
                                </div>
                                <div class="dex-row-fg dex-row-fg--dims">
                                    <label class="dex-row-fg__label"><?= esc_html__('D×Š×V (cm)', 'dexpress-woocommerce') ?></label>
                                    <div class="dex-dims-row">
                                        <input type="number" class="dex-input dex-input--dim dex-row-dim-x" step="0.1" min="0" placeholder="D">
                                        <span class="dex-dims-sep">×</span>
                                        <input type="number" class="dex-input dex-input--dim dex-row-dim-y" step="0.1" min="0" placeholder="Š">
                                        <span class="dex-dims-sep">×</span>
                                        <input type="number" class="dex-input dex-input--dim dex-row-dim-z" step="0.1" min="0" placeholder="V">
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
                                <div class="dex-row-fg dex-row-fg--reset">
                                    <button type="button" class="dex-row-reset"
                                            title="<?= esc_attr__('Resetuj na podrazumevane vrednosti', 'dexpress-woocommerce') ?>">↺</button>
                                </div>
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
                    <?= esc_html__('Kreiraj pošiljke', 'dexpress-woocommerce') ?>
                </button>
            </div>
            <?php endif; ?>

            <!-- ═══ RESULTS SECTION (hidden initially) ═══════════════════ -->
            <div class="dex-card dex-shipments-results" id="dex-results-section" hidden>

                <div class="dex-results-header">
                    <h2 class="dex-results-header__title" id="dex-results-title"></h2>
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
            'status'  => ['processing'],
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
}
