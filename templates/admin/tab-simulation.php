<?php
/**
 * Admin settings tab: Simulacija statusa
 *
 * Available variables:
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;
?>
<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="simulation">

    <div class="notice notice-info inline" style="margin:12px 0;">
        <p>
            <?php esc_html_e('Kad je uključena, posle svake nove TEST pošiljke automatski se zakazuju isti koraci kao kad D Express šalje webhook obaveštenja (sID 0, 3, 4, 1). Svaki korak upisuje red u tabelu webhook logova i obrađuje se istim kodom kao pravi kurir — istorija statusa, beleške na narudžbini i mejlovi.', 'dexpress-woocommerce'); ?>
        </p>
        <p style="margin-top:6px;">
            <strong><?php esc_html_e('Kurirski kodovi (kao v1):', 'dexpress-woocommerce'); ?></strong>
            <?php esc_html_e('0 → 3 → 4 → 1 (mapiraju se u unutrašnje statuse prema šifarniku u bazi i StatusMapper-u).', 'dexpress-woocommerce'); ?>
        </p>
    </div>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">
                <?php esc_html_e('Uključi simulaciju', 'dexpress-woocommerce'); ?>
            </th>
            <td>
                <label>
                    <input type="checkbox"
                           name="simulation_enabled"
                           value="1"
                           <?php checked((bool) $options->get('simulation.enabled', false)); ?>>
                    <?php esc_html_e('Zakazuj lažne webhook-e za TEST pošiljke (samo testno okruženje)', 'dexpress-woocommerce'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <?php esc_html_e('Brzi demo raspored', 'dexpress-woocommerce'); ?>
            </th>
            <td>
                <label>
                    <input type="checkbox"
                           name="simulation_quick_timeline"
                           value="1"
                           <?php checked((bool) $options->get('simulation.quick_timeline', true)); ?>>
                    <?php esc_html_e('Kraći intervali (30s / 1 / 2 / 3 min od kreiranja) — pogodno da odmah vidite mejlove na demo sajtu', 'dexpress-woocommerce'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e('Isključite za isti vremenski raspored kao u v1 plugina: 2, 10, 25 i 45 minuta od kreiranja pošiljke.', 'dexpress-woocommerce'); ?>
                </p>
                <p class="description" style="margin-top:10px;">
                    <strong><?php esc_html_e('WP-Cron:', 'dexpress-woocommerce'); ?></strong>
                    <?php esc_html_e('Zakazani koraci se izvršavaju kada WordPress pokrene cron (poseta sajtu ili sistemski zadatak na wp-cron.php). Za pouzdan rad u produkciji preporučujemo pravi sistemski cron.', 'dexpress-woocommerce'); ?>
                </p>
            </td>
        </tr>
    </table>

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>
