<?php
/**
 * Admin settings tab: Logovanje
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;

$uploadDir = wp_upload_dir();
$logDir    = $uploadDir['basedir'] . '/dexpress-logs/';
?>

<div class="dex-notice dex-notice--info dex-tab-intro">
    <div class="dex-notice__content">
        <p class="dex-notice__body"><?php esc_html_e('Logovi pomažu pri dijagnostici problema. Uključite ih samo kada rešavate grešku — ne ostavljajte uključene u produkciji.', 'dexpress-woocommerce'); ?></p>
    </div>
</div>

<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="logging">

    <div class="dex-card">
        <div class="dex-card__header">
            <h2 class="dex-card__title"><?php esc_html_e('Podešavanja logovanja', 'dexpress-woocommerce'); ?></h2>
        </div>
        <div class="dex-card__body">
            <div class="dex-settings-fields">

                <div class="dex-field">
                    <label for="log_level" class="dex-field__label"><?php esc_html_e('Nivo logovanja', 'dexpress-woocommerce'); ?></label>
                    <select id="log_level" name="log_level">
                        <?php
                        $levels = [
                            'none'    => __('Isključeno', 'dexpress-woocommerce'),
                            'error'   => __('Samo greške', 'dexpress-woocommerce'),
                            'warning' => __('Upozorenja i greške', 'dexpress-woocommerce'),
                            'info'    => __('Informacije', 'dexpress-woocommerce'),
                            'debug'   => __('Debug (detaljno)', 'dexpress-woocommerce'),
                        ];
                        $currentLevel = $options->getString('logging.level', 'error');
                        foreach ($levels as $value => $label) :
                            ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($currentLevel, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="dex-field__hint"><?php esc_html_e('Debug mod generiše veliku količinu podataka — koristite samo pri dijagnostici.', 'dexpress-woocommerce'); ?></p>
                </div>

                <div class="dex-field">
                    <label for="log_retention_days" class="dex-field__label"><?php esc_html_e('Čuvanje logova', 'dexpress-woocommerce'); ?></label>
                    <div class="dex-input-inline">
                        <input type="number"
                               id="log_retention_days"
                               name="log_retention_days"
                               min="1"
                               max="90"
                               value="<?php echo esc_attr($options->getInt('logging.retention_days', 30)); ?>">
                        <span class="dex-input-inline__suffix"><?php esc_html_e('dana', 'dexpress-woocommerce'); ?></span>
                    </div>
                    <p class="dex-field__hint"><?php esc_html_e('Log fajlovi stariji od ovog broja dana biće automatski obrisani (1–90 dana).', 'dexpress-woocommerce'); ?></p>
                </div>

                <div class="dex-field">
                    <span class="dex-field__label"><?php esc_html_e('Lokacija logova', 'dexpress-woocommerce'); ?></span>
                    <code class="dex-code-path"><?php echo esc_html($logDir); ?></code>
                    <p class="dex-field__hint"><?php esc_html_e('Log fajlovi se čuvaju van plugin foldera radi bezbednosti.', 'dexpress-woocommerce'); ?></p>
                </div>

            </div>
        </div>
        <div class="dex-card__footer">
            <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce'), 'primary', 'submit', false); ?>
        </div>
    </div>
</form>
