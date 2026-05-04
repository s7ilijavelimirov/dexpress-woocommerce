<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Domain\Shipment;

interface ShipmentRepository
{
    /**
     * Persist a new shipment and its packages. Returns the shipment with its DB-assigned ID.
     */
    public function save(Shipment $shipment): Shipment;

    public function findById(int $id): ?Shipment;

    public function findByPackageCode(string $code): ?Shipment;

    /**
     * @return Shipment[]
     */
    public function findByOrderId(int $orderId): array;

    /**
     * All shipments for the given order IDs (newest first).
     *
     * @param list<int> $orderIds
     * @return Shipment[]
     */
    public function findByOrderIds(array $orderIds): array;

    /**
     * Hard-deletes a shipment and all its packages.
     * Used as a compensating action when the API call fails after DB commit.
     */
    public function deleteById(int $id): void;

    /**
     * Atomically reserve the next available package code in the configured range.
     * Uses SELECT ... FOR UPDATE inside the caller's transaction to prevent duplicates.
     *
     * @throws \RuntimeException when the range is exhausted
     */
    public function allocatePackageCode(string $prefix, int $rangeStart, int $rangeEnd): PackageCode;

    public function updateStatusPresentation(int $id, StatusEmailBucket $bucket, int $currentSid, string $labelSnapshot): void;

    /**
     * Admin lista pošiljaka (bez hidratacije domena).
     *
     * @return list<array{id: int, order_id: int, status: string, current_sid: int, status_label_snapshot: string, created_at: string, package_code: string|null}>
     */
    public function findAdminListRows(int $offset, int $limit, ?string $statusFilter): array;

    public function countAdminList(?string $statusFilter): int;

    public function countAllNotDeleted(): int;
}
