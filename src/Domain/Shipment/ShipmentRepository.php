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

    public function findLatestByOrderId(int $orderId): ?Shipment;

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

    /**
     * Highest numeric suffix for the given two-letter prefix among existing package codes, or null if none.
     * Used for usage / warning logic (read outside the allocation transaction).
     */
    public function maxAllocatedNumericForPrefix(string $prefix): ?int;

    /**
     * Count of package codes currently allocated within the given range (active — not freed by deletion).
     * More accurate than MAX-based approximation when gaps exist from deleted pending_send shipments.
     */
    public function countAllocatedCodesInRange(string $prefix, int $rangeStart, int $rangeEnd): int;

    /**
     * First numeric suffix in the given range that is not currently allocated.
     * Returns null when the range is fully exhausted.
     */
    public function firstFreeNumericInRange(string $prefix, int $rangeStart, int $rangeEnd): ?int;

    public function updateStatusPresentation(int $id, StatusEmailBucket $bucket, int $currentSid, string $labelSnapshot): void;

    public function getSendStatus(int $id): string;

    public function setSendStatus(int $id, string $sendStatus): void;

    /**
     * Updates editable shipment fields for an existing locally saved shipment.
     */
    public function updateDraftData(Shipment $shipment): void;

    /**
     * Admin lista pošiljaka (bez hidratacije domena).
     *
     * @return list<array{id: int, order_id: int, status: string, current_sid: int, status_label_snapshot: string, created_at: string, package_code: string|null}>
     */
    public function findAdminListRows(int $offset, int $limit, ?string $statusFilter): array;

    public function countAdminList(?string $statusFilter): int;

    public function countAllNotDeleted(): int;
}
