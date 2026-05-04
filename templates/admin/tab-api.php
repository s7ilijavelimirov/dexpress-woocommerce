<?php
/**
 * Admin settings tab: API podešavanja
 *
 * Available variables:
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 * @var bool $hasPassword
 */

defined('ABSPATH') || exit;
?>
<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="api">

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
                <?php if ($hasPassword) : ?>
                    <p class="dexpress-password-saved-indicator">
                        <span class="dashicons dashicons-lock" style="color:#00a32a;vertical-align:middle;"></span>
                        <strong style="color:#00a32a;"><?php esc_html_e('Lozinka je sačuvana', 'dexpress-woocommerce'); ?></strong>
                    </p>
                <?php endif; ?>
                <div class="dexpress-password-wrap" style="margin-top:6px;">
                    <input type="password"
                           id="api_password"
                           name="api_password"
                           class="regular-text"
                           value=""
                           autocomplete="new-password"
                           placeholder="<?php echo $hasPassword ? esc_attr('Unesite novu lozinku za promenu') : esc_attr('API lozinka'); ?>">
                    <button type="button" class="button button-secondary dexpress-toggle-password" data-target="api_password">
                        <?php esc_html_e('Prikaži', 'dexpress-woocommerce'); ?>
                    </button>
                </div>
                <p class="description">
                    <?php if ($hasPassword) : ?>
                        <?php esc_html_e('Ostavite polje prazno da zadržite sačuvanu lozinku. Unesite novu samo ako želite da je promenite.', 'dexpress-woocommerce'); ?>
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

    <h2><?php esc_html_e('Kodovi pošiljki', 'dexpress-woocommerce'); ?></h2>
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

    <h2><?php esc_html_e('Podrazumevane vrednosti pošiljke', 'dexpress-woocommerce'); ?></h2>
    <p class="description"><?php esc_html_e(
        'Koriste se u čarobnjaku za kreiranje pošiljke na narudžbini (narudžbina može da ih nadpiše ako je potrebno).',
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

    <div class="dexpress-test-connection-wrap">
        <button type="button" id="dexpress-test-connection" class="button button-secondary">
            <?php esc_html_e('Testiraj konekciju', 'dexpress-woocommerce'); ?>
        </button>
        <span id="dexpress-test-connection-result" class="dexpress-inline-result" aria-live="polite"></span>
    </div>

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>
