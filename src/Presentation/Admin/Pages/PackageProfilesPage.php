<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Persistence\WpdbPackageProfileRepository;

final class PackageProfilesPage
{
    public const PAGE_SLUG = 'dexpress-package-profiles';

    public function __construct(
        private readonly WpdbPackageProfileRepository $profiles,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu.', 'dexpress-woocommerce'));
        }

        $profiles = $this->profiles->findAll();
        ?>
<div class="wrap dex-pp-wrap">
    <h1 class="wp-heading-inline"><?= esc_html__('D Express — Profili paketa', 'dexpress-woocommerce') ?></h1>
    <button type="button" class="page-title-action" id="dex-pp-add-btn">
        <?= esc_html__('+ Dodaj profil', 'dexpress-woocommerce') ?>
    </button>
    <hr class="wp-header-end" />

    <p class="description" style="margin-bottom:16px;">
        <?= esc_html__('Profili paketa čuvaju podrazumevane dimenzije i masu koji se mogu primeniti na sve narudžbine u jednom kliku tokom grupnog kreiranja pošiljaka.', 'dexpress-woocommerce') ?>
    </p>

    <!-- Forma za dodavanje / izmenu (skrivena po defaultu) -->
    <div id="dex-pp-form-wrap" class="dex-pp-form-wrap" style="display:none;">
        <div class="dex-pp-form-card">
            <h2 id="dex-pp-form-title" class="dex-pp-form-title"><?= esc_html__('Novi profil paketa', 'dexpress-woocommerce') ?></h2>
            <form id="dex-pp-form" autocomplete="off">
                <input type="hidden" id="dex-pp-id" name="id" value="0" />
                <div class="dex-pp-form-grid">
                    <div class="dex-pp-field dex-pp-field--full">
                        <label for="dex-pp-name"><?= esc_html__('Naziv profila', 'dexpress-woocommerce') ?> <span class="required">*</span></label>
                        <input type="text" id="dex-pp-name" name="name" class="regular-text" required placeholder="<?= esc_attr__('npr. Kutija M — Majice', 'dexpress-woocommerce') ?>" />
                    </div>
                    <div class="dex-pp-field dex-pp-field--full">
                        <label for="dex-pp-desc"><?= esc_html__('Opis (opcionalno)', 'dexpress-woocommerce') ?></label>
                        <textarea id="dex-pp-desc" name="description" rows="2" class="large-text"></textarea>
                    </div>
                    <div class="dex-pp-field">
                        <label for="dex-pp-weight"><?= esc_html__('Masa (kg)', 'dexpress-woocommerce') ?></label>
                        <input type="number" id="dex-pp-weight" name="weight_kg" min="0" step="0.01" class="small-text" placeholder="0.50" />
                    </div>
                    <div class="dex-pp-field">
                        <label for="dex-pp-dx"><?= esc_html__('Dimenzije D×Š×V (cm)', 'dexpress-woocommerce') ?></label>
                        <div class="dex-pp-dims">
                            <input type="number" id="dex-pp-dx" name="dim_x" min="0" step="0.1" class="small-text" placeholder="30" title="<?= esc_attr__('Dužina', 'dexpress-woocommerce') ?>" />
                            <span>×</span>
                            <input type="number" id="dex-pp-dy" name="dim_y" min="0" step="0.1" class="small-text" placeholder="20" title="<?= esc_attr__('Širina', 'dexpress-woocommerce') ?>" />
                            <span>×</span>
                            <input type="number" id="dex-pp-dz" name="dim_z" min="0" step="0.1" class="small-text" placeholder="10" title="<?= esc_attr__('Visina', 'dexpress-woocommerce') ?>" />
                        </div>
                    </div>
                    <div class="dex-pp-field dex-pp-field--full">
                        <label for="dex-pp-content"><?= esc_html__('Podrazumevani sadržaj', 'dexpress-woocommerce') ?></label>
                        <input type="text" id="dex-pp-content" name="default_content" class="regular-text" maxlength="50" placeholder="<?= esc_attr__('npr. Odeća', 'dexpress-woocommerce') ?>" />
                        <p class="description"><?= esc_html__('Prikazuje se na nalepnici. Maks. 50 znakova.', 'dexpress-woocommerce') ?></p>
                    </div>
                </div><!-- .dex-pp-form-grid -->
                <div class="dex-pp-form-actions">
                    <button type="submit" class="button button-primary" id="dex-pp-save-btn">
                        <?= esc_html__('Sačuvaj profil', 'dexpress-woocommerce') ?>
                    </button>
                    <button type="button" class="button" id="dex-pp-cancel-btn">
                        <?= esc_html__('Otkaži', 'dexpress-woocommerce') ?>
                    </button>
                    <span id="dex-pp-msg" class="dex-pp-msg" aria-live="polite"></span>
                </div>
            </form>
        </div>
    </div><!-- #dex-pp-form-wrap -->

