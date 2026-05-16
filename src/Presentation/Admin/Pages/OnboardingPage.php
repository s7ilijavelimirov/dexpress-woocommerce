<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Application\Shipment\ShipmentCodeAllocator;
use S7codedesign\DExpress\Application\Sync\SyncCatalogOrder;
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
        private readonly ShipmentCodeAllocator $codeAllocator,
    ) {}

    public function register(): void
    {
        add_action('wp_ajax_dexpress_onboarding_complete', [$this, 'handleComplete']);
        add_action('wp_ajax_dexpress_onboarding_save_credentials', [$this, 'handleSaveCredentials']);
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
            'dex-admin',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DEXPRESS_VERSION,
        );
        wp_enqueue_style(
            'dexpress-onboarding',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin-onboarding.css',
            ['dashicons', 'dex-admin'],
            DEXPRESS_VERSION,
        );
        wp_enqueue_script(
            'dexpress-onboarding',
            DEXPRESS_PLUGIN_URL . 'assets/js/admin-onboarding.js',
            ['jquery'],
            DEXPRESS_VERSION,
            true,
        );
        $syncSequence = array_values(array_filter(
            SyncCatalogOrder::ALL_SEQUENCE,
            static fn(string $s): bool => $s !== 'payments',
        ));
        wp_localize_script('dexpress-onboarding', 'dexpressOnboarding', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'testConnection'    => wp_create_nonce('dexpress_test_connection'),
                'saveCredentials'   => wp_create_nonce('dexpress_onboarding_save_credentials'),
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
            'syncSequence'        => $syncSequence,
            'credentialsSaved'    => [
                'username' => $this->options->getString('api.username') !== '',
                'password' => !EncryptedString::fromString($this->options->getString('api.password'))->isEmpty(),
                'clientId' => trim($this->options->getString('api.client_id')) !== '',
            ],
            'settingsSnapshot'    => $this->settingsSnapshotForLocalize(),
        ]);

        echo '<div class="wrap dex-ob-page dex-settings-wrap">';
        $this->renderWizard();
        echo '</div>';
    }

    /**
     * AJAX: čuva API kredencijale u bazu odmah po uspešnom testu konekcije (Korak 2).
     * Ne označava onboarding kao završen — to radi handleComplete() na Koraku 6.
     */
    public function handleSaveCredentials(): void
    {
        if (!check_ajax_referer('dexpress_onboarding_save_credentials', 'nonce', false)) {
            $this->logger->warning('[ONBOARDING] handleSaveCredentials: neispravan nonce', ['user_id' => get_current_user_id()]);
            wp_send_json_error(['message' => 'Nevažeći sigurnosni token.'], 403);
        }

        if (!current_user_can('manage_woocommerce')) {
            $this->logger->warning('[ONBOARDING] handleSaveCredentials: nedovoljne privilegije', ['user_id' => get_current_user_id()]);
            wp_send_json_error(['message' => 'Nemate dozvolu.'], 403);
        }

        $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
        $password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));
        $clientId = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));

        $hasExistingPassword = !EncryptedString::fromString($this->options->getString('api.password'))->isEmpty();

        if ($password === '' && !$hasExistingPassword) {
            wp_send_json_error(['message' => 'Lozinka ne može biti prazna.']);
        }

        $encrypted = null;
        if ($password !== '') {
            try {
                $encrypted = EncryptedString::encrypt($password)->toString();
            } catch (\RuntimeException $e) {
                $this->logger->error('[ONBOARDING] handleSaveCredentials: enkripcija neuspešna — ' . $e->getMessage());
                wp_send_json_error(['message' => 'Greška pri enkripciji lozinke: ' . $e->getMessage()]);
            }
        }

        if ($encrypted !== null) {
            $this->options->set('api.password', $encrypted);
        }

        // Match Settings → API tab: same option keys and semantics (always persist username + client id from POST).
        $this->options->set('api.username', $username);
        $this->options->set('api.client_id', $clientId);

        $prefixNorm = ShipmentCodeAllocator::normalizeShipmentPrefix(sanitize_text_field(wp_unslash($_POST['shipment_prefix'] ?? '')));
        $prefixErr  = ShipmentCodeAllocator::shipmentPrefixValidationError($prefixNorm);
        if ($prefixErr !== null) {
            wp_send_json_error(['message' => $prefixErr]);
        }
        $this->options->set('shipment.prefix', $prefixNorm);

        $rangeStart = (int) ($_POST['shipment_range_start'] ?? 0);
        $rangeEnd   = (int) ($_POST['shipment_range_end'] ?? 0);

        if ($rangeStart < 1 || $rangeEnd < 1) {
            wp_send_json_error([
                'message' => __('Numerički opseg mora imati pozitivne vrednosti za „Od“ i „Do“.', 'dexpress-woocommerce'),
            ]);
        }

        if ($rangeEnd < $rangeStart) {
            wp_send_json_error([
                'message' => __('Opseg kodova: vrednost „Do“ mora biti veća ili jednaka „Od“.', 'dexpress-woocommerce'),
            ]);
        }

        $this->options->set('shipment.range_start', $rangeStart);
        $this->options->set('shipment.range_end', $rangeEnd);

        $saved = $this->options->save();
        $this->codeAllocator->evaluateRangeUsageAfterSettingsSaved();

        $this->logger->info('[ONBOARDING] handleSaveCredentials: kredencijali sačuvani', [
            'user_id'  => get_current_user_id(),
            'username' => $username !== '' ? $username : '(empty)',
            'saved'    => $saved,
        ]);

        $clientIdInDb = trim($this->options->getString('api.client_id')) !== '';

        wp_send_json_success([
            'message'            => __('Podešavanja su sačuvana.', 'dexpress-woocommerce'),
            'clientId'           => $clientIdInDb,
            'clientIdInDb'       => $clientIdInDb,
            'shipmentPrefix'     => $prefixNorm,
            'shipmentRangeStart' => $rangeStart,
            'shipmentRangeEnd'   => $rangeEnd,
            'nextFreeCode'       => $this->codeAllocator->nextFreeCodeInConfiguredRange(),
        ]);
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

    /** AJAX: dodaje izabrane D Express metode dostave u odgovarajuću WooCommerce zonu za Srbiju. */
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

        $allowed   = [DexpressShippingMethod::METHOD_ID, DexpressPackageShopShippingMethod::METHOD_ID];
        $requested = array_values(array_filter(
            array_map('sanitize_text_field', (array) wp_unslash($_POST['methods'] ?? [])),
            static fn (string $m): bool => in_array($m, $allowed, true),
        ));

        $this->logger->info('[ONBOARDING] handleCreateZone: primljeni metodi', [
            'user_id'   => get_current_user_id(),
            'requested' => $requested,
        ]);

        if (empty($requested)) {
            wp_send_json_error(['message' => __('Niste izabrali nijedan metod dostave.', 'dexpress-woocommerce')]);
        }

        try {
            $zone        = $this->findSerbiaZone();
            $zoneCreated = false;

            if ($zone === null) {
                $zone = new \WC_Shipping_Zone();
                $zone->set_zone_name(__('Srbija — D Express', 'dexpress-woocommerce'));
                $zone->add_location('RS', 'country');
                $zoneId = $zone->save();

                if (!$zoneId) {
                    $this->logger->error('[ONBOARDING] handleCreateZone: save() vratio falsy ID');
                    wp_send_json_error(['message' => __('Zona nije kreirana — WooCommerce nije vratio ID zone.', 'dexpress-woocommerce')]);
                }

                $zoneCreated = true;
                $this->logger->info('[ONBOARDING] Nova shipping zona kreirana', ['zone_id' => $zoneId]);
            }

            // Clear shipping cache before reading existing methods to avoid stale data.
            \WC_Cache_Helper::get_transient_version('shipping', true);

            ['added' => $added, 'skipped' => $skipped] = $this->attachMethodsToZone($zone, $requested);

            $this->logger->info('[ONBOARDING] Metode dostave primenjene', [
                'zone_id'      => $zone->get_id(),
                'zone_name'    => $zone->get_zone_name(),
                'zone_created' => $zoneCreated,
                'added'        => $added,
                'skipped'      => $skipped,
            ]);

            wp_send_json_success([
                'zone_name'    => $zone->get_zone_name(),
                'zone_created' => $zoneCreated,
                'added'        => $added,
                'skipped'      => $skipped,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[ONBOARDING] handleCreateZone: iznimka — ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: PHP error message */
                    __('Greška pri primeni metoda dostave: %s', 'dexpress-woocommerce'),
                    $e->getMessage()
                ),
            ]);
        }
    }

    /**
     * Pronalazi prvu WooCommerce shipping zonu koja pokriva Srbiju (country: RS).
     * Ako ne postoji, vraća null.
     */
    private function findSerbiaZone(): ?\WC_Shipping_Zone
    {
        foreach (\WC_Shipping_Zones::get_zones() as $zoneData) {
            $zone = new \WC_Shipping_Zone((int) $zoneData['id']);
            foreach ($zone->get_zone_locations() as $location) {
                if ($location->type === 'country' && $location->code === 'RS') {
                    return $zone;
                }
            }
        }

        return null;
    }

    /**
     * Dodaje tražene metode u zonu, preskačući one koji već postoje.
     *
     * @param  list<string>                              $methodIds
     * @return array{added: list<string>, skipped: list<string>}
     */
    private function attachMethodsToZone(\WC_Shipping_Zone $zone, array $methodIds): array
    {
        // Read method type IDs directly from DB to avoid WC object cache issues.
        global $wpdb;
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT method_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d",
            $zone->get_id(),
        )) ?: [];

        $added   = [];
        $skipped = [];

        foreach ($methodIds as $methodId) {
            if (in_array($methodId, $existing, true)) {
                $skipped[] = $methodId;
            } else {
                $zone->add_shipping_method($methodId);
                $added[] = $methodId;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
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

    /**
     * Canonical values for onboarding JS; mirrors the API tab on `dexpress-settings` after save/reload.
     *
     * @return array{clientIdInDb: bool, shipmentPrefix: string, shipmentRangeStart: int, shipmentRangeEnd: int}
     */
    private function settingsSnapshotForLocalize(): array
    {
        $p = ShipmentCodeAllocator::normalizeShipmentPrefix($this->options->getString('shipment.prefix', ''));
        $rsRaw = $this->options->get('shipment.range_start');
        $reRaw = $this->options->get('shipment.range_end');
        $rs    = ($rsRaw !== null && $rsRaw !== '' && (int) $rsRaw > 0) ? (int) $rsRaw : 0;
        $re    = ($reRaw !== null && $reRaw !== '' && (int) $reRaw > 0) ? (int) $reRaw : 0;

        return [
            'clientIdInDb'       => trim($this->options->getString('api.client_id')) !== '',
            'shipmentPrefix'     => strlen($p) === 2 ? $p : '',
            'shipmentRangeStart' => $rs,
            'shipmentRangeEnd'   => $re,
        ];
    }

    private function renderObPanelHeadStart(string $dashiconClass): void
    {
        ?>
        <div class="dex-ob-panel-head">
            <div class="dex-ob-panel-head__icon" aria-hidden="true">
                <span class="dashicons <?php echo esc_attr($dashiconClass); ?>"></span>
            </div>
            <div class="dex-ob-panel-head__text">
        <?php
    }

    private function renderObPanelHeadEnd(): void
    {
        ?>
            </div>
        </div>
        <?php
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
        $stepCount = count($steps);
        ?>
        <div class="dex-ob-wrap">

            <div class="dex-ob-top">
                <header class="dex-ob-top__head">
                    <div class="dex-ob-top__brand" aria-hidden="true">
                        <img class="dex-ob-top__logo"
                             src="<?php echo esc_url(DEXPRESS_PLUGIN_URL . 'assets/images/dexpress-icon.svg'); ?>"
                             width="48"
                             height="48"
                             alt=""
                             decoding="async">
                    </div>
                    <div class="dex-ob-top__intro">
                        <h1 class="dex-ob-top__title"><?php esc_html_e('Početno podešavanje', 'dexpress-woocommerce'); ?></h1>
                        <p class="dex-ob-top__lead">
                            <?php esc_html_e('Povežite nalog i podesite pošiljke u nekoliko koraka.', 'dexpress-woocommerce'); ?>
                        </p>
                        <p class="dex-ob-top__lead dex-ob-top__lead--muted">
                            <?php esc_html_e('Isto kao u Podešavanjima — korake možete i preskočiti.', 'dexpress-woocommerce'); ?>
                        </p>
                    </div>
                    <div class="dex-ob-top__badge" id="dex-ob-step-badge" aria-live="polite">
                        <?php echo esc_html(sprintf('%d / %d', 1, $stepCount)); ?>
                    </div>
                </header>
                <div class="dex-ob-top__rail">
                    <nav class="dex-stepper dex-ob-steps-nav" role="list" aria-label="<?php esc_attr_e('Koraci podešavanja', 'dexpress-woocommerce'); ?>">
                        <?php foreach ($steps as $num => $label) : ?>
                            <div class="dex-stepper__step dex-ob-step-dot<?php echo $num === 1 ? ' is-active dex-stepper__step--active' : ''; ?>"
                                 data-step="<?php echo (int) $num; ?>"
                                 role="listitem"
                                 <?php if ($num === 1) : ?>aria-current="step"<?php endif; ?>
                                 aria-label="<?php echo esc_attr(sprintf('%d. %s', $num, $label)); ?>">
                                <span class="dex-stepper__num dex-ob-step-num"><?php echo (int) $num; ?></span>
                                <span class="dex-stepper__label dex-ob-step-label"><?php echo esc_html($label); ?></span>
                            </div>
                            <?php if ($num < $stepCount) : ?>
                                <span class="dex-stepper__line" aria-hidden="true"></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>

            <div class="dex-card dex-ob-surface">

                <?php $this->renderPanel1(); ?>
                <?php $this->renderPanel2(); ?>
                <?php $this->renderPanel3(); ?>
                <?php $this->renderPanel4(); ?>
                <?php $this->renderPanel5(); ?>
                <?php $this->renderPanel6(); ?>

            </div>
        </div>
        <?php
    }

    private function renderPanel1(): void
    {
        ?>
        <div class="dex-ob-panel dex-card__body" id="dex-ob-panel-1">
            <?php $this->renderObPanelHeadStart('dashicons-admin-plugins'); ?>
            <h2 class="dex-ob-panel-head__title"><?php esc_html_e('Dobrodošao u D Express WooCommerce integraciju!', 'dexpress-woocommerce'); ?></h2>
            <p class="dex-ob-panel-head__desc"><?php esc_html_e('Za nekoliko minuta imate D Express u prodavnici: pošiljke iz narudžbina, praćenje statusa, kućna dostava i paketomati, obaveštenja kupcima.', 'dexpress-woocommerce'); ?></p>
            <?php $this->renderObPanelHeadEnd(); ?>

            <div class="dex-ob-panel__body">
            <ul class="dex-ob-feature-list">
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Kreiranje pošiljke iz narudžbine — bez duplog kucanja adrese', 'dexpress-woocommerce'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Kupac vidi status dostave u porudžbini', 'dexpress-woocommerce'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('Kućna dostava i preuzimanje na paketomatu u istoj korpi', 'dexpress-woocommerce'); ?>
                </li>
                <li>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e('E-pošta kupcu kada se status pošiljke promeni', 'dexpress-woocommerce'); ?>
                </li>
            </ul>
            </div>

            <div class="dex-card__footer dex-ob-nav">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dexpress')); ?>"
                   class="dex-btn dex-btn--ghost dex-ob-skip-all">
                    <?php esc_html_e('Preskoči podešavanje', 'dexpress-woocommerce'); ?>
                </a>
                <button type="button" class="dex-btn dex-btn--primary dex-ob-next" data-step="1">
                    <?php esc_html_e('Počni podešavanje', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel2(): void
    {
        $savedUsername   = esc_attr($this->options->getString('api.username'));
        $savedClientId   = esc_attr($this->options->getString('api.client_id'));
        $hasPassword     = !EncryptedString::fromString($this->options->getString('api.password'))->isEmpty();
        $normPrefix = ShipmentCodeAllocator::normalizeShipmentPrefix($this->options->getString('shipment.prefix', ''));
        $savedPrefix = esc_attr(strlen($normPrefix) === 2 ? $normPrefix : '');
        $rsRaw = $this->options->get('shipment.range_start');
        $reRaw = $this->options->get('shipment.range_end');
        $savedRangeStart = ($rsRaw !== null && $rsRaw !== '' && (int) $rsRaw > 0) ? (int) $rsRaw : '';
        $savedRangeEnd   = ($reRaw !== null && $reRaw !== '' && (int) $reRaw > 0) ? (int) $reRaw : '';
        ?>
        <div class="dex-ob-panel dex-card__body" id="dex-ob-panel-2" hidden>
            <?php $this->renderObPanelHeadStart('dashicons-lock'); ?>
            <h2 class="dex-ob-panel-head__title"><?php esc_html_e('Povezivanje naloga', 'dexpress-woocommerce'); ?></h2>
            <p class="dex-ob-panel-head__desc">
                <?php esc_html_e('Unesite podatke koje ste dobili od D Express-a. Isto kao na stranici Podešavanja — sve što ovde sačuvate odmah se pojavljuje i tamo.', 'dexpress-woocommerce'); ?>
            </p>
            <?php $this->renderObPanelHeadEnd(); ?>

            <div class="dex-ob-panel__body">
                <div class="dex-card dex-ob-subcard">
                    <div class="dex-card__header">
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <h3 class="dex-card__title"><?php esc_html_e('API pristup', 'dexpress-woocommerce'); ?></h3>
                    </div>
                    <div class="dex-card__body">
                        <table class="form-table dex-ob-form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="dex-ob-api-username"><?php esc_html_e('Korisničko ime', 'dexpress-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="dex-ob-api-username"
                                               class="regular-text"
                                               value="<?php echo $savedUsername; ?>"
                                               autocomplete="username">
                                        <p class="description">
                                            <?php esc_html_e('API korisničko ime koje vam je dodelio D Express.', 'dexpress-woocommerce'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="dex-ob-api-password">
                                            <?php esc_html_e('Lozinka', 'dexpress-woocommerce'); ?>
                                            <?php if (!$hasPassword) : ?>
                                                <span class="description">(<span class="dex-field__required">*</span>)</span>
                                            <?php endif; ?>
                                        </label>
                                    </th>
                                    <td>
                                        <?php if ($hasPassword) : ?>
                                            <div class="dex-pw-saved" id="dex-ob-pw-saved">
                                                <span class="dex-pw-saved__dots" aria-hidden="true">••••••••</span>
                                                <span class="dex-pw-saved__label">
                                                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                                    <?php esc_html_e('Lozinka je već sačuvana', 'dexpress-woocommerce'); ?>
                                                </span>
                                                <button type="button" id="dex-ob-pw-change" class="dex-btn dex-btn--secondary dex-btn--sm">
                                                    <?php esc_html_e('Promeni lozinku', 'dexpress-woocommerce'); ?>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        <div id="dex-ob-pw-input-wrap"<?php echo $hasPassword ? ' hidden' : ''; ?>>
                                            <div class="dex-pw-wrap">
                                                <input type="password"
                                                       id="dex-ob-api-password"
                                                       class="regular-text"
                                                       value=""
                                                       autocomplete="new-password">
                                                <button type="button"
                                                        class="dex-btn dex-btn--secondary dex-btn--sm dex-ob-toggle-pass"
                                                        data-target="dex-ob-api-password"
                                                        aria-pressed="false"
                                                        title="<?php esc_attr_e('Prikaži / sakrij lozinku', 'dexpress-woocommerce'); ?>">
                                                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                                    <span class="screen-reader-text"><?php esc_html_e('Prikaži lozinku', 'dexpress-woocommerce'); ?></span>
                                                </button>
                                            </div>
                                            <p class="description">
                                                <?php if ($hasPassword) : ?>
                                                    <?php esc_html_e('Unesite novu lozinku. Ostavite prazno da zadržite trenutnu.', 'dexpress-woocommerce'); ?>
                                                <?php else : ?>
                                                    <?php esc_html_e('Unesite API lozinku koju vam je dodelio D Express.', 'dexpress-woocommerce'); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="dex-ob-api-client-id"><?php esc_html_e('Client ID (CClientID)', 'dexpress-woocommerce'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="dex-ob-api-client-id"
                                               class="regular-text"
                                               value="<?php echo $savedClientId; ?>"
                                               autocomplete="off">
                                        <p class="description">
                                            <?php esc_html_e('Vaš D Express klijentski ID (npr. UKXXXXX). Dodeljuje ga D Express.', 'dexpress-woocommerce'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr class="dex-ob-form-table__actions">
                                    <th scope="row"></th>
                                    <td>
                                        <button type="button" class="dex-btn dex-btn--secondary" id="dex-ob-test-connection">
                                            <?php esc_html_e('Proveri povezivanje', 'dexpress-woocommerce'); ?>
                                        </button>
                                        <span class="dex-inline-result" id="dex-ob-connection-result" aria-live="polite"></span>
                                        <p class="description dex-ob-form-table__action-hint">
                                            <?php esc_html_e('Kliknite nakon što ste uneli korisničko ime, lozinku i Client ID. Podaci se tada upisuju u podešavanja (isti kao na stranici Podešavanja).', 'dexpress-woocommerce'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="dex-card dex-ob-subcard dex-card--highlight">
                    <div class="dex-card__header">
                        <span class="dashicons dashicons-tag" aria-hidden="true"></span>
                        <h3 class="dex-card__title"><?php esc_html_e('Raspon kodova pošiljki', 'dexpress-woocommerce'); ?></h3>
                    </div>
                    <div class="dex-card__body">
                        <p class="dex-settings-section__lead dex-ob-section-intro">
                            <?php esc_html_e('D Express vam dodeljuje dvoslovni prefiks i numerički opseg — svaki paket dobija jedinstveni kod iz tog opsega. Kad se opseg popuni, pozovite D Express da vam povećaju vrednost „Do".', 'dexpress-woocommerce'); ?>
                        </p>
                        <table class="form-table dex-ob-form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="dex-ob-shipment-prefix">
                                            <?php esc_html_e('Prefiks pošiljke', 'dexpress-woocommerce'); ?>
                                            <span class="dex-field__required">*</span>
                                        </label>
                                    </th>
                                    <td>
                                        <input type="text"
                                               id="dex-ob-shipment-prefix"
                                               class="regular-text dex-input--code"
                                               maxlength="2"
                                               inputmode="text"
                                               autocomplete="off"
                                               spellcheck="false"
                                               value="<?php echo $savedPrefix; ?>">
                                        <p class="description">
                                            <?php esc_html_e('2 velika slova koja vam je dodelio D Express (npr. TT za test).', 'dexpress-woocommerce'); ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <span class="dex-ob-form-label"><?php esc_html_e('Numerički opseg', 'dexpress-woocommerce'); ?></span>
                                    </th>
                                    <td>
                                        <div class="dex-range-row">
                                            <div class="dex-range-item">
                                                <label class="dex-range-item__label" for="dex-ob-shipment-range-start"><?php esc_html_e('Od', 'dexpress-woocommerce'); ?></label>
                                                <input type="number"
                                                       id="dex-ob-shipment-range-start"
                                                       class="dex-input--number"
                                                       min="1"
                                                       value="<?php echo $savedRangeStart !== '' ? esc_attr((string) $savedRangeStart) : ''; ?>">
                                            </div>
                                            <span class="dex-range-row__sep" aria-hidden="true">—</span>
                                            <div class="dex-range-item">
                                                <label class="dex-range-item__label" for="dex-ob-shipment-range-end"><?php esc_html_e('Do', 'dexpress-woocommerce'); ?></label>
                                                <input type="number"
                                                       id="dex-ob-shipment-range-end"
                                                       class="dex-input--number"
                                                       min="1"
                                                       value="<?php echo $savedRangeEnd !== '' ? esc_attr((string) $savedRangeEnd) : ''; ?>">
                                            </div>
                                        </div>
                                        <p class="description">
                                            <?php esc_html_e('Opseg koji vam je dodelio D Express (npr. Od 83330 Do 83430 = 101 kod). Kad ponestane, kontaktirajte D Express za povećanje „Do".', 'dexpress-woocommerce'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <?php
                        $previewVisible = ($savedPrefix !== '' && $savedRangeStart !== '' && $savedRangeEnd !== '');
                        ?>
                        <div id="dex-ob-code-preview" class="dex-code-preview<?php echo $previewVisible ? '' : ' is-hidden'; ?>">
                            <span class="dex-code-preview__label"><?php esc_html_e('Primer koda:', 'dexpress-woocommerce'); ?></span>
                            <code id="dex-ob-code-preview-first" class="dex-code-preview__code"></code>
                            <span class="dex-code-preview__sep" aria-hidden="true">→</span>
                            <code id="dex-ob-code-preview-last" class="dex-code-preview__code"></code>
                            <span class="dex-code-preview__total" id="dex-ob-code-preview-total"></span>
                        </div>
                        <div id="dex-ob-next-code-row" class="dex-next-code-row" hidden>
                            <span class="dex-next-code-row__label"><?php esc_html_e('Sledeći slobodni kod:', 'dexpress-woocommerce'); ?></span>
                            <code class="dex-next-code-row__code" id="dex-ob-next-code"></code>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dex-card__footer dex-ob-nav">
                <button type="button" class="dex-btn dex-btn--secondary dex-ob-back" data-step="2">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button type="button" class="dex-btn dex-btn--ghost dex-ob-skip" data-step="2">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button type="button" class="dex-btn dex-btn--primary dex-ob-next" data-step="2" disabled>
                    <?php esc_html_e('Sačuvaj i nastavi', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel3(): void
    {
        ?>
        <div class="dex-ob-panel dex-card__body" id="dex-ob-panel-3" hidden>
            <?php $this->renderObPanelHeadStart('dashicons-update'); ?>
            <h2 class="dex-ob-panel-head__title"><?php esc_html_e('Priprema adresa i lokacija', 'dexpress-woocommerce'); ?></h2>
            <p class="dex-ob-panel-head__desc">
                <?php esc_html_e('Da bi kupac brzo našao grad i ulicu, a da bi paketomat i kurir bili tačni, plugin jednom učita listu mesta od D Express-a. To ubrzava kreiranje pošiljke i smanjuje greške na adresi.', 'dexpress-woocommerce'); ?>
            </p>
            <p class="dex-ob-panel-head__desc dex-ob-panel-head__desc--muted">
                <?php esc_html_e('Korak može da potraje jedan do tri minuta — zavisno od servera. Možete ga i preskočiti i pokrenuti kasnije iz podešavanja.', 'dexpress-woocommerce'); ?>
            </p>
            <?php $this->renderObPanelHeadEnd(); ?>

            <div class="dex-ob-panel__body">
            <div class="dex-ob-footer-actions">
                <button type="button" class="dex-btn dex-btn--secondary" id="dex-ob-sync">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e('Ažuriraj podatke o mestima', 'dexpress-woocommerce'); ?>
                </button>
                <span class="spinner" id="dex-ob-sync-spinner"></span>
            </div>

            <div class="dex-ob-sync-feed" aria-label="<?php esc_attr_e('Tok priprema podataka', 'dexpress-woocommerce'); ?>">
                <ul class="dex-ob-sync-log" id="dex-ob-sync-log" aria-live="polite"></ul>
            </div>

            <div class="dex-inline-result dex-ob-block-result" id="dex-ob-sync-result" aria-live="polite"></div>
            </div>

            <div class="dex-card__footer dex-ob-nav">
                <button type="button" class="dex-btn dex-btn--secondary dex-ob-back" data-step="3">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button type="button" class="dex-btn dex-btn--ghost dex-ob-skip" data-step="3">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button type="button" class="dex-btn dex-btn--primary dex-ob-next" data-step="3" disabled>
                    <?php esc_html_e('Sačuvaj i nastavi', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel4(): void
    {
        ?>
        <div class="dex-ob-panel dex-card__body" id="dex-ob-panel-4" hidden>
            <?php $this->renderObPanelHeadStart('dashicons-location'); ?>
            <h2 class="dex-ob-panel-head__title"><?php esc_html_e('Adresa sa koje šaljete pakete', 'dexpress-woocommerce'); ?></h2>
            <p class="dex-ob-panel-head__desc">
                <?php esc_html_e('Ovo je „pošiljalac“ na nalepnici — adresa skladišta ili radnje odakle kurir preuzima paket. Možete kasnije dodati još lokacija u podešavanjima.', 'dexpress-woocommerce'); ?>
            </p>
            <?php $this->renderObPanelHeadEnd(); ?>

            <div class="dex-ob-panel__body">
            <table class="form-table dex-ob-form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-name"><?php esc_html_e('Naziv lokacije', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="dex-ob-loc-name" class="regular-text" placeholder="<?php esc_attr_e('npr. Skladište Novi Sad', 'dexpress-woocommerce'); ?>">
                            <p class="description"><?php esc_html_e('Kratak naziv samo za vas u adminu (kupac ga ne vidi).', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-town-name"><?php esc_html_e('Grad', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label>
                        </th>
                        <td>
                            <div class="dex-ob-autocomplete-wrap">
                                <input type="text" id="dex-ob-loc-town-name" class="regular-text"
                                       placeholder="<?php esc_attr_e('Kucajte grad…', 'dexpress-woocommerce'); ?>"
                                       autocomplete="off">
                                <input type="hidden" id="dex-ob-loc-town-id">
                                <span class="spinner" id="dex-ob-town-spinner"></span>
                                <div class="dex-dropdown" id="dex-ob-town-suggestions" role="listbox"></div>
                            </div>
                            <p class="description"><?php esc_html_e('Izaberite grad iz liste (najmanje dva slova). Tako dobijamo tačnu adresu za kurira.', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-street-name"><?php esc_html_e('Ulica', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label>
                        </th>
                        <td>
                            <div class="dex-ob-autocomplete-wrap">
                                <input type="text" id="dex-ob-loc-street-name" class="regular-text"
                                       placeholder="<?php esc_attr_e('Prvo izaberite grad…', 'dexpress-woocommerce'); ?>"
                                       autocomplete="off"
                                       disabled>
                                <input type="hidden" id="dex-ob-loc-street-id">
                                <span class="spinner" id="dex-ob-street-spinner"></span>
                                <div class="dex-dropdown" id="dex-ob-street-suggestions" role="listbox"></div>
                            </div>
                            <p class="description"><?php esc_html_e('Prvo izaberite grad, zatim ulicu iz liste. Radi i bez npr. š i ć u kucanju.', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-number"><?php esc_html_e('Broj', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="dex-ob-loc-number" class="small-text" placeholder="<?php esc_attr_e('npr. 12a', 'dexpress-woocommerce'); ?>">
                            <p class="description"><?php esc_html_e('Kućni broj na fasadi — kako piše na pošti.', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-addr-desc"><?php esc_html_e('Dodatak uz adresu', 'dexpress-woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="dex-ob-loc-addr-desc" class="regular-text"
                                   placeholder="<?php esc_attr_e('npr. 2. sprat, interfon', 'dexpress-woocommerce'); ?>">
                            <p class="description"><?php esc_html_e('Neobavezno. Pomaže kuriru da brže nađe ulaz.', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-contact-name"><?php esc_html_e('Kontakt osoba', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="dex-ob-loc-contact-name" class="regular-text">
                            <p class="description"><?php esc_html_e('Osoba koja prima kurira na ovoj adresi.', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-contact-phone"><?php esc_html_e('Telefon', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label>
                        </th>
                        <td>
                            <input type="tel" id="dex-ob-loc-contact-phone" class="regular-text" placeholder="381641234567">
                            <p id="dex-ob-phone-error" class="dex-ob-field-msg" aria-live="polite"></p>
                            <p class="description"><?php esc_html_e('Mobilni u formatu 381… (možete uneti i 06… ili +381 — ispravićemo u jedan format).', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dex-ob-loc-bank-account"><?php esc_html_e('Tekući račun za otkupninu', 'dexpress-woocommerce'); ?> <span class="dex-field__required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="dex-ob-loc-bank-account" class="regular-text" placeholder="<?php esc_attr_e('npr. 160-123456789-01', 'dexpress-woocommerce'); ?>">
                            <p class="description"><?php esc_html_e('Račun na koji D Express uplaćuje otkupninu za ovu lokaciju.', 'dexpress-woocommerce'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="dex-ob-footer-actions">
                <button type="button" class="dex-btn dex-btn--secondary" id="dex-ob-save-location">
                    <?php esc_html_e('Sačuvaj lokaciju', 'dexpress-woocommerce'); ?>
                </button>
            </div>
            <div class="dex-inline-result dex-ob-block-result" id="dex-ob-location-result" aria-live="polite"></div>
            </div>

            <div class="dex-card__footer dex-ob-nav">
                <button type="button" class="dex-btn dex-btn--secondary dex-ob-back" data-step="4">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button type="button" class="dex-btn dex-btn--ghost dex-ob-skip" data-step="4">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button type="button" class="dex-btn dex-btn--primary dex-ob-next" data-step="4" disabled>
                    <?php esc_html_e('Sačuvaj i nastavi', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel5(): void
    {
        ?>
        <div class="dex-ob-panel dex-card__body" id="dex-ob-panel-5" hidden>
            <?php $this->renderObPanelHeadStart('dashicons-car'); ?>
            <h2 class="dex-ob-panel-head__title"><?php esc_html_e('Dostava u korpi', 'dexpress-woocommerce'); ?></h2>
            <p class="dex-ob-panel-head__desc">
                <?php esc_html_e('Izaberite kako kupac može da primi paket: kuća ili paketomat / paket shop. Izbor možete kasnije menjati u WooCommerce → Dostava.', 'dexpress-woocommerce'); ?>
            </p>
            <?php $this->renderObPanelHeadEnd(); ?>

            <div class="dex-ob-panel__body">
            <div class="dex-ob-method-stack" role="group" aria-labelledby="dex-ob-methods-legend">
                <p id="dex-ob-methods-legend" class="dex-field__label"><?php esc_html_e('Metode dostave:', 'dexpress-woocommerce'); ?></p>

                <div class="dex-check-row dex-ob-method-row">
                    <input type="checkbox"
                           class="dex-check-row__checkbox"
                           id="dex-ob-method-standard"
                           value="<?php echo esc_attr(DexpressShippingMethod::METHOD_ID); ?>">
                    <div class="dex-check-row__body">
                        <label class="dex-check-row__label" for="dex-ob-method-standard"><?php esc_html_e('D Express — kućna dostava', 'dexpress-woocommerce'); ?></label>
                        <p class="dex-check-row__desc"><?php esc_html_e('Standardna kurirska dostava direktno na adresu kupca.', 'dexpress-woocommerce'); ?></p>
                    </div>
                </div>

                <div class="dex-check-row dex-ob-method-row">
                    <input type="checkbox"
                           class="dex-check-row__checkbox"
                           id="dex-ob-method-package-shop"
                           value="<?php echo esc_attr(DexpressPackageShopShippingMethod::METHOD_ID); ?>">
                    <div class="dex-check-row__body">
                        <label class="dex-check-row__label" for="dex-ob-method-package-shop"><?php esc_html_e('D Express — paketomat / paket shop', 'dexpress-woocommerce'); ?></label>
                        <p class="dex-check-row__desc"><?php esc_html_e('Preuzimanje na paketomatu ili u paket shopu po izboru kupca.', 'dexpress-woocommerce'); ?></p>
                    </div>
                </div>
            </div>

            <p id="dex-ob-method-validation" class="dex-ob-field-msg" aria-live="polite"></p>

            <div class="dex-ob-footer-actions">
                <button type="button" class="dex-btn dex-btn--secondary" id="dex-ob-create-zone">
                    <?php esc_html_e('Primeni metode dostave', 'dexpress-woocommerce'); ?>
                </button>
            </div>
            <div class="dex-inline-result dex-ob-block-result dex-ob-zone-result" id="dex-ob-zone-result" aria-live="polite"></div>
            </div>

            <div class="dex-card__footer dex-ob-nav">
                <button type="button" class="dex-btn dex-btn--secondary dex-ob-back" data-step="5">&larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?></button>
                <button type="button" class="dex-btn dex-btn--ghost dex-ob-skip" data-step="5">
                    <?php esc_html_e('Preskoči', 'dexpress-woocommerce'); ?>
                </button>
                <button type="button" class="dex-btn dex-btn--primary dex-ob-next" data-step="5" disabled>
                    <?php esc_html_e('Sačuvaj i nastavi', 'dexpress-woocommerce'); ?> &rarr;
                </button>
            </div>
        </div>
        <?php
    }

    private function renderPanel6(): void
    {
        $clientIdInDb = trim($this->options->getString('api.client_id')) !== '';

        $urlDashboard  = admin_url('admin.php?page=dexpress');
        $urlShipments  = admin_url('admin.php?page=dexpress-shipments');
        $urlProfiles   = admin_url('admin.php?page=' . PackageProfilesPage::PAGE_SLUG);
        $urlSettings   = admin_url('admin.php?page=dexpress-settings');
        $urlPayments   = admin_url('admin.php?page=dexpress-payments');
        $urlBulk       = admin_url('admin.php?page=dexpress-shipments');
        ?>
        <div class="dex-ob-panel dex-card__body dex-ob-panel--finish-step" id="dex-ob-panel-6" hidden>
            <div class="dex-ob-finish-screen">
                <div class="dex-ob-finish-screen__inner">
                    <div class="dex-notice dex-notice--warning"
                         id="dex-ob-clientid-warning"
                         <?php if ($clientIdInDb) { echo 'hidden'; } ?>>
                        <div class="dex-notice__content">
                            <p class="dex-notice__title"><?php esc_html_e('Još jedan korak za kreiranje pošiljki', 'dexpress-woocommerce'); ?></p>
                            <p class="dex-notice__body">
                                <?php printf(
                                    /* translators: %s: link back to step 2 */
                                    esc_html__('Nije upisan Client ID koji vam je dodelio D Express — bez njega ne možete da kreirate pošiljku. %s', 'dexpress-woocommerce'),
                                    '<a href="#" class="dex-ob-back" data-step="2">' . esc_html__('Vratite se na korak „Povezivanje naloga“', 'dexpress-woocommerce') . '</a>',
                                ); ?>
                            </p>
                        </div>
                    </div>

                    <div class="dex-ob-finish-hero">
                        <div class="dex-ob-finish-hero__badge" aria-hidden="true">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </div>
                        <h2 class="dex-ob-finish-hero__title"><?php esc_html_e('Završite podešavanje', 'dexpress-woocommerce'); ?></h2>
                        <p class="dex-ob-finish-hero__lead">
                            <?php esc_html_e('Kliknite na zeleno dugme ispod, „Završi podešavanje“. Time završavate vodič — sistem vas neće više automatski vraćati ovde. D Express i dalje pronalazite u meniju sa leve strane.', 'dexpress-woocommerce'); ?>
                        </p>
                    </div>

                    <div class="dex-ob-finish" id="dex-ob-finish-root">
                        <div class="dex-ob-finish__outcome" id="dex-ob-finish-outcome" hidden>
                            <div class="dex-ob-finish-saving" id="dex-ob-finish-saving" hidden>
                                <span class="dex-ob-finish-saving__pulse" aria-hidden="true"></span>
                                <span class="dex-ob-finish-saving__text"><?php esc_html_e('Čuvanje…', 'dexpress-woocommerce'); ?></span>
                            </div>

                            <div class="dex-inline-result dex-ob-block-result dex-ob-finish__result"
                                 id="dex-ob-complete-result"
                                 aria-live="assertive"
                                 hidden></div>

                            <div class="dex-ob-finish-saved"
                                 id="dex-ob-finish-saved"
                                 role="status"
                                 hidden>
                                <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                <span class="dex-ob-finish-saved__label"><?php esc_html_e('Sve je sačuvano.', 'dexpress-woocommerce'); ?></span>
                            </div>

                            <div class="dex-ob-finish-links" id="dex-ob-finish-links" hidden>
                                <div class="dex-ob-finish-links__grid" role="navigation" aria-label="<?php esc_attr_e('D Express admin stranice', 'dexpress-woocommerce'); ?>">
                                    <a class="dex-ob-finish-link-card dex-ob-finish-link-card--primary" href="<?php echo esc_url($urlDashboard); ?>">
                                        <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                                        <span class="dex-ob-finish-link-card__title"><?php esc_html_e('Pregled', 'dexpress-woocommerce'); ?></span>
                                        <span class="dex-ob-finish-link-card__desc"><?php esc_html_e('Stanje naloga i pregled aktivnosti', 'dexpress-woocommerce'); ?></span>
                                    </a>
                                    <a class="dex-ob-finish-link-card" href="<?php echo esc_url($urlShipments); ?>">
                                        <span class="dashicons dashicons-archive" aria-hidden="true"></span>
                                        <span class="dex-ob-finish-link-card__title"><?php esc_html_e('Pošiljke', 'dexpress-woocommerce'); ?></span>
                                        <span class="dex-ob-finish-link-card__desc"><?php esc_html_e('Kreirane pošiljke i statusi', 'dexpress-woocommerce'); ?></span>
                                    </a>
                                    <a class="dex-ob-finish-link-card" href="<?php echo esc_url($urlProfiles); ?>">
                                        <span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
                                        <span class="dex-ob-finish-link-card__title"><?php esc_html_e('Profili paketa', 'dexpress-woocommerce'); ?></span>
                                        <span class="dex-ob-finish-link-card__desc"><?php esc_html_e('Dimenzije i pakovanje', 'dexpress-woocommerce'); ?></span>
                                    </a>
                                    <a class="dex-ob-finish-link-card" href="<?php echo esc_url($urlSettings); ?>">
                                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                                        <span class="dex-ob-finish-link-card__title"><?php esc_html_e('Podešavanja', 'dexpress-woocommerce'); ?></span>
                                        <span class="dex-ob-finish-link-card__desc"><?php esc_html_e('API, lokacije, obaveštenja', 'dexpress-woocommerce'); ?></span>
                                    </a>
                                    <a class="dex-ob-finish-link-card" href="<?php echo esc_url($urlPayments); ?>">
                                        <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                                        <span class="dex-ob-finish-link-card__title"><?php esc_html_e('Plaćanja', 'dexpress-woocommerce'); ?></span>
                                        <span class="dex-ob-finish-link-card__desc"><?php esc_html_e('Načini plaćanja i COD', 'dexpress-woocommerce'); ?></span>
                                    </a>
                                    <a class="dex-ob-finish-link-card" href="<?php echo esc_url($urlBulk); ?>">
                                        <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                                        <span class="dex-ob-finish-link-card__title"><?php esc_html_e('Masovna pošiljka', 'dexpress-woocommerce'); ?></span>
                                        <span class="dex-ob-finish-link-card__desc"><?php esc_html_e('Uvoz više narudžbina', 'dexpress-woocommerce'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="dex-ob-finish__pending" id="dex-ob-finish-pending">
                            <div class="dex-ob-finish__cta">
                                <button type="button" class="dex-btn dex-btn--primary dex-btn--lg" id="dex-ob-complete">
                                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                    <?php esc_html_e('Završi podešavanje', 'dexpress-woocommerce'); ?>
                                </button>
                            </div>
                            <button type="button" class="dex-btn dex-btn--ghost dex-ob-finish-back dex-ob-back" data-step="5">
                                &larr; <?php esc_html_e('Nazad', 'dexpress-woocommerce'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
