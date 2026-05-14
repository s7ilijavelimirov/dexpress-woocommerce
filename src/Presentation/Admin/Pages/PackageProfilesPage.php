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
        <div class="wrap dex-pp-page">
            <div class="dex-page-header">
                <div class="dex-page-header__left">
                    <div class="dex-page-header__titles">
                        <h1 class="dex-page-header__title"><?= esc_html__('Profili paketa', 'dexpress-woocommerce') ?></h1>
                        <p class="dex-page-header__subtitle"><?= esc_html__('Sačuvajte šablone kutija (masa, dimenzije, podrazumevani opis) i primenite ih jednim klikom pri grupnom kreiranju pošiljaka — bez ponavljanja unosa za svaku narudžbinu.', 'dexpress-woocommerce') ?></p>
                    </div>
                </div>
            </div>
            <hr class="wp-header-end" />

            <!-- Modal za dodavanje / izmenu -->
            <div id="dex-pp-modal" class="dex-pp-modal" role="dialog" aria-modal="true" aria-labelledby="dex-pp-form-title">
                <div class="dex-pp-modal__backdrop"></div>
                <div class="dex-pp-modal__dialog">
                    <div class="dex-pp-modal__header">
                        <div class="dex-pp-modal__header-main">
                            <h2 id="dex-pp-form-title" class="dex-pp-modal__title">
                                <?= esc_html__('Novi profil paketa', 'dexpress-woocommerce') ?>
                            </h2>
                            <p class="dex-pp-modal__subtitle">
                                <?= esc_html__('Samo je naziv obavezan. Ostalo je opciono.', 'dexpress-woocommerce') ?>
                            </p>
                        </div>
                        <button type="button" class="dex-pp-modal__close" id="dex-pp-modal-close"
                            aria-label="<?= esc_attr__('Zatvori', 'dexpress-woocommerce') ?>">✕</button>
                    </div>

                    <div class="dex-pp-modal__body">
                        <form id="dex-pp-form" autocomplete="off">
                            <input type="hidden" id="dex-pp-id" name="id" value="0" />
                            <div class="dex-pp-modal-stack">
                                <div class="dex-pp-modal-grid">
                                    <div class="dex-pp-modal-grid__col dex-pp-modal-grid__col--main">
                                        <section class="dex-pp-modal-section" aria-labelledby="dex-pp-sec-basic">
                                            <h3 id="dex-pp-sec-basic" class="dex-pp-modal-section__title">
                                                <?= esc_html__('Osnovni podaci', 'dexpress-woocommerce') ?>
                                            </h3>
                                            <div class="dex-pp-modal-section__inner">
                                                <div class="dex-pp-field">
                                                    <label for="dex-pp-name"><?= esc_html__('Naziv profila', 'dexpress-woocommerce') ?> <span class="required">*</span></label>
                                                    <input type="text" id="dex-pp-name" name="name" class="regular-text" required
                                                        placeholder="<?= esc_attr__('npr. Kutija M — Majice', 'dexpress-woocommerce') ?>" />
                                                </div>
                                                <div class="dex-pp-field">
                                                    <label for="dex-pp-desc"><?= esc_html__('Opis (opcionalno)', 'dexpress-woocommerce') ?></label>
                                                    <textarea id="dex-pp-desc" name="description" rows="2" class="large-text"
                                                        placeholder="<?= esc_attr__('npr. Kutija za majice', 'dexpress-woocommerce') ?>"></textarea>
                                                </div>
                                            </div>
                                        </section>
                                        <section class="dex-pp-modal-section" aria-labelledby="dex-pp-sec-content">
                                            <h3 id="dex-pp-sec-content" class="dex-pp-modal-section__title">
                                                <?= esc_html__('Tekst na nalepnici', 'dexpress-woocommerce') ?>
                                            </h3>
                                            <div class="dex-pp-modal-section__inner">
                                                <div class="dex-pp-field">
                                                    <label for="dex-pp-content"><?= esc_html__('Podrazumevani opis sadržaja', 'dexpress-woocommerce') ?></label>
                                                    <input type="text" id="dex-pp-content" name="default_content" class="regular-text"
                                                        maxlength="50"
                                                        placeholder="<?= esc_attr__('npr. Odeća', 'dexpress-woocommerce') ?>" />
                                                    <p class="dex-pp-field-hint"><?= esc_html__('Na nalepnici, do 50 znakova.', 'dexpress-woocommerce') ?></p>
                                                </div>
                                            </div>
                                        </section>
                                    </div>
                                    <div class="dex-pp-modal-grid__col dex-pp-modal-grid__col--size">
                                        <section class="dex-pp-modal-section dex-pp-modal-section--panel" aria-labelledby="dex-pp-sec-size">
                                            <div class="dex-pp-modal-section__inner">
                                                <h3 id="dex-pp-sec-size" class="dex-pp-modal-section__title dex-pp-modal-section__title--in-panel">
                                                    <?= esc_html__('Masa i dimenzije (cm)', 'dexpress-woocommerce') ?>
                                                </h3>
                                                <div class="dex-pp-panel-body">
                                                    <div class="dex-pp-field dex-pp-field--weight-row">
                                                        <label for="dex-pp-weight"><?= esc_html__('Masa prazne kutije (kg)', 'dexpress-woocommerce') ?></label>
                                                        <div class="dex-pp-weight-row">
                                                            <input type="number" id="dex-pp-weight" name="weight_kg" min="0" step="0.01"
                                                                class="small-text" placeholder="0.50" />
                                                            <span class="dex-pp-weight-g" id="dex-pp-weight-g" aria-live="polite"></span>
                                                        </div>
                                                    </div>
                                                    <div class="dex-pp-field dex-pp-field--dims-compact">
                                                        <span class="dex-pp-field-label-block"><?= esc_html__('Spoljašnje mere (cm)', 'dexpress-woocommerce') ?></span>
                                                        <div class="dex-pp-dims dex-pp-dims--grid3" role="group" aria-label="<?= esc_attr__('Dimenzije u centimetrima', 'dexpress-woocommerce') ?>">
                                                            <div class="dex-pp-dim-cell">
                                                                <label class="dex-pp-dim-label" for="dex-pp-dx"><?= esc_html__('Dužina', 'dexpress-woocommerce') ?></label>
                                                                <div class="dex-pp-dim-input-row">
                                                                    <input type="number" id="dex-pp-dx" name="dim_x" min="0" step="0.1"
                                                                        class="small-text" placeholder="30"
                                                                        title="<?= esc_attr__('Najduža strana kutije', 'dexpress-woocommerce') ?>" />
                                                                    <span class="dex-pp-dim-unit"><?= esc_html__('cm', 'dexpress-woocommerce') ?></span>
                                                                </div>
                                                            </div>
                                                            <div class="dex-pp-dim-cell">
                                                                <label class="dex-pp-dim-label" for="dex-pp-dy"><?= esc_html__('Širina', 'dexpress-woocommerce') ?></label>
                                                                <div class="dex-pp-dim-input-row">
                                                                    <input type="number" id="dex-pp-dy" name="dim_y" min="0" step="0.1"
                                                                        class="small-text" placeholder="20" />
                                                                    <span class="dex-pp-dim-unit"><?= esc_html__('cm', 'dexpress-woocommerce') ?></span>
                                                                </div>
                                                            </div>
                                                            <div class="dex-pp-dim-cell">
                                                                <label class="dex-pp-dim-label" for="dex-pp-dz"><?= esc_html__('Visina', 'dexpress-woocommerce') ?></label>
                                                                <div class="dex-pp-dim-input-row">
                                                                    <input type="number" id="dex-pp-dz" name="dim_z" min="0" step="0.1"
                                                                        class="small-text" placeholder="10"
                                                                        title="<?= esc_attr__('Od dna kutije do vrha', 'dexpress-woocommerce') ?>" />
                                                                    <span class="dex-pp-dim-unit"><?= esc_html__('cm', 'dexpress-woocommerce') ?></span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <p class="dex-pp-modal-footnote dex-pp-modal-footnote--inline">
                                                            <?= esc_html__('D = najduža strana · opciono — možete dopuniti pri slanju.', 'dexpress-woocommerce') ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </section>
                                    </div>
                                </div>
                                <p class="dex-pp-form-note">
                                    <?= esc_html__('Mere i masa = prazna kutija, ne roba unutra.', 'dexpress-woocommerce') ?>
                                </p>
                            </div>
                        </form>
                    </div><!-- .dex-pp-modal__body -->

                    <div class="dex-pp-modal__footer">
                        <button type="submit" form="dex-pp-form" class="button button-primary" id="dex-pp-save-btn">
                            <?= esc_html__('Sačuvaj profil', 'dexpress-woocommerce') ?>
                        </button>
                        <button type="button" class="button" id="dex-pp-cancel-btn">
                            <?= esc_html__('Otkaži', 'dexpress-woocommerce') ?>
                        </button>
                        <span id="dex-pp-msg" class="dex-pp-msg" aria-live="polite"></span>
                    </div>
                </div><!-- .dex-pp-modal__dialog -->
            </div><!-- #dex-pp-modal -->

            <div class="dex-pp-page__body dex-pp-layout">
                <main class="dex-pp-main" id="dex-pp-main">
                    <div id="dex-pp-table-wrap" class="dex-pp-main__content dex-card">
                        <div class="dex-card__body">
                            <?php $this->renderTable($profiles); ?>
                        </div>
                    </div>
                </main>

                <aside class="dex-pp-sidebar" aria-label="<?= esc_attr__('Uputstvo i ograničenja D Express', 'dexpress-woocommerce') ?>">
                    <div class="dex-card dex-pp-sidebar-rules">
                        <img
                            src="<?= esc_url(DEXPRESS_PLUGIN_URL . 'assets/images/Uputstvo_za_pakovanje.jpg') ?>"
                            alt="<?= esc_attr__('Uputstvo za pakovanje D Express', 'dexpress-woocommerce') ?>"
                            class="dex-pp-sidebar-banner" />
                        <div class="dex-pp-rules-tab-bar" role="tablist">
                            <button type="button" class="dex-pp-rules-tab-btn is-active"
                                data-tab="standard" role="tab"
                                aria-selected="true" aria-controls="dex-pp-tab-standard">
                                <?= esc_html__('Obična pošiljka', 'dexpress-woocommerce') ?>
                            </button>
                            <button type="button" class="dex-pp-rules-tab-btn"
                                data-tab="paketomat" role="tab"
                                aria-selected="false" aria-controls="dex-pp-tab-paketomat">
                                <?= esc_html__('Paketomat', 'dexpress-woocommerce') ?>
                                <span class="dex-pp-tab-badge"><?= esc_html__('loker', 'dexpress-woocommerce') ?></span>
                            </button>
                        </div>

                        <div class="dex-pp-rules-tab-panel" id="dex-pp-tab-standard" role="tabpanel">
                            <dl class="dex-pp-rules-list">
                                <dt><?= esc_html__('Maks. masa', 'dexpress-woocommerce') ?></dt>
                                <dd>30 kg / paket</dd>
                                <dt><?= esc_html__('Maks. dužina strane', 'dexpress-woocommerce') ?></dt>
                                <dd>200 cm</dd>
                                <dt><?= esc_html__('Maks. obim', 'dexpress-woocommerce') ?></dt>
                                <dd>2×(Visina+Širina)+Dužina ≤ 360 cm</dd>
                                <dt><?= esc_html__('Min. dimenzije', 'dexpress-woocommerce') ?></dt>
                                <dd>10 × 7 cm</dd>
                            </dl>
                            <p class="dex-pp-rules-note">
                                <?= esc_html__('Za pošiljke > 5 kg preporučuje se dvoslojna kartonska kutija.', 'dexpress-woocommerce') ?>
                            </p>
                            <p class="dex-pp-rules-note">
                                <?= esc_html__('Krhke pošiljke označiti i obložiti zaštitnim materijalom (min. 5 cm).', 'dexpress-woocommerce') ?>
                            </p>
                        </div><!-- #dex-pp-tab-standard -->

                        <div class="dex-pp-rules-tab-panel" id="dex-pp-tab-paketomat" role="tabpanel" hidden>
                            <dl class="dex-pp-rules-list">
                                <dt><?= esc_html__('Broj paketa', 'dexpress-woocommerce') ?></dt>
                                <dd><?= esc_html__('Tačno 1', 'dexpress-woocommerce') ?></dd>
                                <dt><?= esc_html__('Maks. masa', 'dexpress-woocommerce') ?></dt>
                                <dd>20 kg</dd>
                                <dt><?= esc_html__('Maks. dimenzije', 'dexpress-woocommerce') ?></dt>
                                <dd>Dužina 470 mm · Širina 440 mm · Visina 440 mm</dd>
                                <dt><?= esc_html__('Maks. otkup', 'dexpress-woocommerce') ?></dt>
                                <dd>200.000 RSD</dd>
                                <dt><?= esc_html__('Telefon primaoca', 'dexpress-woocommerce') ?></dt>
                                <dd><?= esc_html__('Mobilni (06x)', 'dexpress-woocommerce') ?></dd>
                                <dt><?= esc_html__('Povratna dokumentacija', 'dexpress-woocommerce') ?></dt>
                                <dd><?= esc_html__('Nije dozvoljeno', 'dexpress-woocommerce') ?></dd>
                            </dl>
                        </div><!-- #dex-pp-tab-paketomat -->
                    </div><!-- .dex-pp-sidebar-rules -->
                </aside>
            </div><!-- .dex-pp-page__body -->

        </div><!-- .wrap.dex-pp-page -->

        <script>
            (function() {
                // Live gram converter for mass field
                var weightInput = document.getElementById('dex-pp-weight');
                var weightGEl   = document.getElementById('dex-pp-weight-g');
                if (weightInput && weightGEl) {
                    weightInput.addEventListener('input', function() {
                        var kg = parseFloat(this.value) || 0;
                        weightGEl.textContent = kg > 0 ? '= ' + Math.round(kg * 1000) + ' g' : '';
                    });
                }

                // Sidebar tab switching
                var tabBtns   = document.querySelectorAll('.dex-pp-rules-tab-btn');
                var tabPanels = document.querySelectorAll('.dex-pp-rules-tab-panel');

                tabBtns.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        tabBtns.forEach(function(b) {
                            b.classList.remove('is-active');
                            b.setAttribute('aria-selected', 'false');
                        });
                        tabPanels.forEach(function(p) {
                            p.hidden = true;
                        });

                        btn.classList.add('is-active');
                        btn.setAttribute('aria-selected', 'true');
                        var panel = document.getElementById('dex-pp-tab-' + btn.dataset.tab);
                        if (panel) {
                            panel.hidden = false;
                        }
                    });
                });
            }());
        </script>
        <?php
    }

    /**
     * @param list<array<string, mixed>> $profiles
     */
    public function renderTable(array $profiles): void
    {
        $iconUrl = DEXPRESS_PLUGIN_URL . 'assets/images/package-box.svg';

        if (empty($profiles)) {
        ?>
            <div class="dex-pp-empty">
                <img src="<?= esc_url($iconUrl) ?>" class="dex-pp-empty-icon" alt="" />
                <h2 class="dex-pp-empty-title"><?= esc_html__('Još nema profila paketa', 'dexpress-woocommerce') ?></h2>
                <p class="dex-pp-empty-desc">
                    <?= esc_html__('Definišite dimenzije i težinu kutija koje koristite za pakovanje. Jednom sačuvan profil možete koristiti za brzo kreiranje pošiljki.', 'dexpress-woocommerce') ?>
                </p>
                <button type="button" class="button button-primary dex-pp-open-form">
                    <?= esc_html__('＋ Kreiraj prvi profil', 'dexpress-woocommerce') ?>
                </button>
            </div>
<?php
            return;
        }

        echo '<div class="dex-pp-grid">';

        foreach ($profiles as $profile) {
            $id        = (int) $profile['id'];
            $name      = (string) $profile['name'];
            $isDefault = (bool) $profile['is_default'];

            $dimParts = [];
            foreach (['dim_x', 'dim_y', 'dim_z'] as $k) {
                $val        = $profile[$k] !== null ? (int) $profile[$k] : null;
                $dimParts[] = $val !== null ? number_format($val / 10, 1) : '—';
            }
            $dims = implode(' × ', $dimParts) . ' cm';

            $weightG  = (int) $profile['weight_grams'];
            $weightKg = $weightG > 0 ? number_format($weightG / 1000, 3) . ' kg' : '—';
            $content  = (string) ($profile['default_content'] ?? '');

            $profileAttr = esc_attr((string) wp_json_encode([
                'id'              => $id,
                'name'            => $name,
                'description'     => (string) ($profile['description'] ?? ''),
                'weight_kg'       => $weightG > 0 ? number_format($weightG / 1000, 3, '.', '') : '',
                'dim_x'           => $profile['dim_x'] !== null ? number_format((int) $profile['dim_x'] / 10, 1, '.', '') : '',
                'dim_y'           => $profile['dim_y'] !== null ? number_format((int) $profile['dim_y'] / 10, 1, '.', '') : '',
                'dim_z'           => $profile['dim_z'] !== null ? number_format((int) $profile['dim_z'] / 10, 1, '.', '') : '',
                'default_content' => $content,
            ]));

            echo '<div class="dex-pp-card" data-profile="' . $profileAttr . '">';
            echo   '<div class="dex-pp-card-inner">';
            echo     '<div class="dex-pp-card-header">';
            echo       '<img src="' . esc_url($iconUrl) . '" class="dex-pp-card-icon" alt="" />';
            echo       '<div class="dex-pp-card-title">';
            echo         '<strong class="dex-pp-card-name">' . esc_html($name) . '</strong>';
            if ($isDefault) {
                echo ' <span class="dex-pp-badge dex-pp-badge--default">'
                    . esc_html__('Podrazumevani', 'dexpress-woocommerce')
                    . '</span>';
            }
            echo       '</div>';
            echo     '</div>';

            echo     '<dl class="dex-pp-card-meta">';
            echo       '<dt>' . esc_html__('Dimenzije', 'dexpress-woocommerce') . '</dt>';
            echo       '<dd>' . esc_html($dims) . '</dd>';
            echo       '<dt>' . esc_html__('Masa', 'dexpress-woocommerce') . '</dt>';
            echo       '<dd>' . esc_html($weightKg) . '</dd>';
            if ($content !== '') {
                echo   '<dt>' . esc_html__('Sadržaj', 'dexpress-woocommerce') . '</dt>';
                echo   '<dd>' . esc_html($content) . '</dd>';
            }
            echo     '</dl>';

            if (!empty($profile['description'])) {
                echo '<p class="dex-pp-card-desc">' . esc_html((string) $profile['description']) . '</p>';
            }

            echo   '</div>';

            echo   '<div class="dex-pp-card-actions">';
            echo     '<button type="button" class="button button-small dex-pp-edit-btn" data-id="' . $id . '">'
                . esc_html__('Izmeni', 'dexpress-woocommerce') . '</button>';
            if (!$isDefault) {
                echo '<button type="button" class="button button-small dex-pp-default-btn" data-id="' . $id . '">'
                    . esc_html__('Podrazumevani', 'dexpress-woocommerce') . '</button>';
            }
            echo     '<button type="button" class="button button-small button-link-delete dex-pp-delete-btn"'
                . ' data-id="' . $id . '" data-name="' . esc_attr($name) . '">'
                . esc_html__('Obriši', 'dexpress-woocommerce') . '</button>';
            echo   '</div>';

            echo '</div>';
        }

        echo '<button type="button" class="dex-pp-card dex-pp-card--add dex-pp-open-form">';
        echo   '<span class="dex-pp-card-add-plus">＋</span>';
        echo   '<span class="dex-pp-card-add-label">' . esc_html__('Dodaj profil', 'dexpress-woocommerce') . '</span>';
        echo '</button>';

        echo '</div>';
    }
}
