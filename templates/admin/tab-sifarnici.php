<?php
/**
 * Admin settings tab: Šifarnici (Reference data sync)
 *
 * @var \S7codedesign\DExpress\Infrastructure\Options\OptionsRepository $options
 */

defined('ABSPATH') || exit;

$syncTypes = [
    'towns'          => [
        'label'     => __('Gradovi', 'dexpress-woocommerce'),
        'freq'      => __('Mesečno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_towns',
    ],
    'streets'        => [
        'label'     => __('Ulice', 'dexpress-woocommerce'),
        'freq'      => __('Nedeljno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_streets',
    ],
    'municipalities' => [
        'label'     => __('Opštine', 'dexpress-woocommerce'),
        'freq'      => __('Mesečno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_municipalities',
    ],
    'status_codes'   => [
        'label'     => __('Statusni kodovi', 'dexpress-woocommerce'),
        'freq'      => __('Nedeljno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_status_codes',
    ],
    'dispensers'     => [
        'label'     => __('Paketomat', 'dexpress-woocommerce'),
        'freq'      => __('Dnevno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_dispensers',
    ],
    'locations'      => [
        'label'     => __('Lokacije', 'dexpress-woocommerce'),
        'freq'      => __('Dnevno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_locations',
    ],
    'centres'        => [
        'label'     => __('Centri', 'dexpress-woocommerce'),
        'freq'      => __('Nedeljno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_centres',
    ],
    'shops'          => [
        'label'     => __('Prodavnice', 'dexpress-woocommerce'),
        'freq'      => __('Nedeljno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_shops',
    ],
];

$hasCredentials = $options->getString('api.username') !== '';
?>

<?php if (!$hasCredentials) : ?>
    <div class="notice notice-warning inline">
        <p>
            <?php
            printf(
                /* translators: %s: link to API settings tab */
                esc_html__('Pre sinhronizacije unesite API kredencijale u tabu %s.', 'dexpress-woocommerce'),
                '<a href="' . esc_url(add_query_arg(['page' => 'dexpress-settings', 'tab' => 'api'], admin_url('admin.php'))) . '">' . esc_html__('API podešavanja', 'dexpress-woocommerce') . '</a>',
            );
            ?>
        </p>
    </div>
<?php endif; ?>

<h2><?php esc_html_e('Referentni podaci', 'dexpress-woocommerce'); ?></h2>
<p class="description" style="margin-bottom:16px;">
    <?php esc_html_e('Šifarnici se automatski ažuriraju putem WP Cron-a prema prikazanoj učestalosti. Kliknite Sinhronizuj za ručno osvežavanje.', 'dexpress-woocommerce'); ?>
</p>

<p>
    <button type="button"
            id="dexpress-sync-all"
            class="button button-primary"
            <?php disabled(!$hasCredentials); ?>>
        <?php esc_html_e('Sinhronizuj sve šifarnike', 'dexpress-woocommerce'); ?>
    </button>
    <span id="dexpress-sync-all-result" class="dexpress-inline-result" style="margin-left:10px;" aria-live="polite"></span>
</p>

<table class="widefat dexpress-sync-table" style="margin-top:16px;">
    <thead>
        <tr>
            <th><?php esc_html_e('Šifarnik', 'dexpress-woocommerce'); ?></th>
            <th><?php esc_html_e('Automatska učestalost', 'dexpress-woocommerce'); ?></th>
            <th><?php esc_html_e('Poslednja sinhronizacija', 'dexpress-woocommerce'); ?></th>
            <th><?php esc_html_e('Ručno', 'dexpress-woocommerce'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($syncTypes as $type => $info) : ?>
            <?php $lastSync = $options->getString($info['last_key'], ''); ?>
            <tr>
                <td><strong><?php echo esc_html($info['label']); ?></strong></td>
                <td><?php echo esc_html($info['freq']); ?></td>
                <td>
                    <?php if ($lastSync !== '') : ?>
                        <?php
                        $dt = \DateTime::createFromFormat('YmdHis', $lastSync);
                        echo esc_html($dt ? $dt->format('d.m.Y H:i:s') : $lastSync);
                        ?>
                    <?php else : ?>
                        <em style="color:#888;"><?php esc_html_e('Nikad', 'dexpress-woocommerce'); ?></em>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button"
                            class="button button-small dexpress-manual-sync"
                            data-type="<?php echo esc_attr($type); ?>"
                            <?php disabled(!$hasCredentials); ?>>
                        <?php esc_html_e('Sinhronizuj', 'dexpress-woocommerce'); ?>
                    </button>
                    <span class="dexpress-sync-result" aria-live="polite"></span>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="dexpress-danger-zone" style="margin-top:32px;">
    <div class="dexpress-danger-zone-header">
        <span class="dashicons dashicons-warning" style="vertical-align:middle;margin-right:6px;"></span>
        <?php esc_html_e('Opasna zona', 'dexpress-woocommerce'); ?>
    </div>
    <div class="dexpress-danger-zone-body">
        <form method="post" action="">
            <?php wp_nonce_field('dexpress_save_settings', 'dexpress_settings_nonce'); ?>
            <input type="hidden" name="dexpress_save_settings" value="1">
            <input type="hidden" name="dexpress_active_tab" value="sifarnici">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="delete_data_on_uninstall">
                            <?php esc_html_e('Brisanje podataka pri deinstalaciji', 'dexpress-woocommerce'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   id="delete_data_on_uninstall"
                                   name="delete_data_on_uninstall"
                                   value="1"
                                   <?php checked($options->getBool('advanced.delete_data_on_uninstall', false)); ?>>
                            <?php esc_html_e('Obriši sve tabele i podešavanja pri deinstalaciji plugina', 'dexpress-woocommerce'); ?>
                        </label>
                        <p class="description" style="color:#8b1a1a;">
                            <?php esc_html_e('Kada je uključeno, brisanje plugina sa Plugins ekrana trajno briše SVE tabele i podešavanja. Ova akcija se ne može poništiti.', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce'), 'secondary'); ?>
        </form>
    </div>
</div>
