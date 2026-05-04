<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Infrastructure\Options\EncryptedString;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;

final class SettingsPage
{
    private const PAGE_SLUG = 'dexpress-settings';

    private const TABS = [
        'api'              => 'D Express kredencijali',
        'webhook'          => 'Webhook',
        'sender_locations' => 'Lokacije pošiljaoca',
        'checkout'         => 'Checkout',
        'email'            => 'Email podešavanja',
        'logging'          => 'Logovanje',
        'sifarnici'        => 'Šifarnici',
        'simulation'       => 'Simulacija',
    ];

    public function __construct(
        private readonly OptionsRepository $options,
        private readonly WpdbSenderLocationRepository $senderLocations,
    ) {}

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nemate dozvolu za pristup ovoj stranici.', 'dexpress-woocommerce'));
        }

        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api'; // phpcs:ignore WordPress.Security.NonceVerification
        if (!array_key_exists($activeTab, self::TABS)) {
            $activeTab = 'api';
        }

        $notice = $this->getNotice();

        echo '<div class="wrap dexpress-settings-wrap">';
        echo '<img src="' . esc_url(DEXPRESS_PLUGIN_URL . 'assets/images/Dexpress-logo.jpg') . '" alt="D Express" class="dexpress-header-logo">';
        echo '<h1>' . esc_html__('D Express podešavanja', 'dexpress-woocommerce') . '</h1>';
        if ($notice) {
            $this->renderNotice($notice);
        }

        echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';
        foreach (self::TABS as $slug => $label) {
            $url      = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $slug], admin_url('admin.php'));
            $active   = $activeTab === $slug ? ' nav-tab-active' : '';
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($url),
                esc_attr($active),
                esc_html($label),
            );
        }
        echo '</nav>';

        echo '<div class="dexpress-tab-content">';
        $this->renderTab($activeTab);
        echo '</div>';

        echo '</div>';
    }

    private function renderTab(string $tab): void
    {
        $templateDir = DEXPRESS_PLUGIN_DIR . 'templates/admin/';
        $template    = $templateDir . 'tab-' . str_replace('_', '-', $tab) . '.php';

        if (!file_exists($template)) {
            echo '<p>' . esc_html__('Tab nije pronađen.', 'dexpress-woocommerce') . '</p>';
            return;
        }

        $options          = $this->options;
        $senderLocations  = $this->senderLocations->findAll();
        $hasPassword      = !EncryptedString::fromString($options->getString('api.password'))->isEmpty();

        require $template;
    }

    private function getNotice(): ?array
    {
        $type    = isset($_GET['dexpress_notice']) ? sanitize_key($_GET['dexpress_notice']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $message = isset($_GET['dexpress_message']) ? sanitize_text_field(urldecode($_GET['dexpress_message'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($type === '' || $message === '') {
            return null;
        }

        return ['type' => $type, 'message' => $message];
    }

    private function renderNotice(array $notice): void
    {
        $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($notice['message']),
        );
    }
}
