<?php
/**
 * Admin settings tab: Email podešavanja
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;
?>
<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="email">

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">
                <label for="auto_status_emails">
                    <?php esc_html_e('Automatski email o statusu', 'dexpress-woocommerce'); ?>
                </label>
            </th>
            <td>
                <label>
                    <input type="checkbox"
                           id="auto_status_emails"
                           name="auto_status_emails"
                           value="1"
                           <?php checked($options->getBool('email.auto_status_emails', true)); ?>>
                    <?php esc_html_e('Automatski emailovi kupcu (D Express)', 'dexpress-woocommerce'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e('Uključuje obaveštenja kada je pošiljka kreirana i kada se menja status (u toku, na isporuci, isporučeno). Pojedinačne šablone možete isključiti u WooCommerce → Podešavanja → Email.', 'dexpress-woocommerce'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="myaccount_tracking_enabled">
                    <?php esc_html_e('Praćenje u Moj nalog', 'dexpress-woocommerce'); ?>
                </label>
            </th>
            <td>
                <label>
                    <input type="checkbox"
                           id="myaccount_tracking_enabled"
                           name="myaccount_tracking_enabled"
                           value="1"
                           <?php checked($options->getBool('email.myaccount_tracking_enabled', true)); ?>>
                    <?php esc_html_e('Prikaz statusa pošiljke u sekciji Moj nalog', 'dexpress-woocommerce'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e('Kupci mogu pratiti status pošiljke direktno iz svog naloga na vašem sajtu.', 'dexpress-woocommerce'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="emails_test_send_real_customer">
                    <?php esc_html_e('Test okruženje — šalji kupcu pravi email', 'dexpress-woocommerce'); ?>
                </label>
            </th>
            <td>
                <label>
                    <input type="checkbox"
                           id="emails_test_send_real_customer"
                           name="emails_test_send_real_customer"
                           value="1"
                           <?php checked($options->getBool('emails.test_send_real_customer', false)); ?>>
                    <?php esc_html_e('Kada je API u test modu, šalji na email sa narudžbine umesto na administratorski email', 'dexpress-woocommerce'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e('Podrazumevano su u test modu svi D Express emailovi usmereni na administratorsku adresu da ne biste slučajno obaveštavali kupce.', 'dexpress-woocommerce'); ?>
                </p>
            </td>
        </tr>
    </table>

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>
