<?php

/**
 * Admin settings tab: API podešavanja
 *
 * Available variables:
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 * @var bool $hasPassword
 * @var array<string, mixed>|null $shipment_code_range_status read-only stats (API tab; built in SettingsPage)
 */

defined('ABSPATH') || exit;

use S7codedesign\DExpress\Application\Shipment\ShipmentCodeAllocator;

$range_status = is_array($shipment_code_range_status ?? null) ? $shipment_code_range_status : ['valid' => false];
$modeRaw    = $options->getString('api.mode', '');
$currentEnv = $modeRaw !== '' ? $modeRaw : ($options->getString('api.environment', 'test') === 'production' ? 'live' : 'dry_run');
$prefixInput  = ShipmentCodeAllocator::normalizeShipmentPrefix($options->getString('shipment.prefix', ''));
if (strlen($prefixInput) !== 2) {
    $prefixInput = '';
}
$rsRaw = $options->get('shipment.range_start');
$reRaw = $options->get('shipment.range_end');
$rangeStartDisplay = ($rsRaw !== null && $rsRaw !== '' && (int) $rsRaw > 0) ? (string) (int) $rsRaw : '';
$rangeEndDisplay   = ($reRaw !== null && $reRaw !== '' && (int) $reRaw > 0) ? (string) (int) $reRaw : '';
?>

