<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Application\Shipment\ShipmentCodeAllocator;
use S7codedesign\DExpress\Application\Simulation\SimulationService;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Options\EncryptedString;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\WpdbSenderLocationRepository;

final class SettingsPage
{
    private const PAGE_SLUG = 'dexpress-settings';

    private const TABS = [
        'api'              => 'API Kredencijali',
        'sifarnici'        => 'Šifarnici',
        'sender_locations' => 'Lokacije pošiljaoca',
        'webhook'          => 'Webhook',
        'checkout'         => 'Checkout',
        'email'            => 'Email podešavanja',
        'logging'          => 'Logovanje',
        'simulation'       => 'Simulacija',
    ];

    private const TAB_ICONS = [
        'api'              => 'dashicons-lock',
        'sifarnici'        => 'dashicons-database-import',
        'sender_locations' => 'dashicons-location',
        'webhook'          => 'dashicons-rest-api',
        'checkout'         => 'dashicons-cart',
        'email'            => 'dashicons-email',
        'logging'          => 'dashicons-editor-code',
        'simulation'       => 'dashicons-performance',
    ];

    public function __construct(
        private readonly OptionsRepository $options,
        private readonly WpdbSenderLocationRepository $senderLocations,
        private readonly ShipmentRepository $shipments,
        private readonly SimulationService $simulation,
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

        $envValue = $this->options->getString('api.environment', 'test');
        $envLabel = $envValue === 'production'
            ? esc_html__('Produkcija', 'dexpress-woocommerce')
            : esc_html__('Test mode', 'dexpress-woocommerce');
        $envClass = $envValue === 'production'
            ? 'dex-settings-header__env--production'
            : 'dex-settings-header__env--test';

        echo '<div class="wrap dex-settings-wrap">';

        $logoUrl = DEXPRESS_PLUGIN_URL . 'assets/images/Dexpress-logo.jpg';

        echo '<div class="dex-settings-header">';
        echo '<div class="dex-settings-header__brand">';
        echo '<img src="' . esc_url($logoUrl) . '" alt="D Express" class="dex-settings-header__logo">';
        echo '<div>';
        echo '<div class="dex-settings-header__name">D Express for WooCommerce</div>';
        echo '<div class="dex-settings-header__sub">' . esc_html__('Podešavanja plugina', 'dexpress-woocommerce') . '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="dex-settings-header__right">';
        echo '<span class="dex-settings-header__env ' . esc_attr($envClass) . '">' . $envLabel . '</span>';
        echo '</div>';
        echo '</div>';

        if ($notice) {
            $this->renderNotice($notice);
        }

        $usageWarning = ShipmentCodeAllocator::consumeUsageWarningTransient();
        if ($usageWarning !== null && $usageWarning !== '') {
            printf(
                '<div class="dex-notice dex-notice--warning dex-settings-page-notice"><div class="dex-notice__content"><p class="dex-notice__body">%s</p></div></div>',
                esc_html($usageWarning),
            );
        }

        echo '<div class="dex-tabs">';
        foreach (self::TABS as $slug => $label) {
            $url    = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $slug], admin_url('admin.php'));
            $active = $activeTab === $slug ? ' is-active' : '';
            $icon = isset(self::TAB_ICONS[$slug])
                ? '<span class="dashicons ' . esc_attr(self::TAB_ICONS[$slug]) . '" aria-hidden="true"></span>'
                : '';
            echo '<a href="' . esc_url($url) . '" class="dex-tabs__item' . esc_attr($active) . '">';
            echo $icon;
            echo esc_html($label);
            echo '</a>';
        }
        echo '</div>';

        echo '<div class="dex-tabs__panel">';
        $this->renderTab($activeTab);
        echo '</div>';

        $this->renderUnsavedModal();

        echo '</div>';
    }

    private function renderUnsavedModal(): void
    {
        echo '<div id="dex-unsaved-modal" class="dex-modal" role="dialog" aria-modal="true" aria-labelledby="dex-unsaved-modal-title">';
        echo '<div class="dex-modal__backdrop"></div>';
        echo '<div class="dex-modal__dialog">';
        echo '<div class="dex-modal__header">';
        echo '<h3 id="dex-unsaved-modal-title" class="dex-modal__title">' . esc_html__('Nesačuvane promene', 'dexpress-woocommerce') . '</h3>';
        echo '</div>';
        echo '<div class="dex-modal__body">';
        echo '<p>' . esc_html__('Imate nesačuvane promene. Ako napustite ovaj tab, promene će biti izgubljene.', 'dexpress-woocommerce') . '</p>';
        echo '</div>';
        echo '<div class="dex-modal__footer">';
        echo '<button type="button" id="dex-unsaved-confirm" class="dex-btn dex-btn--danger">' . esc_html__('Napusti bez čuvanja', 'dexpress-woocommerce') . '</button>';
        echo '<button type="button" id="dex-unsaved-cancel" class="dex-btn dex-btn--secondary">' . esc_html__('Ostani na stranici', 'dexpress-woocommerce') . '</button>';
        echo '</div>';
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
        /** @var array<string, mixed>|null $shipment_code_range_status read-only UI stats (API tab only) */
        $shipment_code_range_status = $tab === 'api' ? $this->shipmentCodeRangeStatusForTemplate() : null;
        /** @var array{quick: list<array<string, mixed>>, real: list<array<string, mixed>>, flow_labels: list<string>}|null $simulation_timeline */
        $simulation_timeline = $tab === 'simulation' ? $this->simulationTimelineForTemplate() : null;

        require $template;
    }

    /**
     * Read-only stats for Shipment Code Range UI (no allocation / persistence).
     *
     * @return array{
     *   valid: bool,
     *   tier?: 'normal'|'warning'|'critical'|'exhausted',
     *   prefix?: string,
     *   range_start?: int,
     *   range_end?: int,
     *   total?: int,
     *   used?: int,
     *   remaining?: int,
     *   usage_percent?: float,
     *   max_numeric?: int|null
     * }
     */
    private function shipmentCodeRangeStatusForTemplate(): array
    {
        $prefix = ShipmentCodeAllocator::normalizeShipmentPrefix($this->options->getString('shipment.prefix', ''));
        $rsRaw  = $this->options->get('shipment.range_start');
        $reRaw  = $this->options->get('shipment.range_end');
        $rangeStart = ($rsRaw !== null && $rsRaw !== '') ? (int) $rsRaw : 0;
        $rangeEnd   = ($reRaw !== null && $reRaw !== '') ? (int) $reRaw : 0;

        if (
            $prefix === ''
            || !preg_match('/^[A-Z]{2}$/', $prefix)
            || $rangeStart < 1
            || $rangeEnd < 1
            || $rangeEnd < $rangeStart
        ) {
            return ['valid' => false];
        }

        $total = $rangeEnd - $rangeStart + 1;
        if ($total < 1) {
            return ['valid' => false];
        }

        $maxNumeric = $this->shipments->maxAllocatedNumericForPrefix($prefix);
        $rawUsed    = $maxNumeric === null ? 0 : max(0, $maxNumeric - $rangeStart + 1);
        $used       = min($rawUsed, $total);
        $remaining  = max(0, $total - $rawUsed);
        $usagePct   = $total > 0 ? min(100.0, ($rawUsed / $total) * 100.0) : 0.0;

        $tier = 'normal';
        if ($rawUsed >= $total || $remaining <= 0) {
            $tier = 'exhausted';
        } elseif ($usagePct > 95.0) {
            $tier = 'critical';
        } elseif ($usagePct >= 80.0) {
            $tier = 'warning';
        }

        return [
            'valid'          => true,
            'tier'           => $tier,
            'prefix'         => $prefix,
            'range_start'    => $rangeStart,
            'range_end'      => $rangeEnd,
            'total'          => $total,
            'used'           => $rawUsed,
            'remaining'      => $remaining,
            'usage_percent'  => $usagePct,
            'max_numeric'    => $maxNumeric,
        ];
    }

    /**
     * Simulacija: koraci i labele iz servisa + šifarnik (bez duplog hardkoda u šablonu).
     *
     * @return array{quick: list<array<string, mixed>>, real: list<array<string, mixed>>, flow_labels: list<string>}
     */
    private function simulationTimelineForTemplate(): array
    {
        return [
            'quick'       => $this->simulation->buildAdminTimelinePreview(true),
            'real'        => $this->simulation->buildAdminTimelinePreview(false),
            'flow_labels' => $this->simulation->simulationFlowStepLabels(),
        ];
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
        $modifier = match ($notice['type']) {
            'success' => 'success',
            'warning' => 'warning',
            default   => 'error',
        };
        printf(
            '<div class="dex-notice dex-notice--%s dex-settings-page-notice"><div class="dex-notice__content"><p class="dex-notice__body">%s</p></div></div>',
            esc_attr($modifier),
            esc_html($notice['message']),
        );
    }
}
