<?php
/**
 * Checkout — validacija adrese preko D Express API.
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;

$enabled = $options->getBool('validate_address.enabled', false);
?>

<div class="dex-notice dex-notice--info dex-tab-intro">
    <div class="dex-notice__content">
        <p class="dex-notice__body"><?php esc_html_e('Podešavanja koja utiču na izgled i ponašanje D-Express opcija tokom kupovine na vašem sajtu.', 'dexpress-woocommerce'); ?></p>
    </div>
</div>

<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1" />
    <input type="hidden" name="dexpress_active_tab" value="checkout" />

    <div class="dex-card">
        <div class="dex-card__header">
            <h2 class="dex-card__title"><?php esc_html_e('Validacija adrese', 'dexpress-woocommerce'); ?></h2>
        </div>
        <div class="dex-card__body">
            <div class="dex-check-row">
                <input type="checkbox"
                       id="validate_address_enabled"
                       name="validate_address_enabled"
                       value="1"
                       class="dex-check-row__checkbox"
                       <?php checked($enabled); ?> />
                <div class="dex-check-row__body">
                    <label for="validate_address_enabled" class="dex-check-row__label">
                        <?php esc_html_e('Provera adrese (checkaddress)', 'dexpress-woocommerce'); ?>
                    </label>
                    <p class="dex-check-row__desc">
                        <?php esc_html_e('Uključi proveru adrese primaoca preko D Express API-ja na checkout-u (blokira porudžbinu ako odgovor nije OK/TEST) i upozorenje u logu pre kreiranja pošiljke u adminu.', 'dexpress-woocommerce'); ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="dex-card__footer">
            <?php submit_button(__('Sačuvaj', 'dexpress-woocommerce'), 'primary', 'submit', false); ?>
        </div>
    </div>
</form>
