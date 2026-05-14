<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Hooks;

use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;
use WC_Order;

/**
 * WooCommerce narudžbine (lista): kolona „Status dostave“ (HPOS + klasični CPT).
 */
final class OrdersListDeliveryStatusColumn
{
    public const COLUMN_ID = 'dexpress_delivery_status';

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly StatusCodeRepository $statusCodes,
    ) {}

    public function register(): void
    {
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'insertColumnAfterStatus'], 25);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderHposCell'], 10, 2);

        add_filter('manage_shop_order_posts_columns', [$this, 'insertColumnAfterStatus'], 25);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderClassicCell'], 10, 2);

        add_action('admin_enqueue_scripts', [$this, 'enqueueListStyles']);
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function insertColumnAfterStatus(array $columns): array
    {
        $label = __('Status dostave', 'dexpress-woocommerce');
        if (!isset($columns['order_status'])) {
            $columns[self::COLUMN_ID] = $label;

            return $columns;
        }

        $out = [];
        foreach ($columns as $key => $title) {
            $out[$key] = $title;
            if ($key === 'order_status') {
                $out[self::COLUMN_ID] = $label;
            }
        }

        return $out;
    }

    public function renderHposCell(string $column, $order): void
    {
        if ($column !== self::COLUMN_ID || !$order instanceof WC_Order) {
            return;
        }

        $this->echoCellForOrderId((int) $order->get_id());
    }

    /**
     * @param string $column
     * @param int|string $post_id
     */
    public function renderClassicCell(string $column, $post_id): void
    {
        if ($column !== self::COLUMN_ID) {
            return;
        }

        $this->echoCellForOrderId((int) $post_id);
    }

    public function enqueueListStyles(string $hookSuffix): void
    {
        if (!$this->isOrdersListScreen($hookSuffix)) {
            return;
        }

        wp_enqueue_style(
            'dex-admin',
            DEXPRESS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DEXPRESS_VERSION,
        );
    }

    private function isOrdersListScreen(string $hookSuffix): bool
    {
        if ($hookSuffix === 'woocommerce_page_wc-orders') {
            return true;
        }

        if ($hookSuffix === 'edit.php'
            && isset($_GET['post_type']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && sanitize_key((string) $_GET['post_type']) === 'shop_order') {
            return true;
        }

        return false;
    }

    private function echoCellForOrderId(int $orderId): void
    {
        if ($orderId <= 0) {
            echo esc_html('—');

            return;
        }

        $list = $this->shipments->findByOrderId($orderId);
        if ($list === []) {
            echo esc_html('—');

            return;
        }

        $shipment = $this->pickPrimaryShipment($list);
        if ($shipment === null) {
            echo esc_html('—');

            return;
        }

        $label = $this->statusCodes->resolveOfficialShipmentStatusLabel(
            $shipment->currentSid(),
            $shipment->displayStatusLabel(),
        );
        $bucket = $shipment->emailBucket();
        $class   = $this->badgeModifierClass($bucket);

        printf(
            '<span class="dexpress-order-delivery-badge %s">%s</span>',
            esc_attr($class),
            esc_html($label),
        );
    }

    /**
     * @param Shipment[] $shipments
     */
    private function pickPrimaryShipment(array $shipments): ?Shipment
    {
        if ($shipments === []) {
            return null;
        }

        usort(
            $shipments,
            static function (Shipment $a, Shipment $b): int {
                $t = $b->createdAt <=> $a->createdAt;
                if ($t !== 0) {
                    return $t;
                }

                return ($b->id() ?? 0) <=> ($a->id() ?? 0);
            },
        );

        return $shipments[0];
    }

    private function badgeModifierClass(StatusEmailBucket $bucket): string
    {
        return match ($bucket) {
            StatusEmailBucket::Delivered      => 'dexpress-order-delivery-badge--delivered',
            StatusEmailBucket::InTransit,
            StatusEmailBucket::OutForDelivery => 'dexpress-order-delivery-badge--progress',
            StatusEmailBucket::ProblemFailed  => 'dexpress-order-delivery-badge--problem',
            StatusEmailBucket::Other          => 'dexpress-order-delivery-badge--neutral',
        };
    }
}
