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
<form method="post" action="">
    <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
    <input type="hidden" name="dexpress_save_settings" value="1">
    <input type="hidden" name="dexpress_active_tab" value="logging">

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">
                <label for="log_level"><?php esc_html_e('Nivo logovanja', 'dexpress-woocommerce'); ?></label>
            </th>
            <td>
                <select id="log_level" name="log_level">
                    <?php
                    $levels    = ['none' => 'Isključeno', 'error' => 'Samo greške', 'warning' => 'Upozorenja i greške', 'info' => 'Informacije', 'debug' => 'Debug (detaljno)'];
                    $currentLevel = $options->getString('logging.level', 'error');
                    foreach ($levels as $value => $label) :
                        ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($currentLevel, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php esc_html_e('Debug mod generiše veliku količinu podataka — koristite samo pri dijagnostici.', 'dexpress-woocommerce'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="log_retention_days"><?php esc_html_e('Čuvanje logova (dani)', 'dexpress-woocommerce'); ?></label>
            </th>
            <td>
                <input type="number"
                       id="log_retention_days"
                       name="log_retention_days"
                       class="small-text"
                       min="1"
                       max="90"
                       value="<?php echo esc_attr($options->getInt('logging.retention_days', 30)); ?>">
                <p class="description">
                    <?php esc_html_e('Log fajlovi stariji od ovog broja dana biće automatski obrisani (1–90 dana).', 'dexpress-woocommerce'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Lokacija logova', 'dexpress-woocommerce'); ?></th>
            <td>
                <code><?php echo esc_html($logDir); ?></code>
                <p class="description">
                    <?php esc_html_e('Log fajlovi se čuvaju van plugin foldera radi bezbednosti.', 'dexpress-woocommerce'); ?>
                </p>
            </td>
        </tr>
    </table>

    <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce')); ?>
</form>
