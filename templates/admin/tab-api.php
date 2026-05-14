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
$currentEnv   = $options->getString('api.environment', 'test');
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
                    <?php esc_html_e('Dvoslovni prefiks i numerički opseg koje vam je dodelio D Express (npr. TT sa 1–99 za test). Ispod pratite iskorišćenost.', 'dexpress-woocommerce'); ?>
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
                        <p class="dex-field__hint"><?php esc_html_e('2 velika slova koja vam je dodelio D Express (npr. TT za test).', 'dexpress-woocommerce'); ?></p>
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
                        <p class="dex-field__hint"><?php esc_html_e('Numerički opseg koji vam je dodelio D Express. Za testno: 1–99.', 'dexpress-woocommerce'); ?></p>
                    </div>

                </div>

                <?php if (!empty($range_status['valid'])) : ?>
                    <?php
                    $tier          = (string) ($range_status['tier'] ?? 'normal');
                    $range_start   = (int) ($range_status['range_start'] ?? 0);
                    $range_end     = (int) ($range_status['range_end'] ?? 0);
                    $total         = (int) ($range_status['total'] ?? 0);
                    $used          = (int) ($range_status['used'] ?? 0);
                    $usage_percent = (float) ($range_status['usage_percent'] ?? 0.0);
                    $prefix_disp   = (string) ($range_status['prefix'] ?? '');

                    $used_display      = min($used, $total);
                    $remaining_display = max(0, $total - $used_display);
                    $is_exhausted      = $tier === 'exhausted';
                    $show_usage_warn   = !$is_exhausted && $usage_percent >= 80.0;

                    $mailto_subject = rawurlencode(__('Zahtev za proširenje opsega kodova pošiljki', 'dexpress-woocommerce'));
                    $mailto_body    = rawurlencode(
                        sprintf(
                            /* translators: 1: prefix, 2: range start, 3: range end, 4: usage percent */
                            __(
                                "Zdravo,\n\nPotreban nam je prošireni numerički opseg za D Express kodove pošiljki.\n" .
                                    "Trenutni prefiks: %1\$s\nPodešen opseg: %2\$s – %3\$s\nPribližna iskorišćenost: %4\$s%%\n\nHvala.",
                                'dexpress-woocommerce'
                            ),
                            $prefix_disp,
                            (string) $range_start,
                            (string) $range_end,
                            (string) round($usage_percent, 1)
                        ),
                    );
                    $mailto_href = 'mailto:support@dexpress.rs?subject=' . $mailto_subject . '&body=' . $mailto_body;
                    $meter_w     = (string) min(100.0, max(0.0, $usage_percent));
                    $meter_class = $is_exhausted ? ' is-exhausted' : ($show_usage_warn ? ' is-warning' : '');
                    ?>
                    <div class="dex-shipment-range-monitor">
                        <p class="dex-usage-count">
                            <?php
                            printf(
                                /* translators: 1: used count, 2: total capacity */
                                esc_html__('%1$s / %2$s iskorišćeno', 'dexpress-woocommerce'),
                                esc_html((string) $used_display),
                                esc_html((string) $total)
                            );
                            ?>
                            <span class="description">
                                (<?php echo esc_html(number_format_i18n($usage_percent, 1)); ?>%)
                            </span>
                        </p>

                        <div class="dex-usage-meter<?php echo esc_attr($meter_class); ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($meter_w); ?>">
                            <div class="dex-usage-meter-fill" style="width: <?php echo esc_attr($meter_w); ?>%;"></div>
                        </div>

                        <p class="dex-usage-remaining">
                            <strong><?php esc_html_e('Preostali kodovi', 'dexpress-woocommerce'); ?>:</strong>
                            <?php echo esc_html((string) $remaining_display); ?>
                        </p>

                        <p class="description">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: prefix letters, 2: range start, 3: range end */
                                    __('Aktivni prefiks %1$s · numerički opseg %2$s–%3$s', 'dexpress-woocommerce'),
                                    $prefix_disp,
                                    (string) $range_start,
                                    (string) $range_end
                                )
                            );
                            ?>
                        </p>

                        <?php if ($is_exhausted) : ?>
                            <div class="dex-notice dex-notice--error">
                                <div class="dex-notice__content">
                                    <p class="dex-notice__title"><?php esc_html_e('Opseg kodova pošiljki je iscrpljen', 'dexpress-woocommerce'); ?></p>
                                    <p class="dex-notice__body"><?php esc_html_e('Proširite opseg u dogovoru sa D Express ili, kada vam bude dodeljen novi opseg, ažurirajte vrednost „Do" u podešavanjima.', 'dexpress-woocommerce'); ?></p>
                                </div>
                            </div>
                        <?php elseif ($show_usage_warn) : ?>
                            <div class="dex-notice dex-notice--warning">
                                <div class="dex-notice__content">
                                    <p class="dex-notice__title"><?php esc_html_e('Ponestaje vam kodova pošiljki', 'dexpress-woocommerce'); ?></p>
                                    <p class="dex-notice__body"><?php esc_html_e('Zatražite proširenje opsega kod D Express pre potpunog iscrpljenja.', 'dexpress-woocommerce'); ?></p>
                                </div>
                            </div>
                        <?php else : ?>
                            <p class="description">
                                <?php esc_html_e('Iskorišćenost je u bezbednom opsegu. Uvek možete povećati „Do" u dogovoru sa D Express ako očekujete rast.', 'dexpress-woocommerce'); ?>
                            </p>
                        <?php endif; ?>

                        <p>
                            <a class="dex-btn dex-btn--secondary dex-btn--sm"
                                href="<?php echo esc_url($mailto_href); ?>">
                                <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                                <?php esc_html_e('Zatražite dodatne kodove', 'dexpress-woocommerce'); ?>
                            </a>
                        </p>
                    </div>
                <?php else : ?>
                    <div class="dex-notice dex-notice--info">
                        <div class="dex-notice__content">
                            <p class="dex-notice__body"><?php esc_html_e('Sačuvajte ispravan dvoslovni prefiks i opseg (Od ≤ Do) da biste videli iskorišćenost i preostale kodove.', 'dexpress-woocommerce'); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php /* ─── CARD 3: OKRUŽENJE ─── */ ?>
        
        <div class="dex-card dex-env-card" id="dex-env-settings-card">
            <div class="dex-card__header">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <h2 class="dex-card__title"><?php esc_html_e('Okruženje', 'dexpress-woocommerce'); ?></h2>
            </div>
            <div class="dex-card__body">
                <p class="dex-settings-section__lead dex-env-card__intro"><?php esc_html_e('Test ili produkcija — u testu se pošiljke ne šalju D Express-u.', 'dexpress-woocommerce'); ?></p>

                <input type="hidden" name="api_environment" id="api_environment_hidden" value="<?php echo esc_attr($currentEnv); ?>">

                <div class="dex-env-toggle" role="group" aria-label="<?php esc_attr_e('Okruženje', 'dexpress-woocommerce'); ?>">
                    <button type="button"
                        class="dex-env-option<?php echo $currentEnv === 'test' ? ' is-active' : ''; ?>"
                        data-value="test"
                        aria-pressed="<?php echo $currentEnv === 'test' ? 'true' : 'false'; ?>">
                        <span class="dex-env-option__inner">
                            <span class="dex-env-option__label"><?php esc_html_e('TEST', 'dexpress-woocommerce'); ?></span>
                        </span>
                    </button>
                    <button type="button"
                        class="dex-env-option<?php echo $currentEnv === 'production' ? ' is-active' : ''; ?>"
                        data-value="production"
                        aria-pressed="<?php echo $currentEnv === 'production' ? 'true' : 'false'; ?>">
                        <span class="dex-env-option__inner">
                            <span class="dex-env-option__label"><?php esc_html_e('LIVE', 'dexpress-woocommerce'); ?></span>
                        </span>
                    </button>
                </div>

                <div id="dex-env-status" class="dex-env-status <?php echo $currentEnv === 'production' ? 'is-production' : 'is-test'; ?>">
                    <?php if ($currentEnv === 'production') : ?>
                        <span class="dex-env-status__icon" aria-hidden="true">✓</span>
                        <span class="dex-env-status__text"><?php esc_html_e('Produkcijsko okruženje — pošiljke se šalju D Express-u.', 'dexpress-woocommerce'); ?></span>
                    <?php else : ?>
                        <span class="dex-env-status__icon" aria-hidden="true">ℹ</span>
                        <span class="dex-env-status__text"><?php esc_html_e('Testno okruženje — isti URL-ovi, test kredencijali, bez stvarnih pošiljki.', 'dexpress-woocommerce'); ?></span>
                    <?php endif; ?>
                </div>

                <script>
                    (function() {
                        var root = document.getElementById('dex-env-settings-card');
                        if (!root) {
                            return;
                        }
                        var msgTest = <?php echo wp_json_encode('<span class="dex-env-status__icon" aria-hidden="true">ℹ</span><span class="dex-env-status__text">' . esc_html__('Testno okruženje — isti URL-ovi, test kredencijali, bez stvarnih pošiljki.', 'dexpress-woocommerce') . '</span>'); ?>;
                        var msgProd = <?php echo wp_json_encode('<span class="dex-env-status__icon" aria-hidden="true">✓</span><span class="dex-env-status__text">' . esc_html__('Produkcijsko okruženje — pošiljke se šalju D Express-u.', 'dexpress-woocommerce') . '</span>'); ?>;

                        root.querySelectorAll('.dex-env-option').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var val = this.dataset.value;
                                root.querySelectorAll('.dex-env-option').forEach(function(b) {
                                    b.classList.remove('is-active');
                                    b.setAttribute('aria-pressed', 'false');
                                });
                                this.classList.add('is-active');
                                this.setAttribute('aria-pressed', 'true');
                                document.getElementById('api_environment_hidden').value = val;
                                var status = document.getElementById('dex-env-status');
                                if (val === 'production') {
                                    status.className = 'dex-env-status is-production';
                                    status.innerHTML = msgProd;
                                } else {
                                    status.className = 'dex-env-status is-test';
                                    status.innerHTML = msgTest;
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
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_payment_type"><?php esc_html_e('Način plaćanja kurira', 'dexpress-woocommerce'); ?></label>
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
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div><?php /* ─── end .dex-api-grid ─── */ ?>

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>