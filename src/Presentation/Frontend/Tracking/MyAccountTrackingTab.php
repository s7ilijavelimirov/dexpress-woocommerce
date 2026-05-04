<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Frontend\Tracking;

use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Infrastructure\Options\OptionsRepository;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

final class MyAccountTrackingTab
{
    private const ENDPOINT_KEY = 'dexpress-shipments';

    /**
     * Bump when My Account endpoints / routing change so rewrite rules are regenerated.
     * v2: koristi zvanični {@see woocommerce_account_dexpress-shipments_endpoint} umesto hack-a na account_content.
     */
    private const REWRITE_RULES_MARK = 'dexpress_myaccount_v2_account_endpoint';

    private const REWRITE_RULES_OPTION = 'dexpress_myaccount_endpoint_rewrite_mark';

    public function __construct(
        private readonly OptionsRepository $options,
        private readonly ShipmentRepository $shipments,
        private readonly StatusCodeRepository $statusCodes,
    ) {}

    public function register(): void
    {
        add_filter('woocommerce_get_query_vars', [$this, 'registerEndpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'menuItems']);
        // Zvanični WooCommerce tok: {@see woocommerce_account_content()} poziva ovaj hook kada je endpoint aktivan.
        add_action('woocommerce_account_' . self::ENDPOINT_KEY . '_endpoint', [$this, 'renderEndpoint'], 10, 1);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT_KEY . '_title', [$this, 'endpointTitle'], 10, 3);

        // After WC_Query::add_endpoints() on init:10 — persist rules so /my-account/{endpoint}/ resolves.
        add_action('init', [$this, 'maybeFlushRewriteRulesForEndpoints'], 99);
    }

    /** @param mixed $value WooCommerce prosleđuje vrednost query var-a (često prazan string). */
    public function renderEndpoint(mixed $value): void
    {
        unset($value);
        $this->render();
    }

    public function maybeFlushRewriteRulesForEndpoints(): void
    {
        if (get_option(self::REWRITE_RULES_OPTION, '') === self::REWRITE_RULES_MARK) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(self::REWRITE_RULES_OPTION, self::REWRITE_RULES_MARK, false);
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public function registerEndpoint(array $vars): array
    {
        $vars[self::ENDPOINT_KEY] = self::ENDPOINT_KEY;

        return $vars;
    }

    /**
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function menuItems(array $items): array
    {
        if (!$this->options->getBool('email.myaccount_tracking_enabled', true)) {
            return $items;
        }

        $inserted = false;
        $new      = [];

        foreach ($items as $key => $label) {
            $new[$key] = $label;

            if ($key === 'orders') {
                $new[self::ENDPOINT_KEY] = __('Praćenje pošiljki', 'dexpress-woocommerce');
                $inserted                = true;
            }
        }

        if (!$inserted) {
            $new[self::ENDPOINT_KEY] = __('Praćenje pošiljki', 'dexpress-woocommerce');
        }

        return $new;
    }

    public function endpointTitle(string $title, string $endpoint, string $action): string
    {
        unset($action);

        if ($endpoint === self::ENDPOINT_KEY) {
            return __('Praćenje pošiljki', 'dexpress-woocommerce');
        }

        return $title;
    }

    public function render(): void
    {
        if (!$this->options->getBool('email.myaccount_tracking_enabled', true)) {
            wc_print_notice(
                __('Praćenje pošiljki iz naloga nije uključeno.', 'dexpress-woocommerce'),
                'notice',
            );

            return;
        }

        $userId = get_current_user_id();

        if ($userId <= 0) {
            return;
        }

        /** @var list<int> $orderIds */
        $orderIds = wc_get_orders([
            'customer_id' => $userId,
            'limit'       => 300,
            'paginate'    => false,
            'return'      => 'ids',
        ]);

        $orderIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => absint((string) $id),
            is_array($orderIds) ? $orderIds : [],
        ), static fn (int $id): bool => $id > 0));

        $shipments = $this->shipments->findByOrderIds($orderIds);

        /** @var list<array{shipment: Shipment, status_label: string}> $shipment_rows */
        $shipment_rows = [];
        foreach ($shipments as $shipment) {
            if (!$shipment instanceof Shipment) {
                continue;
            }
            $shipment_rows[] = [
                'shipment'     => $shipment,
                'status_label' => $this->statusCodes->resolveOfficialShipmentStatusLabel(
                    $shipment->currentSid(),
                    $shipment->displayStatusLabel(),
                ),
            ];
        }

        if ($shipment_rows === []) {
            echo '<p class="woocommerce-info dexpress-myaccount-tracking-empty">' . esc_html__(
                'Još uvek nemate kreiranih D Express pošiljki za svoje porudžbine. Kada prodavac kreira pošiljku, videćete je ovde sa kodom za praćenje i statusom.',
                'dexpress-woocommerce',
            ) . '</p>';

            return;
        }

        wc_get_template(
            'myaccount/dexpress-shipments.php',
            ['shipment_rows' => $shipment_rows],
            '',
            trailingslashit(DEXPRESS_PLUGIN_DIR) . 'templates/',
        );
    }
}
