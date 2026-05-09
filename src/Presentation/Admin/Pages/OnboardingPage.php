<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Logging\Logger;
use S7codedesign\DExpress\Infrastructure\Options\EncryptedString;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressPackageShopShippingMethod;
use S7codedesign\DExpress\Presentation\Frontend\Shipping\DexpressShippingMethod;

/**
 * Onboarding wizard — prikazuje se automatski pri prvoj aktivaciji (option dexpress_onboarding_complete = 'no')
 * i dostupan je uvek putem menija D Express → Početno podešavanje.
 */
final class OnboardingPage
{
    public const PAGE_SLUG = 'dexpress-onboarding';

    public function __construct(
        private readonly OptionsRepository $options,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_onboarding_complete', [$this, 'handleComplete']);
        add_action('wp_ajax_dexpress_onboarding_create_zone', [$this, 'handleCreateZone']);
        add_action('wp_ajax_dexpress_dismiss_onboarding_notice', [$this, 'handleDismissNotice']);
        add_action('wp_ajax_dexpress_onboarding_log', [$this, 'handleLog']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu za pristup ovoj stranici.', 'dexpress-woocommerce'));
        }

        $this->logger->info('[ONBOARDING] Wizard page loaded', ['user_id' => get_current_user_id()]);

        wp_enqueue_style(
            'dexpress-design-system',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin-design-system.css',
            [],
            DEXPRESS_VERSION,
        );
        wp_enqueue_style(
            'dexpress-onboarding',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin-onboarding.css',
            ['dashicons', 'dexpress-design-system'],
            DEXPRESS_VERSION,
        );
        wp_enqueue_script(
            'dexpress-onboarding',
            DEXPRESS_PLUGIN_URL . 'assets/js/admin-onboarding.js',
            ['jquery'],
            DEXPRESS_VERSION,
            true,
        );
        wp_localize_script('dexpress-onboarding', 'dexpressOnboarding', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'testConnection'    => wp_create_nonce('dexpress_test_connection'),
                'manualSync'        => wp_create_nonce('dexpress_manual_sync'),
                'saveSenderLocation'=> wp_create_nonce('dexpress_save_sender_location'),
                'searchTowns'       => wp_create_nonce('dexpress_admin_search_towns'),
                'searchStreets'     => wp_create_nonce('dexpress_admin_search_streets'),
                'createZone'        => wp_create_nonce('dexpress_onboarding_create_zone'),
                'complete'          => wp_create_nonce('dexpress_onboarding_complete'),
                'log'               => wp_create_nonce('dexpress_onboarding_log'),
            ],
            'dashboardUrl'        => esc_url(admin_url('admin.php?page=dexpress')),
            'settingsUrl'         => esc_url(admin_url('admin.php?page=dexpress-settings')),
            'shippingSettingsUrl' => esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')),
        ]);

        echo '<div class="wrap dex-ob-page">';
        $this->renderWizard();
        echo '</div>';
    }

    /** AJAX: čuva kredencijale i označava onboarding kao završen. */
    public function handleComplete(): void
    {
        if (!check_ajax_referer('dexpress_onboarding_complete', 'nonce', false)) {
            $this->logger->warning('[ONBOARDING] handleComplete: neispravan nonce', ['user_id' => get_current_user_id()]);
            wp_send_json_error(['message' => 'Nevažeći sigurnosni token.'], 403);
        }

        if (!current_user_can('manage_woocommerce')) {
            $this->logger->warning('[ONBOARDING] handleComplete: nedovoljne privilegije', ['user_id' => get_current_user_id()]);
            wp_send_json_error(['message' => 'Nemate dozvolu.'], 403);
        }

        $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
        $password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));
        $clientId = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));

        if ($username !== '') {
            $this->options->set('api.username', $username);
        }
        if ($password !== '') {
            $this->options->set('api.password', EncryptedString::encrypt($password)->toString());
        }
        if ($clientId !== '') {
            $this->options->set('api.client_id', $clientId);
        }
        if ($username !== '' || $password !== '' || $clientId !== '') {
            $this->options->save();
        }

        update_option('dexpress_onboarding_complete', 'yes');

        $this->logger->info('[ONBOARDING] Onboarding završen', [
            'user_id'          => get_current_user_id(),
            'credentials_saved' => $username !== '' || $password !== '' || $clientId !== '',
        ]);

        wp_send_json_success(['redirect' => admin_url('admin.php?page=dexpress')]);
    }

    /** AJAX: kreira WooCommerce shipping zonu za Srbiju sa oba D Express metoda. */
    public function handleCreateZone(): void
    {
        if (!check_ajax_referer('dexpress_onboarding_create_zone', 'nonce', false)) {
            $this->logger->warning('[ONBOARDING] handleCreateZone: neispravan nonce', ['user_id' => get_current_user_id()]);
            wp_send_json_error(['message' => 'Nevažeći sigurnosni token.'], 403);
        }

        if (!current_user_can('manage_woocommerce')) {
            $this->logger->warning('[ONBOARDING] handleCreateZone: nedovoljne privilegije', ['user_id' => get_current_user_id()]);
            wp_send_json_error(['message' => 'Nemate dozvolu.'], 403);
        }

        if (!class_exists('WC_Shipping_Zone') || !function_exists('WC')) {
            $this->logger->error('[ONBOARDING] handleCreateZone: WooCommerce nije dostupan');
            wp_send_json_error(['message' => 'WooCommerce nije aktivan.']);
        }

        // Inicijalizuj WC shipping pre manipulacije zonama.
        WC()->shipping();

        try {
            $dexpressMethods = [DexpressShippingMethod::METHOD_ID, DexpressPackageShopShippingMethod::METHOD_ID];

            // Pronađi sve zone koje već imaju barem jedan D-Express metod.
            $foundZoneIds = [];
            foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
                $existingZone = new \WC_Shipping_Zone((int) $zoneData['id']);
                foreach ($existingZone->get_shipping_methods(false) as $method) {
                    if (in_array($method->id, $dexpressMethods, true)) {
                        $foundZoneIds[] = (int) $zoneData['id'];
                        break;
                    }
                }
            }

            // Ako postoji više zona sa D-Express metodama, obriši duplikate (zadrži prvu).
            if (count($foundZoneIds) > 1) {
                $keep = array_shift($foundZoneIds);
                foreach ($foundZoneIds as $dupId) {
                    $dupZone = new \WC_Shipping_Zone($dupId);
                    $dupZone->delete();
                    $this->logger->info('[ONBOARDING] Obrisana duplikat zona', ['zone_id' => $dupId]);
                }

                WC()->shipping()->load();
                \WC_Cache_Helper::get_transient_version('shipping', true);

                $this->logger->info('[ONBOARDING] Duplikati obrisani, zadržana zona', ['kept_zone_id' => $keep]);
                wp_send_json_success([
                    'message' => __('Zona sa D-Express metodama već postoji. Duplikati su obrisani.', 'dexpress-woocommerce'),
                ]);
            }

            // Tačno jedna zona već postoji — ništa ne radimo.
            if (count($foundZoneIds) === 1) {
                $this->logger->info('[ONBOARDING] Zona već postoji, preskačemo kreiranje', ['zone_id' => $foundZoneIds[0]]);
                wp_send_json_success([
                    'message' => __('Zona sa D-Express metodama dostave već postoji.', 'dexpress-woocommerce'),
                ]);
            }

            // Nema zone sa D-Express metodama — kreiraj novu.
            $zone = new \WC_Shipping_Zone();
            $zone->set_zone_name(__('Srbija — D Express', 'dexpress-woocommerce'));
            $zone->add_location('RS', 'country');
            $zoneId = $zone->save();

            if (!$zoneId) {
                $this->logger->error('[ONBOARDING] handleCreateZone: save() vratio falsy ID');
                wp_send_json_error(['message' => __('Zona nije kreirana — WooCommerce nije vratio ID zone.', 'dexpress-woocommerce')]);
            }

            $zone->add_shipping_method(DexpressShippingMethod::METHOD_ID);
            $zone->add_shipping_method(DexpressPackageShopShippingMethod::METHOD_ID);

            // Reinicijalizuj WC shipping i obriši keš kako bi metodi bili odmah vidljivi na frontendu.
            WC()->shipping()->load();
            \WC_Cache_Helper::get_transient_version('shipping', true);

            $this->logger->info('[ONBOARDING] Shipping zona kreirana', [
                'zone_id' => $zoneId,
                'methods' => $dexpressMethods,
            ]);

            wp_send_json_success([
                'message' => __('Zona "Srbija — D Express" je kreirana sa oba metoda dostave.', 'dexpress-woocommerce'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[ONBOARDING] handleCreateZone: iznimka — ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: PHP error message */
                    __('Greška pri kreiranju zone: %s', 'dexpress-woocommerce'),
                    $e->getMessage()
                ),
            ]);
        }
    }

    /** AJAX: trajno sakriva admin notice za tekućeg korisnika (user meta). */
    public function handleDismissNotice(): void
    {
        if (!check_ajax_referer('dexpress_dismiss_onboarding_notice', 'nonce', false)) {
            wp_send_json_error([], 403);
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([], 403);
        }

        update_user_meta(get_current_user_id(), '_dexpress_onboarding_notice_dismissed', '1');
        wp_send_json_success();
    }

    /**
     * AJAX: prima log poruku sa JS strane i upisuje je kroz Logger.
     * Koristi se za evente koji prolaze kroz postojeće kontrolere (test konekcije, sync, lokacija).
     */
    public function handleLog(): void
    {
        if (!check_ajax_referer('dexpress_onboarding_log', 'nonce', false)) {
            wp_send_json_error([], 403);
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([], 403);
        }

        $level   = sanitize_key($_POST['level'] ?? 'info');
        $message = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));

        if ($message === '') {
            wp_send_json_error(['message' => 'Prazna poruka.']);
        }

        $allowedLevels = ['info', 'warning', 'error'];
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'info';
        }

        $this->logger->{$level}('[ONBOARDING] ' . $message, ['user_id' => get_current_user_id()]);
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private function renderWizard(): void
    {
        $steps = [
            1 => __('Dobrodošli', 'dexpress-woocommerce'),
            2 => __('API podaci', 'dexpress-woocommerce'),
            3 => __('Šifarnici', 'dexpress-woocommerce'),
            4 => __('Lokacija', 'dexpress-woocommerce'),
            5 => __('Dostava', 'dexpress-woocommerce'),
            6 => __('Završeno', 'dexpress-woocommerce'),
        ];
        ?>
        <div class="dex-ob-wrap">

            <div class="dex-ob-header">
                <img src="<?php echo esc_url(DEXPRESS_PLUGIN_URL . 'assets/images/dexpress-icon.svg'); ?>"
                     alt="D Express" class="dex-ob-logo" aria-hidden="true">
                <h1><?php esc_html_e('Početno podešavanje', 'dexpress-woocommerce'); ?></h1>
                <p class="dex-ob-subtitle">
                    <?php esc_html_e('Prođi kroz korake da bi tvoja prodavnica bila spremna za D Express dostavu.', 'dexpress-woocommerce'); ?>
                </p>
            </div>

            <div class="dex-ob-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="dex-ob-progress-fill" id="dex-ob-progress"></div>
            </div>

            <div class="dex-ob-steps-nav" role="list">
                <?php foreach ($steps as $num => $label) : ?>
                    <div class="dex-ob-step-dot<?php echo $num === 1 ? ' is-active' : ''; ?>"
                         data-step="<?php echo (int) $num; ?>"
                         role="listitem"
                         aria-label="<?php echo esc_attr(sprintf('%d. %s', $num, $label)); ?>">
                        <span class="dex-ob-step-num"><?php echo (int) $num; ?></span>
                        <span class="dex-ob-step-label"><?php echo esc_html($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="dex-ob-card">

                <?php $this->renderPanel1(); ?>
                <?php $this->renderPanel2(); ?>
                <?php $this->renderPanel3(); ?>
                <?php $this->renderPanel4(); ?>
                <?php $this->renderPanel5(); ?>
                <?php $this->renderPanel6(); ?>

            </div><!-- .dex-ob-card -->
        </div><!-- .dex-ob-wrap -->
        <?php
    }

    private function renderPanel1(): void
    {
        ?>
        <div class="dex-ob-panel is-active" id="dex-ob-panel-1">
            <div class="dex-ob-panel-icon">
                <span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
            </div>
            <h2><?php esc_html_e('Dobrodošao u D Express WooCommerce integraciju!', 'dexpress-woocommerce'); ?></h2>
            <p><?php esc_html_e('Ovaj kratki čarobnjak će ti pomoći da podeziš ključne opcije za D Express dostavu. Prođi kroz korake redom — možeš preskočiti svaki korak i podesiti ga kasnije u podešavanjima.', 'dexpress-woocommerce'); ?></p>

            <ul class="dex-ob-feature-list">
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Automatsko kreiranje pošiljki direktno iz WooCommerce narudžbina', 'dexpress-woocommerce'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Praćenje statusa dostave u realnom vremenu', 'dexpress-woocommerce'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Podrška za paketomate i kućnu dostavu', 'dexpress-woocommerce'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Automatski e-mailovi o statusu pošiljke kupcima', 'dexpress-woocommerce'); ?>
                </li>
            </ul>

            <div class="dex-ob-nav">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress')); ?>"
                   class="button dex-ob-skip-all">
                    <?php esc_html_e('Preskoči podešavanje', 'dexpress-woocommerce'); ?>
                </a>
                <button class="button button-primary dex-ob-next" data-step="1">
                    <?php esc_html_e('Počni podešavanje', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel2(): void
    {
        $savedUsername = esc_attr($this->options->getString('api.username'));
        $savedClientId = esc_attr($this->options->getString('api.client_id'));
        $hasPassword   = !EncryptedString::fromString($this->options->getString('api.password'))->isEmpty();
        ?>
        <div class="dex-ob-panel" id="dex-ob-panel-2" hidden>
            <div class="dex-ob-panel-icon">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
            </div>
            <h2><?php esc_html_e('API pristupni podaci', 'dexpress-woocommerce'); ?></h2>
            <p><?php esc_html_e('Unesi korisničko ime, lozinku i Client ID koje si dobio od D Express-a. Podaci se čuvaju enkriptovani u bazi.', 'dexpress-woocommerce'); ?></p>

            <table class="form-table dex-ob-form-table">
                <tr>
                    <th scope="row">
                        <label for="dex-ob-api-username">
                            <?php esc_html_e('Korisničko ime', 'dexpress-woocommerce'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               id="dex-ob-api-username"
                               class="regular-text"
                               value="<?php echo $savedUsername; ?>"
                               autocomplete="username">
                        <?php if ($savedUsername !== '') : ?>
                            <p class="description"><?php esc_html_e('Korisničko ime je već podešeno — izmeni samo ako je potrebno.', 'dexpress-woocommerce'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="dex-ob-api-password">
                            <?php esc_html_e('Lozinka', 'dexpress-woocommerce'); ?>
                        </label>
                    </th>
                    <td>
                        <div class="dexpress-password-wrap">
                            <input type="password"
                                   id="dex-ob-api-password"
                                   class="regular-text"
                                   autocomplete="new-password"
                                   placeholder="<?php echo $hasPassword ? '••••••••' : ''; ?>">
                            <button type="button"
                                    class="button dex-ob-toggle-pass dexpress-pw-toggle"
                                    data-target="dex-ob-api-password"
                                    aria-pressed="false"
                                    title="<?php esc_attr_e('Prikaži / sakrij lozinku', 'dexpress-woocommerce'); ?>">
                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php esc_html_e('Prikaži lozinku', 'dexpress-woocommerce'); ?></span>
                            </button>
                        </div>
                        <p class="description">
                            <?php if ($hasPassword) : ?>
                                <?php esc_html_e('Lozinka je podešena. Ostavite prazno da zadržite trenutnu.', 'dexpress-woocommerce'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Unesite lozinku koju ste dobili od D Express-a.', 'dexpress-woocommerce'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="dex-ob-api-client-id">
                            <?php esc_html_e('Client ID (CClientID)', 'dexpress-woocommerce'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               id="dex-ob-api-client-id"
                               class="regular-text"
                               value="<?php echo $savedClientId; ?>"
                               autocomplete="off">
                        <p class="description"><?php esc_html_e('Obavezan za kreiranje pošiljki. Dobija se od D Express-a zajedno sa ostalim API podacima.', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>

            <div class="dex-ob-action-row">
                <button class="button" id="dex-ob-test-connection">
                    <?php esc_html_e('Testiraj konekciju', 'dexpress-woocommerce'); ?>
                </button>
                <span class="dex-ob-inline-result" id="dex-ob-connection-result" aria-live="polite"></span>
            </div>

            <div class="dex-ob-nav">
                <button class="button dex-ob-back" data-step="2">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button class="button dex-ob-skip" data-step="2">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button class="button button-primary dex-ob-next" data-step="2" disabled>
                    <?php esc_html_e('Dalje', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel3(): void
    {
        ?>
        <div class="dex-ob-panel" id="dex-ob-panel-3" hidden>
            <div class="dex-ob-panel-icon">
                <span class="dashicons dashicons-database-import" aria-hidden="true"></span>
            </div>
            <h2><?php esc_html_e('Sinhronizacija šifarnika', 'dexpress-woocommerce'); ?></h2>
            <p>
                <?php esc_html_e('Plugin koristi lokalne kopije D Express šifarnika (gradovi, ulice, paketomat lokacije itd.) za brz rad bez stalnog pozivanja API-ja. Preporučujemo da pokreneš sinhronizaciju odmah.', 'dexpress-woocommerce'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Napomena:', 'dexpress-woocommerce'); ?></strong>
                <?php esc_html_e('Sinhronizacija može potrajati 1–3 minute zavisno od brzine servera.', 'dexpress-woocommerce'); ?>
            </p>

            <div class="dex-ob-action-row">
                <button class="button button-secondary" id="dex-ob-sync">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e('Pokreni sinhronizaciju', 'dexpress-woocommerce'); ?>
                </button>
                <span class="spinner" id="dex-ob-sync-spinner"></span>
            </div>
            <div class="dex-ob-result-block" id="dex-ob-sync-result" aria-live="polite"></div>

            <div class="dex-ob-nav">
                <button class="button dex-ob-back" data-step="3">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button class="button dex-ob-skip" data-step="3">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button class="button button-primary dex-ob-next" data-step="3" disabled>
                    <?php esc_html_e('Dalje', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel4(): void
    {
        ?>
        <div class="dex-ob-panel" id="dex-ob-panel-4" hidden>
            <div class="dex-ob-panel-icon">
                <span class="dashicons dashicons-location" aria-hidden="true"></span>
            </div>
            <h2><?php esc_html_e('Lokacija pošiljaoca', 'dexpress-woocommerce'); ?></h2>
            <p>
                <?php esc_html_e('Definiši adresu sa koje se šalju paketi. Ova lokacija se koristi pri kreiranju pošiljki. Možeš dodati više lokacija kasnije u podešavanjima.', 'dexpress-woocommerce'); ?>
            </p>

            <table class="form-table dex-ob-form-table">
                <tr>
                    <th scope="row"><label for="dex-ob-loc-name"><?php esc_html_e('Naziv lokacije', 'dexpress-woocommerce'); ?> <span class="dex-req">*</span></label></th>
                    <td><input type="text" id="dex-ob-loc-name" class="regular-text" placeholder="<?php esc_attr_e('npr. Centralno skladište', 'dexpress-woocommerce'); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="dex-ob-loc-town-name"><?php esc_html_e('Grad', 'dexpress-woocommerce'); ?> <span class="dex-req">*</span></label></th>
                    <td>
                        <div class="dex-ob-autocomplete-wrap">
                            <input type="text" id="dex-ob-loc-town-name" class="regular-text"
                                   placeholder="<?php esc_attr_e('Kucaj naziv grada...', 'dexpress-woocommerce'); ?>"
                                   autocomplete="off">
                            <input type="hidden" id="dex-ob-loc-town-id">
                            <span class="spinner" id="dex-ob-town-spinner"></span>
                            <div class="dex-ob-suggestions" id="dex-ob-town-suggestions" role="listbox" hidden></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dex-ob-loc-street-name"><?php esc_html_e('Ulica', 'dexpress-woocommerce'); ?> <span class="dex-req">*</span></label></th>
                    <td>
                        <div class="dex-ob-autocomplete-wrap">
                            <input type="text" id="dex-ob-loc-street-name" class="regular-text"
                                   placeholder="<?php esc_attr_e('Kucaj naziv ulice...', 'dexpress-woocommerce'); ?>"
                                   autocomplete="off">
                            <input type="hidden" id="dex-ob-loc-street-id">
                            <span class="spinner" id="dex-ob-street-spinner"></span>
                            <div class="dex-ob-suggestions" id="dex-ob-street-suggestions" role="listbox" hidden></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dex-ob-loc-number"><?php esc_html_e('Kućni broj', 'dexpress-woocommerce'); ?> <span class="dex-req">*</span></label></th>
                    <td><input type="text" id="dex-ob-loc-number" class="small-text" placeholder="bb"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="dex-ob-loc-contact-name"><?php esc_html_e('Kontakt osoba', 'dexpress-woocommerce'); ?> <span class="dex-req">*</span></label></th>
                    <td><input type="text" id="dex-ob-loc-contact-name" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="dex-ob-loc-contact-phone"><?php esc_html_e('Telefon', 'dexpress-woocommerce'); ?> <span class="dex-req">*</span></label></th>
                    <td>
                        <input type="text" id="dex-ob-loc-contact-phone" class="regular-text" placeholder="381641234567">
                        <p id="dex-ob-phone-error" class="dex-ob-field-error" aria-live="polite"></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dex-ob-loc-bank-account"><?php esc_html_e('Tekući račun', 'dexpress-woocommerce'); ?></label></th>
                    <td>
                        <input type="text" id="dex-ob-loc-bank-account" class="regular-text" placeholder="000-0000000000000-00">
                        <p class="description"><?php esc_html_e('Popuni samo ako koristiš otkupninu (plaćanje pouzećem).', 'dexpress-woocommerce'); ?></p>
                    </td>
                </tr>
            </table>

            <div class="dex-ob-action-row">
                <button class="button button-secondary" id="dex-ob-save-location">
                    <?php esc_html_e('Sačuvaj lokaciju', 'dexpress-woocommerce'); ?>
                </button>
            </div>
            <div class="dex-ob-result-block" id="dex-ob-location-result" aria-live="polite"></div>

            <div class="dex-ob-nav">
                <button class="button dex-ob-back" data-step="4">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button class="button dex-ob-skip" data-step="4">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button class="button button-primary dex-ob-next" data-step="4" disabled>
                    <?php esc_html_e('Dalje', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel5(): void
    {
        ?>
        <div class="dex-ob-panel" id="dex-ob-panel-5" hidden>
            <div class="dex-ob-panel-icon">
                <span class="dashicons dashicons-car" aria-hidden="true"></span>
            </div>
            <h2><?php esc_html_e('Metode dostave', 'dexpress-woocommerce'); ?></h2>
            <p>
                <?php esc_html_e('Plugin može automatski kreirati WooCommerce zonu dostave "Srbija — D Express" i dodati oba metoda:', 'dexpress-woocommerce'); ?>
            </p>
            <ul class="dex-ob-feature-list">
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <strong><?php esc_html_e('D Express — kućna dostava', 'dexpress-woocommerce'); ?></strong>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <strong><?php esc_html_e('D Express — paketomat', 'dexpress-woocommerce'); ?></strong>
                </li>
            </ul>
            <p class="description">
                <?php esc_html_e('Ako već imaš podešene zone dostave, možeš preskočiti ovaj korak i ručno dodati metode u WooCommerce → Podešavanja → Dostava.', 'dexpress-woocommerce'); ?>
            </p>

            <div class="dex-ob-action-row">
                <button class="button button-secondary" id="dex-ob-create-zone">
                    <span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Kreiraj zonu dostave', 'dexpress-woocommerce'); ?>
                </button>
            </div>
            <div class="dex-ob-result-block" id="dex-ob-zone-result" aria-live="polite"></div>

            <div class="dex-ob-nav">
                <button class="button dex-ob-back" data-step="5">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button class="button dex-ob-skip" data-step="5">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button class="button button-primary dex-ob-next" data-step="5" disabled>
                    <?php esc_html_e('Dalje', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel6(): void
    {
        $clientIdMissing = trim($this->options->getString('api.client_id')) === '';
        ?>
        <div class="dex-ob-panel" id="dex-ob-panel-6" hidden>
            <div class="dex-ob-panel-icon dex-ob-panel-icon--success">
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
            </div>
            <h2><?php esc_html_e('Sve je podešeno!', 'dexpress-woocommerce'); ?></h2>
            <p>
                <?php esc_html_e('Tvoja prodavnica je sada spremna za korišćenje D Express dostave. Klikni dugme ispod da završiš podešavanje i odeš na pregled naloga.', 'dexpress-woocommerce'); ?>
            </p>

            <?php if ($clientIdMissing) : ?>
            <div class="notice notice-warning inline" style="margin-bottom:16px;">
                <p>
                    <strong><?php esc_html_e('Upozorenje:', 'dexpress-woocommerce'); ?></strong>
                    <?php printf(
                        /* translators: %s: link to API settings step */
                        esc_html__('Client ID (CClientID) nije unet — kreiranje pošiljki će biti onemogućeno. %s', 'dexpress-woocommerce'),
                        '<a href="#" class="dex-ob-back" data-step="6">' . esc_html__('Vrati se na Korak 2 da ga uneseš', 'dexpress-woocommerce') . '</a>',
                    ); ?>
                </p>
            </div>
            <?php endif; ?>

            <ul class="dex-ob-feature-list">
                <li>
                    <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-settings')); ?>">
                        <?php esc_html_e('Podešavanja — dodatne opcije, lokacije, simulacija', 'dexpress-woocommerce'); ?>
                    </a>
                </li>
                <li>
                    <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress-shipments')); ?>">
                        <?php esc_html_e('Pošiljke — pregled i upravljanje kreiranim pošiljkama', 'dexpress-woocommerce'); ?>
                    </a>
                </li>
            </ul>

            <div class="dex-ob-nav dex-ob-nav--center">
                <button class="button dex-ob-back" data-step="6">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button class="button button-primary" id="dex-ob-complete">
                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                    <?php esc_html_e('Završi podešavanje', 'dexpress-woocommerce'); ?>
                </button>
            </div>
            <div class="dex-ob-result-block" id="dex-ob-complete-result" aria-live="polite"></div>
        </div>
        <?php
    }
}
