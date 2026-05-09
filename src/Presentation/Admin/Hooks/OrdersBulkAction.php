<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Hooks;

use S7codedesign\DExpress\Infrastructure\Persistence\WpdbShipmentRepository;

/**
 * Dodaje bulk akciju "Kreiraj D-Express pošiljke" na listu WooCommerce narudžbina.
 * Podržava i HPOS i klasičan (post-based) mod.
 *
 * Filteri pre preusmervanja:
 *   - preskočiti Package Shop narudžbine (kreirati ih pojedinačno)
 *   - preskočiti narudžbine koje već imaju D-Express pošiljku
 *   - preskočiti narudžbine bez D-Express metode dostave
 *   - max 20 narudžbina odjednom
 */
final class OrdersBulkAction
{
    private const MAX_ORDERS = 20;
    private const DEXPRESS_METHODS = ['dexpress', 'dexpress_package_shop'];
    private const PACKAGE_SHOP_METHOD = 'dexpress_package_shop';

    public function __construct(
        private readonly WpdbShipmentRepository $shipments,
    ) {}

    public function register(): void
    {
        // HPOS (WooCommerce 7.1+)
        add_filter('bulk_actions-woocommerce_page_wc-orders',        [$this, 'addBulkAction']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders',  [$this, 'handleBulkAction'], 10, 3);
        // Klasična lista (post-based)
        add_filter('bulk_actions-edit-shop_order',        [$this, 'addBulkAction']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handleBulkAction'], 10, 3);

        add_action('admin_notices', [$this, 'renderNotices']);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function addBulkAction(array $actions): array
    {
        if (!current_user_can('manage_woocommerce')) {
            return $actions;
        }

        $actions['dexpress_bulk_create'] = __('Kreiraj D-Express pošiljke', 'dexpress-woocommerce');

        return $actions;
    }

    /**
     * @param string    $redirectTo
     * @param string    $action
     * @param int[]|string[] $orderIds
     */
    public function handleBulkAction(string $redirectTo, string $action, array $orderIds): string
    {
        if ($action !== 'dexpress_bulk_create') {
            return $redirectTo;
        }

        if (!current_user_can('manage_woocommerce')) {
            return $redirectTo;
        }

        $orderIds = array_values(array_map('absint', $orderIds));
        $total    = count($orderIds);

        if ($total > self::MAX_ORDERS) {
            return add_query_arg(
                ['dexpress_bulk_notice' => 'too_many', 'dexpress_count' => $total],
                $redirectTo,
            );
        }

        $eligible     = [];
        $skipShop     = 0;
        $skipNoMethod = 0;
        $skipExisting = 0;

        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $hasMethod = false;
            $isShop    = false;

            foreach ($order->get_shipping_methods() as $method) {
                $mid = $method->get_method_id();
                if (in_array($mid, self::DEXPRESS_METHODS, true)) {
                    $hasMethod = true;
                    if ($mid === self::PACKAGE_SHOP_METHOD) {
                        $isShop = true;
                    }
                }
            }

            if (!$hasMethod) {
                $skipNoMethod++;
                continue;
            }

            if ($isShop) {
                $skipShop++;
                continue;
            }

            // Proveri da li postoji aktivna pošiljka za tu narudžbinu.
            if ($this->shipments->findLatestByOrderId($orderId) !== null) {
                $skipExisting++;
                continue;
            }

            $eligible[] = $orderId;
        }

        if (empty($eligible)) {
            return add_query_arg(
                [
                    'dexpress_bulk_notice' => 'none_eligible',
                    'dexpress_skip_shop'   => $skipShop,
                    'dexpress_skip_no_m'   => $skipNoMethod,
                    'dexpress_skip_exist'  => $skipExisting,
                ],
                $redirectTo,
            );
        }

        $params = [
            'page'           => 'dexpress-bulk-shipment',
            'order_ids'      => implode(',', $eligible),
            'skip_shop'      => $skipShop,
            'skip_no_method' => $skipNoMethod,
            'skip_existing'  => $skipExisting,
            '_wpnonce'       => wp_create_nonce('dexpress_bulk_init'),
        ];

        wp_safe_redirect(admin_url('admin.php?' . http_build_query($params)));
        exit;
    }

    public function renderNotices(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $notice = isset($_GET['dexpress_bulk_notice']) ? sanitize_key((string) $_GET['dexpress_bulk_notice']) : '';
        if ($notice === '') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $count = absint($_GET['dexpress_count'] ?? 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $skipShop  = absint($_GET['dexpress_skip_shop'] ?? 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $skipNoM   = absint($_GET['dexpress_skip_no_m'] ?? 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $skipExist = absint($_GET['dexpress_skip_exist'] ?? 0);

        if ($notice === 'too_many') {
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>D-Express:</strong> %s</p></div>',
                esc_html(sprintf(
                    /* translators: 1: number of selected orders, 2: maximum */
                    __('Odabrano je %1$d narudžbina. Maksimum za grupno kreiranje je %2$d. Molimo smanjite selekciju.', 'dexpress-woocommerce'),
                    $count,
                    self::MAX_ORDERS,
                )),
            );
            return;
        }

        if ($notice === 'none_eligible') {
            $parts = [];
            if ($skipShop > 0) {
                $parts[] = sprintf(
                    /* translators: %d: count */
                    _n('%d Package Shop narudžbina preskočena — kreirati pojedinačno', '%d Package Shop narudžbine preskočene — kreirati pojedinačno', $skipShop, 'dexpress-woocommerce'),
                    $skipShop,
                );
            }
            if ($skipNoM > 0) {
                $parts[] = sprintf(
                    /* translators: %d: count */
                    _n('%d narudžbina bez D-Express metode dostave', '%d narudžbina bez D-Express metode dostave', $skipNoM, 'dexpress-woocommerce'),
                    $skipNoM,
                );
            }
            if ($skipExist > 0) {
                $parts[] = sprintf(
                    /* translators: %d: count */
                    _n('%d narudžbina već ima pošiljku', '%d narudžbina već ima pošiljku', $skipExist, 'dexpress-woocommerce'),
                    $skipExist,
                );
            }

            $detail = !empty($parts) ? ' (' . implode('; ', $parts) . ').' : '.';
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>D-Express:</strong> %s%s</p></div>',
                esc_html__('Nema prihvatljivih narudžbina za grupno kreiranje.', 'dexpress-woocommerce'),
                esc_html($detail),
            );
        }
    }
}
