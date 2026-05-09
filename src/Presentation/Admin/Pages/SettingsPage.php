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

        echo '<div class="wrap dexpress-settings-wrap">';
        echo '<img src="' . esc_url(DEXPRESS_PLUGIN_URL . 'assets/images/Dexpress-logo.jpg') . '" alt="D Express" class="dexpress-header-logo">';
        echo '<h1>' . esc_html__('D Express podešavanja', 'dexpress-woocommerce') . '</h1>';
        if ($notice) {
            $this->renderNotice($notice);
        }

        $usageWarning = ShipmentCodeAllocator::consumeUsageWarningTransient();
        if ($usageWarning !== null && $usageWarning !== '') {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html($usageWarning),
            );
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
        $prefix     = strtoupper(trim($this->options->getString('shipment.prefix', '')));
        $rangeStart = max(1, (int) $this->options->get('shipment.range_start', 1));
        $rangeEnd   = max(1, (int) $this->options->get('shipment.range_end', 99));

        if ($prefix === '' || !preg_match('/^[A-Z]{2}$/', $prefix) || $rangeEnd < $rangeStart) {
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
        $class = match ($notice['type']) {
            'success' => 'notice-success',
            'warning' => 'notice-warning',
            default   => 'notice-error',
        };
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($notice['message']),
        );
    }
}
