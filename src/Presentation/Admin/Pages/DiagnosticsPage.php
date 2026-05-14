<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;

final class DiagnosticsPage
{
    public const PAGE_SLUG = 'dexpress-diagnostics';

    /** @var array<string, string> */
    private const SYNC_LABELS = [
        'sync.last_towns'          => 'Gradovi',
        'sync.last_streets'        => 'Ulice',
        'sync.last_municipalities' => 'Opštine',
        'sync.last_status_codes'   => 'Status kodovi',
        'sync.last_dispensers'     => 'Paketomati',
        'sync.last_locations'      => 'Lokacije',
        'sync.last_payments'       => 'Otkupnine',
        'sync.last_centres'        => 'Centri',
        'sync.last_shops'          => 'Prodavnice',
    ];

    public function __construct(
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        $syncedCount = 0;
        foreach (self::SYNC_LABELS as $key => $_unused) {
            if ($this->options->getString($key, '') !== '') {
                $syncedCount++;
            }
        }
        $totalCount   = count(self::SYNC_LABELS);
        $pendingCount = $totalCount - $syncedCount;

        $apiUrl = admin_url('admin.php?page=dexpress-settings&tab=api');
?>
        <div class="wrap dex-diagnostics">
            <div class="dex-page-header">
                <div class="dex-page-header__left">
                    <div class="dex-page-header__titles">
                        <h1 class="dex-page-header__title"><?= esc_html__('Dijagnostika', 'dexpress-woocommerce') ?></h1>
                        <p class="dex-page-header__subtitle"><?= esc_html__('Šifarnici, API veza i dnevni log sistema.', 'dexpress-woocommerce') ?></p>
                    </div>
                </div>
            </div>
            <hr class="wp-header-end" />

            <div class="dex-diagnostics__kpi-grid" role="group" aria-label="<?= esc_attr__('Pregled sinhronizacije šifarnika', 'dexpress-woocommerce') ?>">
                <div class="dex-stat-card">
                    <div class="dex-stat-card__icon dex-stat-card__icon--<?= $syncedCount === $totalCount ? 'green' : 'amber' ?>">
                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    </div>
                    <div class="dex-stat-card__body">
                        <p class="dex-stat-card__value <?= $syncedCount === $totalCount ? 'dex-stat-card__value--success' : 'dex-stat-card__value--warning' ?>"><?= (int) $syncedCount ?></p>
                        <p class="dex-stat-card__label"><?= esc_html__('Sinhronizovano', 'dexpress-woocommerce') ?></p>
                    </div>
                </div>
                <div class="dex-stat-card">
                    <div class="dex-stat-card__icon dex-stat-card__icon--blue">
                        <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                    </div>
                    <div class="dex-stat-card__body">
                        <p class="dex-stat-card__value"><?= (int) $totalCount ?></p>
                        <p class="dex-stat-card__label"><?= esc_html__('Ukupno šifarnika', 'dexpress-woocommerce') ?></p>
                    </div>
                </div>
                <div class="dex-stat-card">
                    <div class="dex-stat-card__icon dex-stat-card__icon--<?= $pendingCount > 0 ? 'amber' : 'green' ?>">
                        <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                    </div>
                    <div class="dex-stat-card__body">
                        <p class="dex-stat-card__value <?= $pendingCount > 0 ? 'dex-stat-card__value--warning' : 'dex-stat-card__value--success' ?>"><?= (int) $pendingCount ?></p>
                        <p class="dex-stat-card__label"><?= esc_html__('Na čekanju', 'dexpress-woocommerce') ?></p>
                    </div>
                </div>
            </div>

            <?php if ($syncedCount === $totalCount) : ?>
                <div class="dex-notice dex-notice--success dex-diagnostics__notice">
                    <div class="dex-notice__content">
                        <p class="dex-notice__body">
                            <?= esc_html(
                                sprintf(
                                    /* translators: 1: synced count, 2: total catalogs */
                                    __('Svi šifarnici su sinhronizovani (%1$d / %2$d).', 'dexpress-woocommerce'),
                                    $syncedCount,
                                    $totalCount,
                                ),
                            ) ?>
                        </p>
                    </div>
                </div>
            <?php else : ?>
                <div class="dex-notice dex-notice--warning dex-diagnostics__notice">
                    <div class="dex-notice__content">
                        <p class="dex-notice__body">
                            <?= esc_html(
                                sprintf(
                                    /* translators: 1: synced count, 2: total count, 3: pending */
                                    __('Sinhronizovano %1$d od %2$d šifarnika. Na čekanju: %3$d.', 'dexpress-woocommerce'),
                                    $syncedCount,
                                    $totalCount,
                                    $pendingCount,
                                ),
                            ) ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dex-diagnostics__split">
                <section class="dex-card">
                    <div class="dex-card__header">
                        <h2 class="dex-card__title"><?= esc_html__('Šifarnici', 'dexpress-woocommerce') ?></h2>
                    </div>
                    <div class="dex-card__body">
                        <p class="description"><?= esc_html__('Poslednje vreme sinhronizacije po katalogu.', 'dexpress-woocommerce') ?></p>
                        <div class="dex-table-wrap">
                            <table class="dex-table">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col"><?= esc_html__('Katalog', 'dexpress-woocommerce') ?></th>
                                        <th scope="col"><?= esc_html__('Poslednja sinhronizacija', 'dexpress-woocommerce') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $i = 1;
                                    foreach (self::SYNC_LABELS as $key => $label) {
                                        $v        = $this->options->getString($key, '');
                                        $rowClass = $v !== '' ? 'dex-diagnostics__row--synced' : 'dex-diagnostics__row--pending';
                                    ?>
                                        <tr class="<?= esc_attr($rowClass) ?>">
                                            <td><?= (int) $i++ ?></td>
                                            <td><?= esc_html($label) ?></td>
                                            <td><code><?= esc_html($v !== '' ? $v : '—') ?></code></td>
                                        </tr>
                                    <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="dex-card">
                    <div class="dex-card__header">
                        <h2 class="dex-card__title"><?= esc_html__('API', 'dexpress-woocommerce') ?></h2>
                    </div>
                    <div class="dex-card__body">
                        <p><?= esc_html__('Test konekcije i API ključ podešavate na tabu API.', 'dexpress-woocommerce') ?></p>
                        <p class="dex-diagnostics__api-actions">
                            <a class="dex-btn dex-btn--primary" href="<?= esc_url($apiUrl) ?>"><?= esc_html__('Otvori podešavanja — API', 'dexpress-woocommerce') ?></a>
                        </p>
                    </div>
                </section>
            </div>

            <section class="dex-card">
                <div class="dex-card__header">
                    <h2 class="dex-card__title"><?= esc_html__('Log', 'dexpress-woocommerce') ?></h2>
                    <button type="button" class="dex-btn dex-btn--sm dex-btn--secondary" id="dexpress-diagnostics-copy-log"><?= esc_html__('Kopiraj', 'dexpress-woocommerce') ?></button>
                </div>
                <div class="dex-card__body">
                    <p class="description dex-diagnostics__log-lead">
                        <?= esc_html(
                            sprintf(
                                /* translators: %s: date Y-m-d */
                                __('Dnevni log (%s), poslednjih 200 redova.', 'dexpress-woocommerce'),
                                gmdate('Y-m-d'),
                            ),
                        ) ?>
                    </p>
                    <?php
                    $path = $this->logger->getLogDirectory() . 'dexpress-' . gmdate('Y-m-d') . '.log';
                    if (!is_readable($path)) {
                        echo '<p><em>' . esc_html__('Nema log fajla za današnji dan.', 'dexpress-woocommerce') . '</em></p>';
                    } else {
                        $lines = file($path, FILE_IGNORE_NEW_LINES);
                        if (!is_array($lines)) {
                            echo '<p><em>' . esc_html__('Log se ne može pročitati.', 'dexpress-woocommerce') . '</em></p>';
                        } else {
                            $tail = array_slice($lines, -200);
                    ?>
                            <textarea readonly="readonly" rows="18" id="dexpress-diagnostics-log" class="dex-diagnostics__log"><?= esc_textarea(implode("\n", $tail)) ?></textarea>
                    <?php
                        }
                    }
                    ?>
                    <p class="dex-diagnostics__log-meta">
                        <strong><?= esc_html__('Direktorijum:', 'dexpress-woocommerce') ?></strong>
                        <code><?= esc_html($this->logger->getLogDirectory()) ?></code>
                    </p>
                </div>
            </section>
        </div>
        <script>
            (function() {
                var btn = document.getElementById('dexpress-diagnostics-copy-log');
                var ta = document.getElementById('dexpress-diagnostics-log');
                if (!btn || !ta) return;
                var label = btn.textContent;
                var copied = <?= wp_json_encode(__('Kopirano.', 'dexpress-woocommerce')) ?>;
                btn.addEventListener('click', function() {
                    if (!navigator.clipboard || !navigator.clipboard.writeText) return;
                    navigator.clipboard.writeText(ta.value).then(function() {
                        btn.textContent = copied;
                        setTimeout(function() {
                            btn.textContent = label;
                        }, 2000);
                    });
                });
            }());
        </script>
<?php
    }
}