    <!-- Tabela profila -->
    <div id="dex-pp-table-wrap">
        <?php $this->renderTable($profiles); ?>
    </div>
</div>
        <?php
    }

    /**
     * @param list<array<string, mixed>> $profiles
     */
    public function renderTable(array $profiles): void
    {
        if (empty($profiles)) {
            echo '<p class="description">'
                . esc_html__('Nema sačuvanih profila paketa. Kliknite "+ Dodaj profil" da kreirate prvi.', 'dexpress-woocommerce')
                . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped dex-pp-table">';
        echo '<thead><tr>'
            . '<th>' . esc_html__('Naziv', 'dexpress-woocommerce') . '</th>'
            . '<th>' . esc_html__('Dimenzije (cm)', 'dexpress-woocommerce') . '</th>'
            . '<th>' . esc_html__('Masa', 'dexpress-woocommerce') . '</th>'
            . '<th>' . esc_html__('Sadržaj', 'dexpress-woocommerce') . '</th>'
            . '<th>' . esc_html__('Akcije', 'dexpress-woocommerce') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($profiles as $profile) {
            $id   = (int) $profile['id'];
            $name = (string) $profile['name'];
            $isDefault = (bool) $profile['is_default'];

            $dimParts = [];
            foreach (['dim_x', 'dim_y', 'dim_z'] as $k) {
                $val = $profile[$k] !== null ? (int) $profile[$k] : null;
                $dimParts[] = $val !== null ? number_format($val / 10, 1) : '—';
            }
            $dims = implode(' × ', $dimParts);

            $weightG  = (int) $profile['weight_grams'];
            $weightKg = $weightG > 0 ? number_format($weightG / 1000, 3) . ' kg' : '—';

            $content = (string) ($profile['default_content'] ?? '');

            printf(
                '<tr data-profile="%s">',
                esc_attr((string) wp_json_encode([
                    'id'              => $id,
                    'name'            => $name,
                    'description'     => (string) ($profile['description'] ?? ''),
                    'weight_kg'       => $weightG > 0 ? number_format($weightG / 1000, 3, '.', '') : '',
                    'dim_x'           => $profile['dim_x'] !== null ? number_format((int) $profile['dim_x'] / 10, 1, '.', '') : '',
                    'dim_y'           => $profile['dim_y'] !== null ? number_format((int) $profile['dim_y'] / 10, 1, '.', '') : '',
                    'dim_z'           => $profile['dim_z'] !== null ? number_format((int) $profile['dim_z'] / 10, 1, '.', '') : '',
                    'default_content' => $content,
                ])),
            );

            echo '<td>';
            echo '<strong>' . esc_html($name) . '</strong>';
            if ($isDefault) {
                echo ' <span class="dex-pp-badge dex-pp-badge--default">'
                    . esc_html__('Podrazumevani', 'dexpress-woocommerce')
                    . '</span>';
            }
            if (!empty($profile['description'])) {
                echo '<br><span class="description">' . esc_html((string) $profile['description']) . '</span>';
            }
            echo '</td>';

            echo '<td>' . esc_html($dims) . '</td>';
            echo '<td>' . esc_html($weightKg) . '</td>';
            echo '<td>' . esc_html($content ?: '—') . '</td>';

            echo '<td class="dex-pp-actions">';
            echo '<button type="button" class="button button-small dex-pp-edit-btn" data-id="' . $id . '">'
                . esc_html__('Izmeni', 'dexpress-woocommerce') . '</button> ';
            if (!$isDefault) {
                echo '<button type="button" class="button button-small dex-pp-default-btn" data-id="' . $id . '">'
                    . esc_html__('Postavi kao podrazumevani', 'dexpress-woocommerce') . '</button> ';
            }
            echo '<button type="button" class="button button-small button-link-delete dex-pp-delete-btn" data-id="' . $id . '" data-name="' . esc_attr($name) . '">'
                . esc_html__('Obriši', 'dexpress-woocommerce') . '</button>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
