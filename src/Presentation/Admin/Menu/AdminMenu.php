<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Menu;

use S7codedesign\DExpress\Presentation\Admin\Pages\DashboardPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\DiagnosticsPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\SettingsPage;
use S7codedesign\DExpress\Presentation\Admin\Pages\ShipmentsPage;

/**
 * Glavni admin meni D Express (top-level + SVG ikona), sa podstranicama.
 */
final class AdminMenu
{
    /** Roditeljski slug menija — isti kao prva podstavka da nema duplog unosa. */
    private const MENU_SLUG = 'dexpress';

    /** Svi `admin.php?page=…` slugovi za koje učitavamo zajednički admin CSS/JS. */
    private const ADMIN_PAGE_SLUGS = [
        self::MENU_SLUG,
        'dexpress-shipments',
        'dexpress-settings',
        'dexpress-diagnostics',
    ];

    public function __construct(
        private readonly DashboardPage $dashboardPage,
        private readonly ShipmentsPage $shipmentsPage,
        private readonly SettingsPage $settingsPage,
        private readonly DiagnosticsPage $diagnosticsPage,
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuItems'], 54);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_head', [$this, 'printIconStyles']);
        add_filter('plugin_action_links_' . DEXPRESS_PLUGIN_BASENAME, [$this, 'addSettingsLink']);
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if (!$this->isDExpressAdminScreen($hookSuffix)) {
            return;
        }

        wp_enqueue_style(
            'dexpress-admin',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DEXPRESS_VERSION,
        );

        wp_enqueue_script(
            'dexpress-admin-settings',
            DEXPRESS_PLUGIN_URL . 'assets/js/admin-settings.js',
            ['jquery'],
            DEXPRESS_VERSION,
            true,
        );

        wp_localize_script('dexpress-admin-settings', 'dexpressAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'testConnection'       => wp_create_nonce('dexpress_test_connection'),
                'saveSenderLocation'   => wp_create_nonce('dexpress_save_sender_location'),
                'deleteSenderLocation' => wp_create_nonce('dexpress_delete_sender_location'),
                'setDefaultLocation'   => wp_create_nonce('dexpress_set_default_location'),
                'manualSync'           => wp_create_nonce('dexpress_manual_sync'),
                'searchTowns'          => wp_create_nonce('dexpress_admin_search_towns'),
                'searchStreets'        => wp_create_nonce('dexpress_admin_search_streets'),
            ],
            'strings' => [
                'confirmDelete' => __('Da li ste sigurni da želite da obrišete ovu lokaciju?', 'dexpress-woocommerce'),
                'confirmSync'   => __('Da li ste sigurni? Sinhronizacija može potrajati nekoliko minuta.', 'dexpress-woocommerce'),
                'saving'        => __('Čuvanje...', 'dexpress-woocommerce'),
                'testing'       => __('Testiranje...', 'dexpress-woocommerce'),
                'syncing'       => __('Pokretanje...', 'dexpress-woocommerce'),
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

        return $page !== '' && in_array($page, self::ADMIN_PAGE_SLUGS, true);
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

        add_submenu_page(
            self::MENU_SLUG,
            __('D Express — pošiljke', 'dexpress-woocommerce'),
            __('Pošiljke', 'dexpress-woocommerce'),
            'manage_woocommerce',
            'dexpress-shipments',
            [$this->shipmentsPage, 'render'],
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
            'dexpress-diagnostics',
            [$this->diagnosticsPage, 'render'],
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
