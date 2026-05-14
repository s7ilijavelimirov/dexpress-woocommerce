<?php
/**
 * Admin settings tab: Webhook
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;

$passcode    = $options->getString('webhook.passcode');
$webhookUrl  = rest_url('dexpress/v1/notify');
$allowedIp   = $options->getString('webhook.ip_address', '');

$currentIp = '';
$ipCandidates = [];
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $forwarded = trim((string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
    if ($forwarded !== '') {
        foreach (explode(',', $forwarded) as $part) {
            $ip = trim($part);
            if ($ip !== '') {
                $ipCandidates[] = $ip;
            }
        }
    }
}
if (isset($_SERVER['HTTP_X_REAL_IP'])) {
    $ip = trim((string) wp_unslash($_SERVER['HTTP_X_REAL_IP']));
    if ($ip !== '') {
        $ipCandidates[] = $ip;
    }
}
if (isset($_SERVER['REMOTE_ADDR'])) {
    $ip = trim((string) wp_unslash($_SERVER['REMOTE_ADDR']));
    if ($ip !== '') {
        $ipCandidates[] = $ip;
    }
}
foreach ($ipCandidates as $candidate) {
    if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
        $currentIp = $candidate;
        break;
    }
}
?>

<div class="dex-notice dex-notice--info dex-tab-intro">
    <div class="dex-notice__content">
        <p class="dex-notice__body"><?php esc_html_e('Webhook URL i lozinku unesite u D-Express portal da bi sistem automatski ažurirao status vaših pošiljki u realnom vremenu.', 'dexpress-woocommerce'); ?></p>
    </div>
</div>

<div class="dex-card">
    <div class="dex-card__header">
        <h2 class="dex-card__title"><?php esc_html_e('Webhook podaci', 'dexpress-woocommerce'); ?></h2>
    </div>
    <div class="dex-card__body">

        <div class="dex-settings-fields">

            <div class="dex-field">
                <span class="dex-field__label"><?php esc_html_e('Webhook URL', 'dexpress-woocommerce'); ?></span>
                <div class="dex-copy-field">
                    <code class="dex-copy-field__code dex-copy-target" id="webhook-url"><?php echo esc_html($webhookUrl); ?></code>
                    <button type="button" class="dex-btn dex-btn--secondary dex-btn--sm dex-copy-btn dex-copy-field__btn" data-target="webhook-url">
                        <?php esc_html_e('Kopiraj', 'dexpress-woocommerce'); ?>
                    </button>
                </div>
                <p class="dex-field__hint"><?php esc_html_e('Ovaj URL prijavite D Express podršci kao vašu Notify adresu.', 'dexpress-woocommerce'); ?></p>
            </div>

            <div class="dex-field">
                <span class="dex-field__label"><?php esc_html_e('Passcode (cc)', 'dexpress-woocommerce'); ?></span>
                <?php if ($passcode !== '') : ?>
                    <div class="dex-copy-field">
                        <code class="dex-copy-field__code dex-copy-target" id="webhook-passcode"><?php echo esc_html($passcode); ?></code>
                        <button type="button" class="dex-btn dex-btn--secondary dex-btn--sm dex-copy-btn dex-copy-field__btn" data-target="webhook-passcode">
                            <?php esc_html_e('Kopiraj', 'dexpress-woocommerce'); ?>
                        </button>
                    </div>
                    <p class="dex-field__hint"><?php esc_html_e('Pošaljite ovaj passcode D Express podršci zajedno sa Webhook URL-om. Automatski je generisan pri aktivaciji plugina.', 'dexpress-woocommerce'); ?></p>
                <?php else : ?>
                    <p class="dex-field__hint dex-notice-warning"><?php esc_html_e('Passcode nije generisan. Deaktivirajte i ponovo aktivirajte plugin.', 'dexpress-woocommerce'); ?></p>
                <?php endif; ?>
            </div>

        </div>

    </div>
    <div class="dex-card__footer dex-card__footer--instructions">
        <div class="dex-webhook-instructions">
            <p class="dex-webhook-instructions__title"><?php esc_html_e('Kako podesiti:', 'dexpress-woocommerce'); ?></p>
            <ol class="dex-webhook-instructions__list">
                <li><?php esc_html_e('Kopirajte Webhook URL i Passcode gore.', 'dexpress-woocommerce'); ?></li>
                <li><?php esc_html_e('Pošaljite oba podatka D Express podršci (podrška@dexpress.rs) i zamolite ih da registruju vaš Notify URL.', 'dexpress-woocommerce'); ?></li>
                <li><?php esc_html_e('D Express će početi da šalje statusna obaveštenja na vaš Webhook URL kada se status pošiljke promeni.', 'dexpress-woocommerce'); ?></li>
            </ol>
            <p class="dex-webhook-instructions__note"><?php esc_html_e('Webhook prihvata i PUT (query string) i POST (JSON body) zahteve od D Express-a.', 'dexpress-woocommerce'); ?></p>
        </div>
    </div>
</div>

<form method="post" action="" class="dex-settings-form">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="webhook">

    <div class="dex-card">
        <div class="dex-card__header">
            <h2 class="dex-card__title"><?php esc_html_e('Bezbednost', 'dexpress-woocommerce'); ?></h2>
        </div>
        <div class="dex-card__body">
            <div class="dex-settings-fields">

                <div class="dex-field">
                    <label for="webhook_ip_address" class="dex-field__label"><?php esc_html_e('Dozvoljena IP adresa (webhook)', 'dexpress-woocommerce'); ?></label>
                    <input type="text"
                           id="webhook_ip_address"
                           name="webhook_ip_address"
                           placeholder="203.0.113.10"
                           value="<?php echo esc_attr($allowedIp); ?>">
                    <p class="dex-field__hint">
                        <?php esc_html_e('IP adresa sa koje D-Express šalje webhook notifikacije. Ostavite prazno da dozvolite sve IP adrese.', 'dexpress-woocommerce'); ?>
                    </p>
                    <p class="dex-field__hint">
                        <?php
                        if ($currentIp !== '') {
                            printf(
                                /* translators: %s: currently detected IP */
                                esc_html__('Vaša trenutna IP adresa: %s', 'dexpress-woocommerce'),
                                esc_html($currentIp),
                            );
                        } else {
                            esc_html_e('Vaša trenutna IP adresa: nije detektovana', 'dexpress-woocommerce');
                        }
                        ?>
                    </p>
                </div>

            </div>
        </div>
        <div class="dex-card__footer">
            <?php submit_button(__('Sačuvaj webhook podešavanja', 'dexpress-woocommerce'), 'primary', 'submit', false); ?>
        </div>
    </div>
</form>
