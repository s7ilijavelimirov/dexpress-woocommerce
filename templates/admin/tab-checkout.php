<?php
/**
 * Checkout — validacija adrese preko D Express API.
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;

$enabled = $options->getBool('validate_address.enabled', false);
?>
<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1" />
    <input type="hidden" name="dexpress_active_tab" value="checkout" />

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e('Provera adrese (checkaddress)', 'dexpress-woocommerce'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="validate_address_enabled" value="1" <?php checked($enabled); ?> />
                    <?php esc_html_e('Uključi proveru adrese primaoca preko D Express API-ja na checkout-u (blokira porudžbinu ako odgovor nije OK/TEST) i upozorenje u logu pre kreiranja pošiljke u adminu.', 'dexpress-woocommerce'); ?>
                </label>
            </td>
        </tr>
    </table>

    <?php submit_button(__('Sačuvaj', 'dexpress-woocommerce')); ?>
</form>
