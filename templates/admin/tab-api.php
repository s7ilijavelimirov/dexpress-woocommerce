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

$range_status = is_array($shipment_code_range_status ?? null) ? $shipment_code_range_status : ['valid' => false];
?>
<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="api">

    <?php /* --- SECTION A: API Credentials --- */ ?>
    <div id="dexpress-section-api-credentials" class="dexpress-settings-section">
        <div class="dexpress-settings-section-head">
            <h2 class="dexpress-settings-section-title"><?php esc_html_e('API Credentials', 'dexpress-woocommerce'); ?></h2>
        </div>
        <div class="dexpress-settings-section-body">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="api_username"><?php esc_html_e('Korisničko ime', 'dexpress-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="api_username"
                               name="api_username"
                               class="regular-text"
                               value="<?php echo esc_attr($options->getString('api.username')); ?>"
                               autocomplete="off">
                        <p class="description">
                            <?php esc_html_e('API korisničko ime koje vam je dodelio D Express.', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="api_password"><?php esc_html_e('Lozinka', 'dexpress-woocommerce'); ?></label>
                    </th>
                    <td>
                        <div class="dexpress-password-wrap">
                            <input type="password"
                                   id="api_password"
                                   name="api_password"
                                   class="regular-text"
                                   value=""
                                   autocomplete="new-password"
                                   placeholder="<?php echo $hasPassword ? '••••••••' : ''; ?>">
                            <button type="button"
                                    class="button dexpress-toggle-password dexpress-pw-toggle"
                                    data-target="api_password"
                                    aria-pressed="false"
                                    title="<?php esc_attr_e('Prikaži / sakrij lozinku', 'dexpress-woocommerce'); ?>">
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php esc_html_e('Prikaži lozinku', 'dexpress-woocommerce'); ?></span>
                            </button>
                        </div>
                        <p class="description">
                            <?php if ($hasPassword) : ?>
                                <?php esc_html_e('Lozinka je podešena. Ostavite prazno da zadržite trenutnu.', 'dexpress-woocommerce'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Unesite API lozinku koju vam je dodelio D Express.', 'dexpress-woocommerce'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="api_client_id"><?php esc_html_e('Client ID (CClientID)', 'dexpress-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="api_client_id"
                               name="api_client_id"
                               class="regular-text"
                               value="<?php echo esc_attr($options->getString('api.client_id')); ?>">
                        <p class="description">
                            <?php esc_html_e('Vaš D Express klijentski ID ( ID vaše kompanije u D Express sistemu )  (npr. UKXXXXX). Dodeljuje ga D Express.', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="api_environment"><?php esc_html_e('Okruženje', 'dexpress-woocommerce'); ?></label>
                    </th>
                    <td>
                        <select id="api_environment" name="api_environment">
                            <option value="test" <?php selected($options->getString('api.environment', 'test'), 'test'); ?>>
                                <?php esc_html_e('Testno', 'dexpress-woocommerce'); ?>
                            </option>
                            <option value="production" <?php selected($options->getString('api.environment', 'test'), 'production'); ?>>
                                <?php esc_html_e('Produkcija', 'dexpress-woocommerce'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Testno okruženje koristi iste URL-ove kao produkcija, ali sa test kredencijalima.', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <div class="dexpress-test-connection-wrap">
                <button type="button" id="dexpress-test-connection" class="button button-secondary">
                    <?php esc_html_e('Testiraj konekciju', 'dexpress-woocommerce'); ?>
                </button>
                <span id="dexpress-test-connection-result" class="dexpress-inline-result" aria-live="polite"></span>
            </div>
        </div>
    </div>

    <?php /* --- SECTION B: Shipment Code Range --- */ ?>
    <div id="dexpress-section-shipment-code-range" class="dexpress-settings-section dexpress-settings-section--highlight">
        <div class="dexpress-settings-section-head">
            <h2 class="dexpress-settings-section-title"><?php esc_html_e('Opseg kodova pošiljki (npr. TT)', 'dexpress-woocommerce'); ?></h2>
        </div>
        <div class="dexpress-settings-section-body">
            <p class="description dexpress-settings-section-lead">
                <?php esc_html_e('Dvoslovni prefiks i numerički opseg koje vam je dodelio D Express (npr. TT sa 1–99 za test). Ispod pratite iskorišćenost.', 'dexpress-woocommerce'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="shipment_prefix"><?php esc_html_e('Prefiks pošiljke', 'dexpress-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="shipment_prefix"
                               name="shipment_prefix"
                               class="small-text"
                               maxlength="5"
                               value="<?php echo esc_attr($options->getString('shipment.prefix', 'TT')); ?>">
                        <p class="description">
                            <?php esc_html_e('2 velika slova koja vam je dodelio D Express (npr. TT za test).', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Opseg kodova', 'dexpress-woocommerce'); ?></th>
                    <td>
                        <label for="shipment_range_start"><?php esc_html_e('Od:', 'dexpress-woocommerce'); ?></label>
                        <input type="number"
                               id="shipment_range_start"
                               name="shipment_range_start"
                               class="small-text"
                               min="1"
                               value="<?php echo esc_attr($options->getInt('shipment.range_start', 1)); ?>">
                        &nbsp;
                        <label for="shipment_range_end"><?php esc_html_e('Do:', 'dexpress-woocommerce'); ?></label>
                        <input type="number"
                               id="shipment_range_end"
                               name="shipment_range_end"
                               class="small-text"
                               min="1"
                               value="<?php echo esc_attr($options->getInt('shipment.range_end', 99)); ?>">
                        <p class="description">
                            <?php esc_html_e('Numerički opseg koji vam je dodelio D Express. Za testno: 1–99.', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

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
                <div class="dexpress-shipment-range-monitor">
                    <p class="dexpress-usage-count" style="margin: 16px 0 8px;">
                        <?php
                        printf(
                            /* translators: 1: used count, 2: total capacity */
                            esc_html__('%1$s / %2$s iskorišćeno', 'dexpress-woocommerce'),
                            esc_html((string) $used_display),
                            esc_html((string) $total)
                        );
                        ?>
                        <span class="description" style="margin-left:8px;">
                            (<?php echo esc_html(number_format_i18n($usage_percent, 1)); ?>%)
                        </span>
                    </p>

                    <div class="dexpress-usage-meter<?php echo esc_attr($meter_class); ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($meter_w); ?>">
                        <div class="dexpress-usage-meter-fill" style="width: <?php echo esc_attr($meter_w); ?>%;"></div>
                    </div>

                    <p class="dexpress-usage-remaining" style="margin:12px 0 0;">
                        <strong><?php esc_html_e('Preostali kodovi', 'dexpress-woocommerce'); ?>:</strong>
                        <?php echo esc_html((string) $remaining_display); ?>
                    </p>

                    <p class="description" style="margin-top:6px;">
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
                        <div class="notice notice-error" style="margin:16px 0 0;">
                            <p><strong><?php esc_html_e('Opseg kodova pošiljki je iscrpljen', 'dexpress-woocommerce'); ?></strong></p>
                            <p><?php esc_html_e('Proširite opseg u dogovoru sa D Express ili, kada vam bude dodeljen novi opseg, ažurirajte vrednost „Do“ u podešavanjima.', 'dexpress-woocommerce'); ?></p>
                        </div>
                    <?php elseif ($show_usage_warn) : ?>
                        <div class="notice notice-warning" style="margin:16px 0 0;">
                            <p><strong><?php esc_html_e('Ponestaje vam kodova pošiljki', 'dexpress-woocommerce'); ?></strong></p>
                            <p><?php esc_html_e('Zatražite proširenje opsega kod D Express pre potpunog iscrpljenja.', 'dexpress-woocommerce'); ?></p>
                        </div>
                    <?php else : ?>
                        <p class="description" style="margin-top:14px;">
                            <?php esc_html_e('Iskorišćenost je u bezbednom opsegu. Uvek možete povećati „Do“ u dogovoru sa D Express ako očekujete rast.', 'dexpress-woocommerce'); ?>
                        </p>
                    <?php endif; ?>

                    <p style="margin-top:16px;">
                        <a class="button button-secondary"
                           href="<?php echo esc_url($mailto_href); ?>">
                            <?php esc_html_e('Zatražite dodatne kodove', 'dexpress-woocommerce'); ?>
                        </a>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-info" style="margin-top:12px;">
                    <p><?php esc_html_e('Sačuvajte ispravan dvoslovni prefiks i opseg (Od ≤ Do) da biste videli iskorišćenost i preostale kodove.', 'dexpress-woocommerce'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php /* --- SECTION C: Other shipment defaults (this tab only) --- */ ?>
    <div id="dexpress-section-other-settings" class="dexpress-settings-section">
        <div class="dexpress-settings-section-head">
            <h2 class="dexpress-settings-section-title"><?php esc_html_e('Ostala podešavanja', 'dexpress-woocommerce'); ?></h2>
        </div>
        <div class="dexpress-settings-section-body">
            <p class="description dexpress-settings-section-lead"><?php esc_html_e(
                'Podrazumevane vrednosti za pošiljku koje se koriste u čarobnjaku za slanje porudžbine (porudžbina ih može po potrebi zameniti).',
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

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>