<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="api">

    <div class="dex-api-grid">

        <?php /* ─── CARD 1: API PRISTUP ─── */ ?>
        <div class="dex-card">
            <div class="dex-card__header">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                <h2 class="dex-card__title"><?php esc_html_e('API pristup', 'dexpress-woocommerce'); ?></h2>
            </div>
            <div class="dex-card__body">
                <div class="dex-settings-fields">

                    <div class="dex-field">
                        <label class="dex-field__label" for="api_username">
                            <?php esc_html_e('Korisničko ime', 'dexpress-woocommerce'); ?>
                            <span class="dex-field__required">*</span>
                        </label>
                        <input type="text"
                            id="api_username"
                            name="api_username"
                            value="<?php echo esc_attr($options->getString('api.username')); ?>"
                            autocomplete="off">
                        <p class="dex-field__hint"><?php esc_html_e('API korisničko ime koje vam je dodelio D Express.', 'dexpress-woocommerce'); ?></p>
                    </div>

                    <div class="dex-field">
                        <label class="dex-field__label" for="api_password">
                            <?php esc_html_e('Lozinka', 'dexpress-woocommerce'); ?>
                            <?php if (!$hasPassword) : ?>
                                <span class="dex-field__required">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($hasPassword) : ?>
                            <div class="dex-pw-saved" id="dex-pw-saved-indicator">
                                <span class="dex-pw-saved__dots" aria-hidden="true">••••••••</span>
                                <span class="dex-pw-saved__label">
                                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                    <?php esc_html_e('Sačuvano', 'dexpress-woocommerce'); ?>
                                </span>
                                <button type="button" id="dex-pw-change" class="dex-btn dex-btn--secondary dex-btn--sm">
                                    <?php esc_html_e('Promeni lozinku', 'dexpress-woocommerce'); ?>
                                </button>
                            </div>
                        <?php endif; ?>

                        <div class="dex-pw-wrap<?php echo $hasPassword ? ' dex-hidden' : ''; ?>" id="dex-pw-input-wrap">
                            <input type="password"
                                id="api_password"
                                name="api_password"
                                value=""
                                autocomplete="new-password">
                            <button type="button"
                                class="dex-btn dex-btn--secondary dex-btn--sm dex-toggle-password"
                                data-target="api_password"
                                aria-pressed="false"
                                title="<?php esc_attr_e('Prikaži / sakrij lozinku', 'dexpress-woocommerce'); ?>">
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php esc_html_e('Prikaži lozinku', 'dexpress-woocommerce'); ?></span>
                            </button>
                            <?php if ($hasPassword) : ?>
                                <button type="button" id="dex-pw-cancel" class="dex-btn dex-btn--ghost dex-btn--sm">
                                    <?php esc_html_e('Otkaži', 'dexpress-woocommerce'); ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <p class="dex-field__hint<?php echo $hasPassword ? ' dex-hidden' : ''; ?>" id="dex-pw-hint">
                            <?php if ($hasPassword) : ?>
                                <?php esc_html_e('Unesite novu lozinku. Ostavite prazno da zadržite trenutnu.', 'dexpress-woocommerce'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Unesite API lozinku koju vam je dodelio D Express.', 'dexpress-woocommerce'); ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="dex-field">
                        <label class="dex-field__label" for="api_client_id">
                            <?php esc_html_e('Client ID (CClientID)', 'dexpress-woocommerce'); ?>
                        </label>
                        <input type="text"
                            id="api_client_id"
                            name="api_client_id"
                            value="<?php echo esc_attr($options->getString('api.client_id')); ?>">
                        <p class="dex-field__hint"><?php esc_html_e('Vaš D Express klijentski ID (npr. UKXXXXX). Dodeljuje ga D Express.', 'dexpress-woocommerce'); ?></p>
                    </div>

                </div>
            </div>
            <div class="dex-card__footer">
                <button type="button" id="dex-test-connection" class="dex-btn dex-btn--secondary">
                    <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                    <?php esc_html_e('Testiraj konekciju', 'dexpress-woocommerce'); ?>
                </button>
                <span id="dex-test-connection-result" class="dex-inline-result" aria-live="polite"></span>
            </div>
        </div>

        <?php /* ─── CARD 2: RASPON KODOVA POŠILJKI ─── */ ?>
        <div class="dex-card dex-card--highlight">
            <div class="dex-card__header">
                <span class="dashicons dashicons-tag" aria-hidden="true"></span>
                <h2 class="dex-card__title"><?php esc_html_e('Raspon kodova pošiljki', 'dexpress-woocommerce'); ?></h2>
            </div>
            <div class="dex-card__body">
                <p class="dex-settings-section__lead">
                    <?php esc_html_e('D Express vam dodeljuje dvoslovni prefiks i numerički opseg — svaki paket dobija jedinstveni kod iz tog opsega. Kad se opseg popuni, pozovite D Express da vam prošire „Do" na veći broj.', 'dexpress-woocommerce'); ?>
                </p>

                <div class="dex-settings-fields">

                    <div class="dex-field">
                        <label class="dex-field__label" for="shipment_prefix">
                            <?php esc_html_e('Prefiks pošiljke', 'dexpress-woocommerce'); ?>
                            <span class="dex-field__required">*</span>
                        </label>
                        <input type="text"
                            id="shipment_prefix"
                            name="shipment_prefix"
                            class="dex-input--code"
                            maxlength="2"
                            inputmode="text"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            value="<?php echo esc_attr($prefixInput); ?>">
                        <p class="dex-field__hint"><?php esc_html_e('2 velika slova koja vam je dodelio D Express (npr. TT za test, ili ZS, AB… za produkciju).', 'dexpress-woocommerce'); ?></p>
                    </div>

                    <div class="dex-field">
                        <span class="dex-field__label"><?php esc_html_e('Numerički opseg', 'dexpress-woocommerce'); ?></span>
                        <div class="dex-range-row">
                            <div class="dex-range-item">
                                <label class="dex-range-item__label" for="shipment_range_start"><?php esc_html_e('Od', 'dexpress-woocommerce'); ?></label>
                                <input type="number"
                                    id="shipment_range_start"
                                    name="shipment_range_start"
                                    class="dex-input--number"
                                    min="1"
                                    value="<?php echo esc_attr($rangeStartDisplay); ?>">
                            </div>
                            <span class="dex-range-row__sep">—</span>
                            <div class="dex-range-item">
                                <label class="dex-range-item__label" for="shipment_range_end"><?php esc_html_e('Do', 'dexpress-woocommerce'); ?></label>
                                <input type="number"
                                    id="shipment_range_end"
                                    name="shipment_range_end"
                                    class="dex-input--number"
                                    min="1"
                                    value="<?php echo esc_attr($rangeEndDisplay); ?>">
                            </div>
                        </div>
                        <p class="dex-field__hint"><?php esc_html_e('Opseg koji vam je dodelio D Express. Primer: Od 7520 Do 8020 = 501 kod. Kad ponestane, D Express povećava „Do".', 'dexpress-woocommerce'); ?></p>
                    </div>

                </div>

                <?php /* Live preview — updates via JS as user types */ ?>
                <div id="dex-code-preview" class="dex-code-preview<?php echo ($prefixInput !== '' && $rangeStartDisplay !== '' && $rangeEndDisplay !== '') ? '' : ' is-hidden'; ?>">
                    <span class="dex-code-preview__label"><?php esc_html_e('Primer koda:', 'dexpress-woocommerce'); ?></span>
                    <code id="dex-code-preview-first" class="dex-code-preview__code"></code>
                    <span class="dex-code-preview__sep">→</span>
                    <code id="dex-code-preview-last" class="dex-code-preview__code"></code>
                    <span class="dex-code-preview__total" id="dex-code-preview-total"></span>
                </div>

                <script>
                (function () {
                    function pad(n) {
                        var s = String(Math.max(0, n));
                        while (s.length < 10) { s = '0' + s; }
                        return s;
                    }
                    function update() {
                        var pfx   = (document.getElementById('shipment_prefix').value || '').toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
                        var start = parseInt(document.getElementById('shipment_range_start').value, 10);
                        var end   = parseInt(document.getElementById('shipment_range_end').value, 10);
                        var wrap  = document.getElementById('dex-code-preview');
                        if (!wrap) { return; }
                        if (pfx.length !== 2 || isNaN(start) || isNaN(end) || start < 1 || end < start) {
                            wrap.classList.add('is-hidden'); return;
                        }
                        wrap.classList.remove('is-hidden');
                        document.getElementById('dex-code-preview-first').textContent = pfx + pad(start);
                        document.getElementById('dex-code-preview-last').textContent  = pfx + pad(end);
                        document.getElementById('dex-code-preview-total').textContent = '(' + (end - start + 1) + ' <?php echo esc_js(__('kodova', 'dexpress-woocommerce')); ?>)';
                    }
                    ['shipment_prefix', 'shipment_range_start', 'shipment_range_end'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) { el.addEventListener('input', update); }
                    });
                    update();
                }());
                </script>

                <?php if (!empty($range_status['valid'])) : ?>
                    <?php
                    $tier          = (string) ($range_status['tier'] ?? 'normal');
                    $range_start   = (int) ($range_status['range_start'] ?? 0);
                    $range_end     = (int) ($range_status['range_end'] ?? 0);
                    $total         = (int) ($range_status['total'] ?? 0);
                    $used          = (int) ($range_status['used'] ?? 0);
                    $remaining     = (int) ($range_status['remaining'] ?? 0);
                    $usage_percent = (float) ($range_status['usage_percent'] ?? 0.0);
                    $prefix_disp   = (string) ($range_status['prefix'] ?? '');
                    $next_free     = isset($range_status['next_free']) ? (int) $range_status['next_free'] : null;

                    $is_exhausted    = $tier === 'exhausted';
                    $show_usage_warn = !$is_exhausted && $usage_percent >= 80.0;
                    $meter_w         = (string) min(100.0, max(0.0, $usage_percent));
                    $meter_class     = $is_exhausted ? ' is-exhausted' : ($show_usage_warn ? ' is-warning' : '');

                    $next_code_display = $next_free !== null
                        ? ($prefix_disp . str_pad((string) $next_free, 10, '0', STR_PAD_LEFT))
                        : null;

                    $mailto_subject = rawurlencode(__('Zahtev za proširenje opsega kodova pošiljki', 'dexpress-woocommerce'));
                    $mailto_body    = rawurlencode(sprintf(
                        /* translators: 1: prefix, 2: range start, 3: range end, 4: usage percent */
                        __("Zdravo,\n\nPotreban nam je prošireni numerički opseg za D Express kodove pošiljki.\nTrenutni prefiks: %1\$s\nPodešen opseg: %2\$s – %3\$s\nIskorišćenost: %4\$s%%\n\nHvala.", 'dexpress-woocommerce'),
                        $prefix_disp, (string) $range_start, (string) $range_end, (string) round($usage_percent, 1)
                    ));
                    $mailto_href = 'mailto:support@dexpress.rs?subject=' . $mailto_subject . '&body=' . $mailto_body;
                    ?>
                    <div class="dex-shipment-range-monitor">

                        <?php if ($next_code_display !== null) : ?>
                        <div class="dex-next-code-row">
                            <span class="dex-next-code-row__label"><?php esc_html_e('Sledeći slobodni kod:', 'dexpress-woocommerce'); ?></span>
                            <code class="dex-next-code-row__code"><?php echo esc_html($next_code_display); ?></code>
                        </div>
                        <?php endif; ?>

                        <p class="dex-usage-count">
                            <?php
                            printf(
                                /* translators: 1: used, 2: total */
                                esc_html__('%1$s / %2$s kodova iskorišćeno', 'dexpress-woocommerce'),
                                '<strong>' . esc_html((string) $used) . '</strong>',
                                esc_html((string) $total)
                            );
                            ?>
                            <span class="description">(<?php echo esc_html(number_format_i18n($usage_percent, 1)); ?>%)</span>
                        </p>

                        <div class="dex-usage-meter<?php echo esc_attr($meter_class); ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($meter_w); ?>">
                            <div class="dex-usage-meter-fill" style="width: <?php echo esc_attr($meter_w); ?>%;"></div>
                        </div>

                        <p class="dex-usage-remaining">
                            <strong><?php esc_html_e('Slobodnih kodova:', 'dexpress-woocommerce'); ?></strong>
                            <?php echo esc_html((string) $remaining); ?>
                            <span class="description">(<?php echo esc_html($prefix_disp . ' ' . $range_start . '–' . $range_end); ?>)</span>
                        </p>

                        <?php if ($is_exhausted) : ?>
                            <div class="dex-notice dex-notice--error">
                                <div class="dex-notice__content">
                                    <p class="dex-notice__title"><?php esc_html_e('Opseg kodova je iscrpljen', 'dexpress-woocommerce'); ?></p>
                                    <p class="dex-notice__body"><?php esc_html_e('Ne možete kreirati nove pošiljke dok ne proširite opseg. Pozovite D Express i tražite da povećaju vrednost „Do". Kada dobijete novi broj, upišite ga ovde i sačuvajte.', 'dexpress-woocommerce'); ?></p>
                                </div>
                            </div>
                        <?php elseif ($show_usage_warn) : ?>
                            <div class="dex-notice dex-notice--warning">
                                <div class="dex-notice__content">
                                    <p class="dex-notice__title"><?php esc_html_e('Ponestaje kodova', 'dexpress-woocommerce'); ?></p>
                                    <p class="dex-notice__body"><?php esc_html_e('Ostalo vam je manje od 20% opsega. Kontaktirajte D Express na vreme i tražite proširenje „Do" vrednosti pre nego što ostanete bez kodova.', 'dexpress-woocommerce'); ?></p>
                                </div>
                            </div>
                        <?php else : ?>
                            <p class="description">
                                <?php esc_html_e('Iskorišćenost je u bezbednom opsegu.', 'dexpress-woocommerce'); ?>
                            </p>
                        <?php endif; ?>

                        <p>
                            <a class="dex-btn dex-btn--secondary dex-btn--sm" href="<?php echo esc_url($mailto_href); ?>">
                                <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                                <?php esc_html_e('Zatražite proširenje opsega', 'dexpress-woocommerce'); ?>
                            </a>
                        </p>
                    </div>
                <?php else : ?>
                    <div class="dex-notice dex-notice--info">
                        <div class="dex-notice__content">
                            <p class="dex-notice__body"><?php esc_html_e('Unesite ispravan prefiks i opseg pa sačuvajte da biste videli iskorišćenost i sledeći slobodni kod.', 'dexpress-woocommerce'); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php /* ─── CARD 3: MOD RADA ─── */ ?>

        <div class="dex-card dex-env-card" id="dex-env-settings-card">
            <div class="dex-card__header">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <h2 class="dex-card__title"><?php esc_html_e('Mod rada', 'dexpress-woocommerce'); ?></h2>
            </div>
            <div class="dex-card__body">
                <p class="dex-settings-section__lead dex-env-card__intro">
                    <?php esc_html_e('Izaberite kako plugin komunicira sa D-Expressom.', 'dexpress-woocommerce'); ?>
                </p>

                <input type="hidden" name="api_mode" id="api_mode_hidden" value="<?php echo esc_attr($currentEnv); ?>">

                <div class="dex-env-toggle" role="group" aria-label="<?php esc_attr_e('Mod rada', 'dexpress-woocommerce'); ?>">
                    <button type="button"
                        class="dex-env-option<?php echo $currentEnv === 'dry_run' ? ' is-active' : ''; ?>"
                        data-value="dry_run"
                        aria-pressed="<?php echo $currentEnv === 'dry_run' ? 'true' : 'false'; ?>">
                        <span class="dex-env-option__inner">
                            <span class="dex-env-option__label"><?php esc_html_e('Probni rad', 'dexpress-woocommerce'); ?></span>
                        </span>
                    </button>
                    <button type="button"
                        class="dex-env-option<?php echo $currentEnv === 'live' ? ' is-active' : ''; ?>"
                        data-value="live"
                        aria-pressed="<?php echo $currentEnv === 'live' ? 'true' : 'false'; ?>">
                        <span class="dex-env-option__inner">
                            <span class="dex-env-option__label"><?php esc_html_e('Produkcija', 'dexpress-woocommerce'); ?></span>
                        </span>
                    </button>
                </div>

                <div id="dex-env-status" class="dex-env-status <?php echo $currentEnv === 'live' ? 'is-production' : 'is-dry-run'; ?>">
                    <?php if ($currentEnv === 'live') : ?>
                        <span class="dex-env-status__icon" aria-hidden="true">✓</span>
                        <span class="dex-env-status__text"><?php esc_html_e('Produkcija — pošiljke se šalju D-Expressu i troše vaše kodove. Koristite kada ste spremni za rad sa kupcima.', 'dexpress-woocommerce'); ?></span>
                    <?php else : ?>
                        <span class="dex-env-status__icon" aria-hidden="true">ℹ</span>
                        <span class="dex-env-status__text"><?php esc_html_e('Probni rad — pošiljke se snimaju u sistem, ali se ne šalju D-Expressu i ne troše vaše kodove. Koristite za testiranje i podešavanje.', 'dexpress-woocommerce'); ?></span>
                    <?php endif; ?>
                </div>

                <script>
                    (function() {
                        var root = document.getElementById('dex-env-settings-card');
                        if (!root) {
                            return;
                        }
                        var msgDryRun = <?php echo wp_json_encode('<span class="dex-env-status__icon" aria-hidden="true">ℹ</span><span class="dex-env-status__text">' . esc_html__('Probni rad — pošiljke se snimaju u sistem, ali se ne šalju D-Expressu i ne troše vaše kodove. Koristite za testiranje i podešavanje.', 'dexpress-woocommerce') . '</span>'); ?>;
                        var msgLive   = <?php echo wp_json_encode('<span class="dex-env-status__icon" aria-hidden="true">✓</span><span class="dex-env-status__text">' . esc_html__('Produkcija — pošiljke se šalju D-Expressu i troše vaše kodove. Koristite kada ste spremni za rad sa kupcima.', 'dexpress-woocommerce') . '</span>'); ?>;

                        root.querySelectorAll('.dex-env-option').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var val = this.dataset.value;
                                root.querySelectorAll('.dex-env-option').forEach(function(b) {
                                    b.classList.remove('is-active');
                                    b.setAttribute('aria-pressed', 'false');
                                });
                                this.classList.add('is-active');
                                this.setAttribute('aria-pressed', 'true');
                                document.getElementById('api_mode_hidden').value = val;
                                var status = document.getElementById('dex-env-status');
                                if (val === 'live') {
                                    status.className = 'dex-env-status is-production';
                                    status.innerHTML = msgLive;
                                } else {
                                    status.className = 'dex-env-status is-dry-run';
                                    status.innerHTML = msgDryRun;
                                }
                            });
                        });
                    }());
                </script>
            </div>
        </div>
        <?php /* ─── CARD 4: PODRAZUMEVANE VREDNOSTI POŠILJKE ─── */ ?>
        <div class="dex-card">
            <div class="dex-card__header">
                <span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
                <h2 class="dex-card__title"><?php esc_html_e('Podrazumevane vrednosti pošiljke', 'dexpress-woocommerce'); ?></h2>
            </div>
            <div class="dex-card__body">
                <p class="dex-settings-section__lead"><?php esc_html_e(
                                                            'Podrazumevane vrednosti koje se koriste u čarobnjaku za slanje porudžbine. Svaka porudžbina ih može po potrebi zameniti.',
                                                            'dexpress-woocommerce'
                                                        ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="default_delivery_type"><?php esc_html_e('Tip dostave', 'dexpress-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="default_delivery_type" name="default_delivery_type">
                                <?php
                                $savedDl = $options->getString('shipment.default_delivery_type');
                                $savedDl = $savedDl !== '' ? $savedDl : (string) \S7codedesign\DExpress\Domain\Shipment\DeliveryType::Regular->value;
                                foreach (\S7codedesign\DExpress\Domain\Shipment\DeliveryType::cases() as $type) :
                                ?>
                                    <option value="<?php echo esc_attr((string) $type->value); ?>" <?php selected($savedDl, (string) $type->value); ?>>
                                        <?php echo esc_html($type->label()); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Standardna dostava — sledeći radni dan. Hitno — isti dan (samo određena područja, može biti skuplje). Preporučujemo Standardnu dostavu za sve normalne narudžbine.', 'dexpress-woocommerce'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_payment_type"><?php esc_html_e('Način plaćanja usluge kurira', 'dexpress-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="default_payment_type" name="default_payment_type">
                                <?php
                                $savedPay = $options->getString('shipment.default_payment_type');
                                $savedPay = $savedPay !== '' ? $savedPay : (string) \S7codedesign\DExpress\Domain\Shipment\PaymentType::Invoice->value;
                                foreach (\S7codedesign\DExpress\Domain\Shipment\PaymentType::cases() as $type) :
                                ?>
                                    <option value="<?php echo esc_attr((string) $type->value); ?>" <?php selected($savedPay, (string) $type->value); ?>>
                                        <?php echo esc_html($type->label()); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Ko plaća troškove dostave D-Expressu. Faktura — D-Express vam šalje fakturu mesečno (uobičajeno za poslovne korisnike). Gotovina — kurir naplaćuje pri preuzimanju paketa. Gotovo uvek koristite Faktura.', 'dexpress-woocommerce'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_return_doc"><?php esc_html_e('Povraćaj dokumenta', 'dexpress-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="default_return_doc" name="default_return_doc">
                                <?php
                                $savedRet = $options->getString('shipment.default_return_doc');
                                $savedRet = $savedRet !== '' ? $savedRet : (string) \S7codedesign\DExpress\Domain\Shipment\ReturnDoc::None->value;
                                foreach (\S7codedesign\DExpress\Domain\Shipment\ReturnDoc::cases() as $doc) :
                                ?>
                                    <option value="<?php echo esc_attr((string) $doc->value); ?>" <?php selected($savedRet, (string) $doc->value); ?>>
                                        <?php echo esc_html($doc->label()); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Bez povraćaja — ništa se ne vraća (standard za webshop). Povraćaj dokumenata — kurir donosi potpisane dokumente nazad (npr. ugovori, fakture). POD (Proof of Delivery) — zvanična potvrda o isporuci sa potpisom primaoca. Za online prodaju gotovo uvek koristite Bez povraćaja.', 'dexpress-woocommerce'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Način predaje paketa', 'dexpress-woocommerce'); ?>
                        </th>
                        <td>
                            <label for="default_self_drop_off">
                                <input type="checkbox"
                                       id="default_self_drop_off"
                                       name="default_self_drop_off"
                                       value="1"
                                       <?php checked($options->getBool('shipment.default_self_drop_off')); ?>>
                                <?php esc_html_e('Sam donosim pakete u D-Express (Self Drop-off)', 'dexpress-woocommerce'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Označite ako sami donosite pakete u D-Express paket shop ili depo — kurir neće dolaziti po preuzimanje. Ostavite isključeno ako želite da kurir dolazi po pakete na vašu adresu.', 'dexpress-woocommerce'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div><?php /* ─── end .dex-api-grid ─── */ ?>

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>