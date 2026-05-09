<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Presentation\Admin\Pages;

use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;
use S7codedesign\DExpress\Infrastructure\Persistence\Sync\StatusCodeRepository;

if (!class_exists(\WP_List_Table::class, false)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class ShipmentListTable extends \WP_List_Table
{
    private static function representativeSidForStatusFilter(StatusEmailBucket $bucket): int
    {
        return match ($bucket) {
            StatusEmailBucket::Delivered      => 1,
            StatusEmailBucket::InTransit      => 3,
            StatusEmailBucket::OutForDelivery => 4,
            StatusEmailBucket::ProblemFailed  => 5,
            StatusEmailBucket::Other          => 0,
        };
    }

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly StatusCodeRepository $statusCodes,
    ) {
        parent::__construct([
            'singular' => 'shipment',
            'plural'   => 'shipments',
            'ajax'     => false,
        ]);
    }

    public function no_items(): void
    {
        esc_html_e('Nema pošiljki.', 'dexpress-woocommerce');
    }

    public function get_columns(): array
    {
        return [
            'order_id'     => __('Narudžbina', 'dexpress-woocommerce'),
            'package_code' => __('Kod paketa', 'dexpress-woocommerce'),
            'status'       => __('Status', 'dexpress-woocommerce'),
            'created_at'   => __('Kreirano', 'dexpress-woocommerce'),
            'actions'      => __('Akcije', 'dexpress-woocommerce'),
        ];
    }

    public function prepare_items(): void
    {
        $perPage = 25;
        $paged   = max(1, (int) ($_GET['paged'] ?? 1));
        $status  = isset($_GET['status_filter']) ? sanitize_key((string) $_GET['status_filter']) : '';
        $status  = $status !== '' ? $status : null;

        $total = $this->shipments->countAdminList($status);
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $perPage,
        ]);

        $offset       = ($paged - 1) * $perPage;
        $this->items  = $this->shipments->findAdminListRows($offset, $perPage, $status);
    }

    protected function display_tablenav($which): void
    {
        if ($which === 'top') {
            return;
        }
        parent::display_tablenav($which);
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'bottom') {
            return;
        }

        $current = isset($_GET['status_filter']) ? sanitize_key((string) $_GET['status_filter']) : '';

        echo '<form method="get" class="alignleft actions">';
        echo '<input type="hidden" name="page" value="dexpress-shipments" />';
        echo '<label class="screen-reader-text" for="dexpress-status-filter">' . esc_html__('Status', 'dexpress-woocommerce') . '</label>';
        echo '<select name="status_filter" id="dexpress-status-filter">';
        echo '<option value="">' . esc_html__('Svi statusi', 'dexpress-woocommerce') . '</option>';
        foreach (StatusEmailBucket::cases() as $case) {
            $v     = $case->value;
            $label = $this->statusCodes->resolveOfficialShipmentStatusLabel(
                self::representativeSidForStatusFilter($case),
                '',
            );
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($v),
                selected($current, $v, false),
                esc_html($label),
            );
        }
        echo '</select>';
        echo '<button type="submit" class="button">' . esc_html__('Filtriraj', 'dexpress-woocommerce') . '</button>';
        echo '</form>';
    }

    public function column_default($item, $column_name): string
    {
        /** @var array{id:int,order_id:int,status:string,current_sid:int,status_label_snapshot:string,created_at:string,package_code:?string} $item */
        return match ($column_name) {
            'order_id'     => $this->column_order_id($item),
            'package_code' => esc_html($item['package_code'] ?? '—'),
            'status'       => esc_html($this->adminStatusCell($item)),
            'created_at'   => esc_html($item['created_at']),
            'actions'      => $this->column_actions($item),
            default        => '',
        };
    }

    /**
     * @param array{id:int,order_id:int,status:string,current_sid:int,status_label_snapshot:string,created_at:string,package_code:?string} $item
     */
    private function adminStatusCell(array $item): string
    {
        return $this->statusCodes->resolveOfficialShipmentStatusLabel(
            (int) ($item['current_sid'] ?? 0),
            trim((string) ($item['status_label_snapshot'] ?? '')),
        );
    }

    /**
     * @param array{id:int,order_id:int,status:string,current_sid:int,status_label_snapshot:string,created_at:string,package_code:?string} $item
     */
    private function column_order_id(array $item): string
    {
        $oid = (int) $item['order_id'];
        $url = $this->orderEditUrl($oid);

        return '<a href="' . esc_url($url) . '">#' . esc_html((string) $oid) . '</a>';
    }

    /**
     * @param array{id:int,order_id:int,status:string,current_sid:int,status_label_snapshot:string,created_at:string,package_code:?string} $item
     */
    private function column_actions(array $item): string
    {
        $sid = (int) $item['id'];
        $url = add_query_arg(
            [
                'page'         => 'dexpress-label',
                'shipment_id'  => $sid,
                'nonce'        => wp_create_nonce('dexpress_print_label_' . $sid),
            ],
            admin_url('admin.php'),
        );

        return '<a class="button button-small" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . esc_html__('Nalepnica', 'dexpress-woocommerce') . '</a>';
    }

    private function orderEditUrl(int $orderId): string
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId);
        }

        return admin_url('post.php?post=' . $orderId . '&action=edit');
    }
}
