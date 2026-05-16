<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Menu;

use S7codedesign\DExpress\Application\Sync\SyncCatalogOrder;
use S7codedesign\DExpress\Presentation\Admin\Pages\DashboardPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\DiagnosticsPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\OnboardingPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\PackageProfilesPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\PaymentsPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\SettingsPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\ShipmentsPage;

/**
 * Glavni admin meni D Express (top-level + SVG ikona), sa podstranicama.
 */
final class AdminMenu
{
    /** Roditeljski slug menija — isti kao prva podstavka da nema duplog unosa. */
    private const MENU_SLUG = 'dexpress';

    /**
     * Svi `admin.php?page=…` slugovi za koje učitavamo zajednički admin CSS/JS.
     *
     * @return list<string>
     */
    private static function adminPageSlugs(): array
    {
        return [
            self::MENU_SLUG,
            'dexpress-shipments',
            'dexpress-payments',
            'dexpress-settings',
            DiagnosticsPage::PAGE_SLUG,
            OnboardingPage::PAGE_SLUG,
            PackageProfilesPage::PAGE_SLUG,
        ];
    }

    public function __construct(
        private readonly DashboardPage       $dashboardPage,
        private readonly ShipmentsPage       $shipmentsPage,
        private readonly SettingsPage        $settingsPage,
        private readonly DiagnosticsPage     $diagnosticsPage,
        private readonly PaymentsPage        $paymentsPage,
        private readonly OnboardingPage      $onboardingPage,
        private readonly PackageProfilesPage $packageProfilesPage,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuItems'], 54);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_head', [$this, 'printIconStyles']);
        add_filter('plugin_action_links_' . DEXPRESS_PLUGIN_BASENAME, [$this, 'addSettingsLink']);
        add_action('admin_init', [$this, 'maybeRedirectToOnboarding']);
        add_action('admin_notices', [$this, 'renderOnboardingNotice']);
    }

    /**
     * Preusmerava SAMO sa WP dašborda (index.php) ili D-Express početne stranice.
     * Na svim ostalim stranicama korisnik može slobodno da se kreće — notice preuzima ulogu.
     */
    public function maybeRedirectToOnboarding(): void
    {
        if (get_option('dexpress_onboarding_complete') !== 'no') {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page    = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $pagenow = $GLOBALS['pagenow'] ?? '';

        // Ne preusmeravaj sa samog wizard-a.
        if ($page === OnboardingPage::PAGE_SLUG) {
            return;
        }

        // Odmah nakon prve aktivacije transient signalizuje redirect bez obzira na stranicu.
        if (get_transient('_dexpress_activation_redirect')) {
            delete_transient('_dexpress_activation_redirect');
            wp_safe_redirect(admin_url('admin.php?page=' . OnboardingPage::PAGE_SLUG));
            exit;
        }

        // Preusmeravaj samo sa WP admin dašborda ili D-Express početne stranice.
        $isWpDashboard  = ($pagenow === 'index.php' && $page === '');
        $isDExpressHome = ($pagenow === 'admin.php' && $page === self::MENU_SLUG);

        if (!$isWpDashboard && !$isDExpressHome) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . OnboardingPage::PAGE_SLUG));
        exit;
    }

    /**
     * Prikazuje upozoravajući notice dok onboarding nije završen.
     * Svaki korisnik može da ga trajno zatvori (user meta).
     */
    public function renderOnboardingNotice(): void
    {
        if (get_option('dexpress_onboarding_complete') !== 'no') {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (get_user_meta(get_current_user_id(), '_dexpress_onboarding_notice_dismissed', true) === '1') {
            return;
        }

        // Ne prikazuj na samoj onboarding stranici.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page === OnboardingPage::PAGE_SLUG) {
            return;
        }

        $wizardUrl   = esc_url(admin_url('admin.php?page=' . OnboardingPage::PAGE_SLUG));
        $dismissNonce = wp_create_nonce('dexpress_dismiss_onboarding_notice');

        printf(
            '<div class="notice notice-warning dexpress-onboarding-notice" id="dexpress-onboarding-notice" style="display:flex;align-items:center;gap:12px;padding:10px 16px;">
                <p style="margin:0;flex:1;">
                    <strong>D Express</strong> — %s
                    <a href="%s" class="button button-small" style="margin-left:10px;">%s</a>
                </p>
                <button type="button" class="notice-dismiss dexpress-ob-dismiss" style="position:static;flex-shrink:0;" aria-label="%s">
                    <span class="screen-reader-text">%s</span>
                </button>
            </div>
            <script>
            (function($){
                $(\'#dexpress-onboarding-notice .dexpress-ob-dismiss\').on(\'click\', function(){
                    $(\'#dexpress-onboarding-notice\').remove();
                    $.post(%s, { action: \'dexpress_dismiss_onboarding_notice\', nonce: \'%s\' });
                });
            })(jQuery);
            </script>',
            esc_html__('D-Express nije podešen. Pokrenite čarobnjak podešavanja.', 'dexpress-woocommerce'),
            $wizardUrl,
            esc_html__('Pokreni podešavanje', 'dexpress-woocommerce'),
            esc_attr__('Zatvori obaveštenje', 'dexpress-woocommerce'),
            esc_html__('Zatvori obaveštenje', 'dexpress-woocommerce'),
            wp_json_encode(admin_url('admin-ajax.php')),
            esc_js($dismissNonce),
        );
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if (!$this->isDExpressAdminScreen($hookSuffix)) {
            return;
        }

        wp_enqueue_style(
            'dex-admin',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DEXPRESS_VERSION,
        );

        if ($hookSuffix === 'toplevel_page_dexpress') {
            wp_enqueue_style(
                'dex-dashboard',
                DEXPRESS_PLUGIN_URL . 'assets/css/admin-dashboard.css',
                ['dex-admin'],
                DEXPRESS_VERSION,
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';

        if ($page === PackageProfilesPage::PAGE_SLUG) {
            wp_enqueue_style(
                'dex-package-profiles',
                DEXPRESS_PLUGIN_URL . 'assets/css/admin-package-profiles.css',
                ['dex-admin'],
                DEXPRESS_VERSION,
            );
            wp_enqueue_script(
                'dex-package-profiles',
                DEXPRESS_PLUGIN_URL . 'assets/js/admin-package-profiles.js',
                ['jquery'],
                DEXPRESS_VERSION,
                true,
            );
            wp_localize_script('dex-package-profiles', 'dexpressProfiles', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'iconUrl' => DEXPRESS_PLUGIN_URL . 'assets/images/package-box.svg',
                'nonces'  => [
                    'save'       => wp_create_nonce('dexpress_save_package_profile'),
                    'delete'     => wp_create_nonce('dexpress_delete_package_profile'),
                    'setDefault' => wp_create_nonce('dexpress_set_default_profile'),
                ],
                'i18n' => [
                    'confirmDelete' => __('Obrisati profil "%s"?', 'dexpress-woocommerce'),
                    'saving'        => __('Čuvanje...', 'dexpress-woocommerce'),
                    'saved'         => __('Sačuvano.', 'dexpress-woocommerce'),
                    'error'         => __('Greška. Pokušajte ponovo.', 'dexpress-woocommerce'),
                    'editTitle'     => __('Izmeni profil paketa', 'dexpress-woocommerce'),
                    'newTitle'      => __('Novi profil paketa', 'dexpress-woocommerce'),
                ],
            ]);
        }

        if ($page === 'dexpress-shipments') {
            wp_enqueue_style(
                'dex-shipments',
                DEXPRESS_PLUGIN_URL . 'assets/css/admin-bulk-shipment.css',
                ['dex-admin'],
                DEXPRESS_VERSION,
            );
            wp_enqueue_script(
                'dex-shipments',
                DEXPRESS_PLUGIN_URL . 'assets/js/admin-bulk-shipment.js',
                ['jquery'],
                DEXPRESS_VERSION,
                true,
            );
            // wp_localize_script is called inside ShipmentsPage::render() with handle 'dex-shipments'.
        }

        if ($page === DiagnosticsPage::PAGE_SLUG) {
            wp_enqueue_style(
                'dex-diagnostics',
                DEXPRESS_PLUGIN_URL . 'assets/css/admin-diagnostics.css',
                ['dex-admin'],
                DEXPRESS_VERSION,
            );
        }

        wp_enqueue_script(
            'dex-admin-settings',
            DEXPRESS_PLUGIN_URL . 'assets/js/admin-settings.js',
            ['jquery'],
            DEXPRESS_VERSION,
            true,
        );

        wp_localize_script('dex-admin-settings', 'dexpressAdmin', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'syncAllOrder' => SyncCatalogOrder::ALL_SEQUENCE,
            'nonces'       => [
                'testConnection'       => wp_create_nonce('dexpress_test_connection'),
                'saveSenderLocation'   => wp_create_nonce('dexpress_save_sender_location'),
                'deleteSenderLocation' => wp_create_nonce('dexpress_delete_sender_location'),
                'setDefaultLocation'   => wp_create_nonce('dexpress_set_default_location'),
                'manualSync'           => wp_create_nonce('dexpress_manual_sync'),
                'searchTowns'          => wp_create_nonce('dexpress_admin_search_towns'),
                'searchStreets'        => wp_create_nonce('dexpress_admin_search_streets'),
            ],
            'strings' => [
                'confirmDelete'   => __('Da li ste sigurni da želite da obrišete ovu lokaciju?', 'dexpress-woocommerce'),
                'confirmSync'     => __('Da li ste sigurni? Sinhronizacija može potrajati nekoliko minuta.', 'dexpress-woocommerce'),
                'saving'          => __('Čuvanje...', 'dexpress-woocommerce'),
                'testing'         => __('Testiranje...', 'dexpress-woocommerce'),
                'syncing'         => __('Pokretanje...', 'dexpress-woocommerce'),
                'syncAllRunning'  => __('Sinhronizacija šifarnika…', 'dexpress-woocommerce'),
                'syncAllDone'     => __('Svi šifarnici su uspešno osveženi.', 'dexpress-woocommerce'),
                'syncAjaxFail'    => __('Greška pri slanju zahteva.', 'dexpress-woocommerce'),
                'syncStepUnknown' => __('Nepoznat korak sinhronizacije.', 'dexpress-woocommerce'),
            ],
        ]);
    }

    private function isDExpressAdminScreen(string $hookSuffix): bool
    {
        $hook = (string) $hookSuffix;
        if (
            str_starts_with($hook, 'toplevel_page_dexpress')
            || str_starts_with($hook, 'dexpress_page_')
        ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';

        return $page !== '' && in_array($page, self::adminPageSlugs(), true);
    }

    public function addMenuItems(): void
    {
        add_menu_page(
            __('D Express', 'dexpress-woocommerce'),
            __('D Express', 'dexpress-woocommerce'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this->dashboardPage, 'render'],
            DEXPRESS_PLUGIN_URL . 'assets/images/dexpress-icon.svg',
            54,
        );

        // Isti slug kao roditelj — WordPress ne duplira prvu stavku u podmeniju.
        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — pregled', 'dexpress-woocommerce'),
            __('Pregled', 'dexpress-woocommerce'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this->dashboardPage, 'render'],
        );

        global $wpdb;
        $shipmentsTable = $wpdb->prefix . 'dexpress_shipments';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $pendingCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$shipmentsTable}` WHERE send_status = 'pending_send'");
        $shipmentsMenuLabel = __('Pošiljke', 'dexpress-woocommerce');
        if ($pendingCount > 0) {
            $shipmentsMenuLabel .= ' <span class="awaiting-mod count-' . $pendingCount . '"><span class="pending-count">' . $pendingCount . '</span></span>';
        }

        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — pošiljke', 'dexpress-woocommerce'),
            $shipmentsMenuLabel,
            'manage_woocommerce',
            'dexpress-shipments',
            [$this->shipmentsPage, 'render'],
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — profili paketa', 'dexpress-woocommerce'),
            __('Profili paketa', 'dexpress-woocommerce'),
            'manage_woocommerce',
            PackageProfilesPage::PAGE_SLUG,
            [$this->packageProfilesPage, 'render'],
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — otkupnine', 'dexpress-woocommerce'),
            __('Otkupnine', 'dexpress-woocommerce'),
            'manage_woocommerce',
            'dexpress-payments',
            [$this->paymentsPage, 'render'],
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — podešavanja', 'dexpress-woocommerce'),
            __('Podešavanja', 'dexpress-woocommerce'),
            'manage_woocommerce',
            'dexpress-settings',
            [$this->settingsPage, 'render'],
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — dijagnostika', 'dexpress-woocommerce'),
            __('Dijagnostika', 'dexpress-woocommerce'),
            'manage_woocommerce',
            DiagnosticsPage::PAGE_SLUG,
            [$this->diagnosticsPage, 'render'],
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — početno podešavanje', 'dexpress-woocommerce'),
            __('Početno podešavanje', 'dexpress-woocommerce'),
            'manage_woocommerce',
            OnboardingPage::PAGE_SLUG,
            [$this->onboardingPage, 'render'],
        );
    }

    public function printIconStyles(): void
    {
        echo '<style>
    #adminmenu #toplevel_page_dexpress .wp-menu-image img {
        padding: 5px 0 0;
        opacity: .6;
        width: 24px;
    }
    #adminmenu #toplevel_page_dexpress:hover .wp-menu-image img,
    #adminmenu #toplevel_page_dexpress.current .wp-menu-image img {
        opacity: 1;
    }
    </style>';
    }

    /** @param array<string, string> $links */
    public function addSettingsLink(array $links): array
    {
        $settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=dexpress-settings')) . '">'
            . __('Podešavanja', 'dexpress-woocommerce')
            . '</a>';

        array_unshift($links, $settingsLink);

        return $links;
    }
}
