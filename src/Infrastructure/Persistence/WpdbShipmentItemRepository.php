<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use wpdb;

/**
 * Aggregated order-line quantities per shipment (derived from package_items).
 */
final class WpdbShipmentItemRepository
{
    private string $table;

    private string $packageItemsTable;

    private string $packagesTable;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table             = $wpdb->prefix . 'dexpress_shipment_items';
        $this->packageItemsTable = $wpdb->prefix . 'dexpress_package_items';
        $this->packagesTable     = $wpdb->prefix . 'dexpress_packages';
    }

    public function deleteByShipmentId(int $shipmentId): void
    {
        $this->wpdb->delete($this->table, ['shipment_id' => $shipmentId], ['%d']);
    }

    /**
     * Rebuilds shipment_items from package_items for this shipment.
     */
    public function replaceAggregatedFromPackageItems(int $shipmentId): void
    {
        $this->deleteByShipmentId($shipmentId);

        $now = current_time('mysql');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "INSERT INTO `{$this->table}` (shipment_id, order_item_id, quantity, created_at, updated_at)
                SELECT %d, pi.order_item_id, SUM(pi.quantity), %s, %s
                FROM `{$this->packageItemsTable}` pi
                INNER JOIN `{$this->packagesTable}` p ON p.id = pi.package_id
                WHERE p.shipment_id = %d
                GROUP BY pi.order_item_id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->wpdb->query($this->wpdb->prepare($sql, $shipmentId, $now, $now, $shipmentId));
    }
}
