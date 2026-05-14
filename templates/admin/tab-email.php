<?php
/**
 * Admin settings tab: Email podešavanja
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;
?>

<div class="dex-notice dex-notice--info dex-tab-intro">
    <div class="dex-notice__content">
        <p class="dex-notice__body"><?php esc_html_e('Automatska obaveštenja kupcima o statusu pošiljke. Preporučujemo da ih uključite — kupci cene transparentnost u dostavi.', 'dexpress-woocommerce'); ?></p>
    </div>
</div>

<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="email">

    <div class="dex-card">
        <div class="dex-card__header">
            <h2 class="dex-card__title"><?php esc_html_e('Email obaveštenja', 'dexpress-woocommerce'); ?></h2>
        </div>
        <div class="dex-card__body">

            <div class="dex-check-row">
                <input type="checkbox"
                       id="auto_status_emails"
                       name="auto_status_emails"
                       value="1"
                       class="dex-check-row__checkbox"
                       <?php checked($options->getBool('email.auto_status_emails', true)); ?>>
                <div class="dex-check-row__body">
                    <label for="auto_status_emails" class="dex-check-row__label">
                        <?php esc_html_e('Automatski email o statusu', 'dexpress-woocommerce'); ?>
                    </label>
                    <p class="dex-check-row__desc">
                        <?php esc_html_e('Uključuje obaveštenja pri kreiranju pošiljke i pri svakoj promeni statusa prema šifarniku D Express (isti nazivi kao u bazi i u mejlovima). Pojedinačne šablone možete isključiti u WooCommerce → Podešavanja → Email.', 'dexpress-woocommerce'); ?>
                    </p>
                </div>
            </div>

            <div class="dex-check-row">
                <input type="checkbox"
                       id="myaccount_tracking_enabled"
                       name="myaccount_tracking_enabled"
                       value="1"
                       class="dex-check-row__checkbox"
                       <?php checked($options->getBool('email.myaccount_tracking_enabled', true)); ?>>
                <div class="dex-check-row__body">
                    <label for="myaccount_tracking_enabled" class="dex-check-row__label">
                        <?php esc_html_e('Praćenje u Moj nalog', 'dexpress-woocommerce'); ?>
                    </label>
                    <p class="dex-check-row__desc">
                        <?php esc_html_e('Kupci mogu pratiti status pošiljke direktno iz svog naloga na vašem sajtu.', 'dexpress-woocommerce'); ?>
                    </p>
                </div>
            </div>

            <div class="dex-check-row">
                <input type="checkbox"
                       id="emails_test_send_real_customer"
                       name="emails_test_send_real_customer"
                       value="1"
                       class="dex-check-row__checkbox"
                       <?php checked($options->getBool('emails.test_send_real_customer', false)); ?>>
                <div class="dex-check-row__body">
                    <label for="emails_test_send_real_customer" class="dex-check-row__label">
                        <?php esc_html_e('Test okruženje — šalji kupcu pravi email', 'dexpress-woocommerce'); ?>
                    </label>
                    <p class="dex-check-row__desc">
                        <?php esc_html_e('Kada je API u test modu, šalji na email sa narudžbine umesto na administratorski email. Podrazumevano su u test modu svi D Express emailovi usmereni na administratorsku adresu da ne biste slučajno obaveštavali kupce.', 'dexpress-woocommerce'); ?>
                    </p>
                </div>
            </div>

        </div>
        <div class="dex-card__footer">
            <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce'), 'primary', 'submit', false); ?>
        </div>
    </div>
</form>
