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
        'freq'      => __('Jednom mesečno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_towns',
    ],
    'streets'        => [
        'label'     => __('Ulice', 'dexpress-woocommerce'),
        'freq'      => __('Jednom mesečno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_streets',
    ],
    'municipalities' => [
        'label'     => __('Opštine', 'dexpress-woocommerce'),
        'freq'      => __('Jednom mesečno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_municipalities',
    ],
    'status_codes'   => [
        'label'     => __('Statusni kodovi', 'dexpress-woocommerce'),
        'freq'      => __('Na 6 meseci', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_status_codes',
    ],
    'dispensers'     => [
        'label'     => __('Paketomat', 'dexpress-woocommerce'),
        'freq'      => __('Svakog dana', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_dispensers',
    ],
    'locations'      => [
        'label'     => __('Lokacije', 'dexpress-woocommerce'),
        'freq'      => __('Svakog dana', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_locations',
    ],
    'payments'       => [
        'label'     => __('Otkupnine', 'dexpress-woocommerce'),
        'freq'      => __('Ručno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_payments',
    ],
    'centres'        => [
        'label'     => __('Centri', 'dexpress-woocommerce'),
        'freq'      => __('Jednom mesečno', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_centres',
    ],
    'shops'          => [
        'label'     => __('Prodavnice', 'dexpress-woocommerce'),
        'freq'      => __('Na 3 meseca', 'dexpress-woocommerce'),
        'last_key'  => 'sync.last_shops',
    ],
];

$hasCredentials = $options->getString('api.username') !== '';
?>

<div class="dex-notice dex-notice--info dex-tab-intro">
    <div class="dex-notice__content">
        <p class="dex-notice__body"><?php esc_html_e('Šifarnici su liste gradova, ulica i poštanskih brojeva koje D-Express koristi. Plugin ih automatski osvežava — ručna sinhronizacija je potrebna samo ako primetite da nedostaju lokacije.', 'dexpress-woocommerce'); ?></p>
    </div>
</div>

<?php if (!$hasCredentials) : ?>
    <div class="dex-notice dex-notice--warning dex-tab-intro">
        <div class="dex-notice__content">
            <p class="dex-notice__body">
                <?php
                printf(
                    /* translators: %s: link to API settings tab */
                    esc_html__('Pre sinhronizacije unesite API kredencijale u tabu %s.', 'dexpress-woocommerce'),
                    '<a href="' . esc_url(add_query_arg(['page' => 'dexpress-settings', 'tab' => 'api'], admin_url('admin.php'))) . '">' . esc_html__('API podešavanja', 'dexpress-woocommerce') . '</a>',
                );
                ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="dex-sifarnici-toolbar">
    <button type="button"
            id="dex-sync-all"
            class="dex-btn dex-btn--primary"
            <?php disabled(!$hasCredentials); ?>>
        <span class="dashicons dashicons-update" aria-hidden="true"></span>
        <?php esc_html_e('Sinhronizuj sve šifarnike', 'dexpress-woocommerce'); ?>
    </button>
    <span id="dex-sync-all-result" class="dex-inline-result" aria-live="polite"></span>
</div>

<table class="widefat dex-sync-table">
    <thead>
        <tr>
            <th><?php esc_html_e('Šifarnik', 'dexpress-woocommerce'); ?></th>
            <th><?php esc_html_e('Automatsko ažuriranje', 'dexpress-woocommerce'); ?></th>
            <th><?php esc_html_e('Poslednja sinhronizacija', 'dexpress-woocommerce'); ?></th>
            <th><?php esc_html_e('Ručno', 'dexpress-woocommerce'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($syncTypes as $type => $info) : ?>
            <?php $lastSync = $options->getString($info['last_key'], ''); ?>
            <tr data-sync-type="<?php echo esc_attr($type); ?>">
                <td>
                    <strong><?php echo esc_html($info['label']); ?></strong>
                    <span class="dex-sync-status" aria-live="polite"></span>
                </td>
                <td><?php echo esc_html($info['freq']); ?></td>
                <td>
                    <?php if ($lastSync !== '') : ?>
                        <?php
                        $dt = \DateTime::createFromFormat('YmdHis', $lastSync);
                        echo esc_html($dt ? $dt->format('d.m.Y H:i:s') : $lastSync);
                        ?>
                    <?php else : ?>
                        <em class="dex-text-muted"><?php esc_html_e('Nikad', 'dexpress-woocommerce'); ?></em>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button"
                            class="dex-btn dex-btn--secondary dex-btn--sm dex-manual-sync"
                            data-type="<?php echo esc_attr($type); ?>"
                            <?php disabled(!$hasCredentials); ?>>
                        <?php esc_html_e('Sinhronizuj', 'dexpress-woocommerce'); ?>
                    </button>
                    <span class="dex-sync-result" aria-live="polite"></span>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="dex-danger-zone">
    <div class="dex-danger-zone__header">
        <?php esc_html_e('Opasna zona', 'dexpress-woocommerce'); ?>
    </div>
    <div class="dex-danger-zone__body">
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
                        <p class="description">
                            <?php esc_html_e('Kada je uključeno, brisanje plugina sa Plugins ekrana trajno briše sve tabele i podešavanja ovog plugina, kao i D Express meta polja na porudžbinama (ključevi koji počinju sa „_dexpress“). Ostala WooCommerce polja porudžbine (adresa, kupac, plaćanje itd.) ostaju netaknuta. Ova akcija se ne može poništiti.', 'dexpress-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Sačuvaj podešavanja', 'dexpress-woocommerce'), 'secondary'); ?>
        </form>
    </div>
</div>
