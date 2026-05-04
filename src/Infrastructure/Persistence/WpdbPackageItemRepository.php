<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use wpdb;

/**
 * Per-package allocation of WooCommerce order line items (quantity).
 */
final class WpdbPackageItemRepository
{
    private string $table;

    private string $packagesTable;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table         = $wpdb->prefix . 'dexpress_package_items';
        $this->packagesTable = $wpdb->prefix . 'dexpress_packages';
    }

    public function insert(int $packageId, int $orderItemId, int $quantity): void
    {
        if ($quantity <= 0 || $orderItemId <= 0 || $packageId <= 0) {
            return;
        }

        $now = current_time('mysql');
        $ok  = $this->wpdb->insert(
            $this->table,
            [
                'package_id'     => $packageId,
                'order_item_id'  => $orderItemId,
                'quantity'       => $quantity,
                'created_at'     => $now,
            ],
            ['%d', '%d', '%d', '%s'],
        );

        if ($ok === false) {
            throw new \RuntimeException('package_items insert failed: ' . $this->wpdb->last_error);
        }
    }

    /**
     * Removes all package-item rows for packages belonging to this shipment.
     */
    public function deleteByShipmentId(int $shipmentId): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "DELETE pi FROM `{$this->table}` pi
                INNER JOIN `{$this->packagesTable}` p ON p.id = pi.package_id
                WHERE p.shipment_id = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->wpdb->query($this->wpdb->prepare($sql, $shipmentId));
    }

    /**
     * @return array<int, list<array{order_item_id: int, quantity: int}>>
     */
    public function quantitiesByPackageId(int $shipmentId): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT pi.package_id, pi.order_item_id, pi.quantity
                FROM `{$this->table}` pi
                INNER JOIN `{$this->packagesTable}` p ON p.id = pi.package_id
                WHERE p.shipment_id = %d
                ORDER BY pi.package_id ASC, pi.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $shipmentId), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) $row['package_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = [];
            }
            $out[$pid][] = [
                'order_item_id' => (int) $row['order_item_id'],
                'quantity'      => (int) $row['quantity'],
            ];
        }

        return $out;
    }
}
