<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use S7codedesign\DExpress\Domain\Money\Grams;
use S7codedesign\DExpress\Domain\Money\Money;
use S7codedesign\DExpress\Domain\Shipment\DeliveryType;
use S7codedesign\DExpress\Domain\Shipment\PackageCode;
use S7codedesign\DExpress\Domain\Shipment\PaymentBy;
use S7codedesign\DExpress\Domain\Shipment\PaymentType;
use S7codedesign\DExpress\Domain\Shipment\ReturnDoc;
use S7codedesign\DExpress\Domain\Shipment\Shipment;
use S7codedesign\DExpress\Domain\Shipment\ShipmentRepository;
use S7codedesign\DExpress\Domain\Shipment\StatusEmailBucket;

final class WpdbShipmentRepository implements ShipmentRepository
{
    private string $table;
    private string $packagesTable;

    public function __construct(
        private readonly \wpdb                  $wpdb,
        private readonly WpdbPackageRepository  $packages,
    ) {
        $this->table         = $wpdb->prefix . 'dexpress_shipments';
        $this->packagesTable = $wpdb->prefix . 'dexpress_packages';
    }

    /**
     * Inserts a new shipment (id=null) or updates status+api_response for an existing one.
     */
    public function save(Shipment $shipment): Shipment
    {
        $now = current_time('mysql');

        if ($shipment->id() === null) {
            $this->wpdb->insert(
                $this->table,
                [
                    'order_id'           => $shipment->orderId,
                    'reference_id'       => $shipment->referenceId,
                    'sender_location_id' => $shipment->senderLocationId,
                    'send_status'             => 'pending_send',
                    'status'                  => $shipment->emailBucket()->value,
                    'current_sid'             => $shipment->currentSid(),
                    'status_label_snapshot'   => $shipment->displayStatusLabel(),
                    'dl_type_id'              => $shipment->deliveryType->value,
                    'payment_by'         => $shipment->paymentBy->value,
                    'payment_type'       => $shipment->paymentType->value,
                    'value_para'         => $shipment->declaredValue->toPara(),
                    'cod_amount_para'    => $shipment->codAmount->toPara(),
                    'cod_bank_account'   => $shipment->codBankAccount,
                    'total_mass_grams'   => $shipment->totalMass->value(),
                    'content'            => $shipment->content,
                    'note'               => $shipment->note,
                    'return_doc'         => $shipment->returnDoc->value,
                    'self_drop_off'      => $shipment->selfDropOff ? 1 : 0,
                    'split_index'        => $shipment->splitIndex,
                    'total_splits'       => $shipment->totalSplits,
                    'api_response'       => $shipment->apiResponse(),
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ],
                ['%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s'],
            );

            $id = (int) $this->wpdb->insert_id;

            foreach ($shipment->packages as $package) {
                $this->packages->saveForShipment($package, $id);
            }

            return $shipment->withId($id);
        }

        // Update — promenljiva polja posle kreiranja / API odgovora
        $this->wpdb->update(
            $this->table,
            [
                'status'                => $shipment->emailBucket()->value,
                'current_sid'           => $shipment->currentSid(),
                'status_label_snapshot' => $shipment->displayStatusLabel(),
                'api_response'          => $shipment->apiResponse(),
                'updated_at'            => $now,
            ],
            ['id' => $shipment->id()],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d'],
        );

        return $shipment;
    }

    /**
     * Hard-deletes a shipment and all its packages.
     * Used as a compensating action when the API call fails after a successful DB insert.
     */
    public function deleteById(int $id): void
    {
        $packageItemsTable = $this->wpdb->prefix . 'dexpress_package_items';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE pi FROM `{$packageItemsTable}` pi
                 INNER JOIN `{$this->packagesTable}` p ON p.id = pi.package_id
                 WHERE p.shipment_id = %d",
                $id,
            ),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM `{$this->packagesTable}` WHERE shipment_id = %d",
                $id,
            ),
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM `{$this->table}` WHERE id = %d",
                $id,
            ),
        );
    }

    public function findById(int $id): ?Shipment
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE id = %d AND deleted_at IS NULL",
                $id,
            ),
            ARRAY_A,
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByPackageCode(string $code): ?Shipment
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $shipmentId = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT shipment_id FROM `{$this->packagesTable}` WHERE code = %s LIMIT 1",
                $code,
            )
        );

        if ($shipmentId === null) {
            return null;
        }

        return $this->findById((int) $shipmentId);
    }

    /**
     * @return Shipment[]
     */
    public function findByOrderId(int $orderId): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE order_id = %d AND deleted_at IS NULL ORDER BY created_at DESC",
                $orderId,
            ),
            ARRAY_A,
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn (array $row): Shipment => $this->hydrate($row), $rows);
    }

    public function findLatestByOrderId(int $orderId): ?Shipment
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE order_id = %d AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1",
                $orderId,
            ),
            ARRAY_A,
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param list<int> $orderIds
     * @return Shipment[]
     */
    public function findByOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => absint((string) $id), $orderIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table}`
             WHERE deleted_at IS NULL AND order_id IN ({$placeholders})
             ORDER BY created_at DESC",
            ...$orderIds,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn (array $row): Shipment => $this->hydrate($row), $rows);
    }

    /**
     * Atomically reserves the next available package code within the configured range.
     * Must be called inside an active transaction (caller's responsibility).
     *
     * Important: rows are not inserted until later {@see save()}. Call this once per shipment,
     * then derive further codes with {@see PackageCode::fromPrefixAndIndex()} (+1) for additional packages.
     *
     * @throws \RuntimeException when the range is exhausted
     */
    public function allocatePackageCode(string $prefix, int $rangeStart, int $rangeEnd): PackageCode
    {
        $like = $this->wpdb->esc_like($prefix) . '%';

        // Lock all matching rows so concurrent allocations cannot race.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $activeCodes = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT code FROM `{$this->packagesTable}` WHERE code LIKE %s FOR UPDATE",
                $like,
            )
        );

        // Build a fast lookup of occupied numbers within the configured range.
        $occupied  = [];
        $prefixLen = strlen($prefix);
        foreach ($activeCodes ?: [] as $code) {
            $n = (int) substr((string) $code, $prefixLen);
            if ($n >= $rangeStart && $n <= $rangeEnd) {
                $occupied[$n] = true;
            }
        }

        // First free slot — respects gaps left by deleted pending_send shipments.
        for ($n = $rangeStart; $n <= $rangeEnd; $n++) {
            if (!isset($occupied[$n])) {
                return PackageCode::fromPrefixAndIndex($prefix, $n);
            }
        }

        throw new \RuntimeException(
            'D Express code range exhausted. Please extend range in settings.',
        );
    }

    public function maxAllocatedNumericForPrefix(string $prefix): ?int
    {
        $prefix = strtoupper($prefix);
        if ($prefix === '' || !preg_match('/^[A-Z]{2}$/', $prefix)) {
            return null;
        }

        $like = $this->wpdb->esc_like($prefix) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $maxCode = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT MAX(code) FROM `{$this->packagesTable}` WHERE code LIKE %s",
                $like,
            )
        );

        if ($maxCode === null || $maxCode === '') {
            return null;
        }

        return (int) substr((string) $maxCode, 2);
    }

    public function countAllocatedCodesInRange(string $prefix, int $rangeStart, int $rangeEnd): int
    {
        $prefix = strtoupper($prefix);
        if ($prefix === '' || !preg_match('/^[A-Z]{2}$/', $prefix)) {
            return 0;
        }

        $like      = $this->wpdb->esc_like($prefix) . '%';
        $prefixLen = strlen($prefix);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $codes = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT code FROM `{$this->packagesTable}` WHERE code LIKE %s",
                $like,
            )
        );

        $count = 0;
        foreach ($codes ?: [] as $code) {
            $n = (int) substr((string) $code, $prefixLen);
            if ($n >= $rangeStart && $n <= $rangeEnd) {
                $count++;
            }
        }

        return $count;
    }

    public function firstFreeNumericInRange(string $prefix, int $rangeStart, int $rangeEnd): ?int
    {
        $prefix = strtoupper($prefix);
        if ($prefix === '' || !preg_match('/^[A-Z]{2}$/', $prefix)) {
            return null;
        }

        $like      = $this->wpdb->esc_like($prefix) . '%';
        $prefixLen = strlen($prefix);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $codes = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT code FROM `{$this->packagesTable}` WHERE code LIKE %s",
                $like,
            )
        );

        $occupied = [];
        foreach ($codes ?: [] as $code) {
            $n = (int) substr((string) $code, $prefixLen);
            if ($n >= $rangeStart && $n <= $rangeEnd) {
                $occupied[$n] = true;
            }
        }

        for ($n = $rangeStart; $n <= $rangeEnd; $n++) {
            if (!isset($occupied[$n])) {
                return $n;
            }
        }

        return null;
    }

    public function updateStatusPresentation(int $id, StatusEmailBucket $bucket, int $currentSid, string $labelSnapshot): void
    {
        $this->wpdb->update(
            $this->table,
            [
                'status'                => $bucket->value,
                'current_sid'           => $currentSid,
                'status_label_snapshot' => $labelSnapshot,
                'updated_at'            => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s'],
            ['%d'],
        );
    }

    public function getSendStatus(int $id): string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT send_status FROM `{$this->table}` WHERE id = %d LIMIT 1",
                $id,
            ),
        );

        return is_string($value) && $value !== '' ? $value : 'sent';
    }

    public function setSendStatus(int $id, string $sendStatus): void
    {
        $normalized = $sendStatus === 'pending_send' ? 'pending_send' : 'sent';
        $this->wpdb->update(
            $this->table,
            [
                'send_status' => $normalized,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    public function updateDraftData(Shipment $shipment): void
    {
        $id = (int) $shipment->id();
        if ($id <= 0) {
            throw new \RuntimeException('Pošiljka nije validna za ažuriranje.');
        }

        $this->wpdb->update(
            $this->table,
            [
                'sender_location_id' => $shipment->senderLocationId,
                'dl_type_id'         => $shipment->deliveryType->value,
                'payment_by'         => $shipment->paymentBy->value,
                'payment_type'       => $shipment->paymentType->value,
                'value_para'         => $shipment->declaredValue->toPara(),
                'cod_amount_para'    => $shipment->codAmount->toPara(),
                'cod_bank_account'   => $shipment->codBankAccount,
                'total_mass_grams'   => $shipment->totalMass->value(),
                'content'            => $shipment->content,
                'note'               => $shipment->note,
                'return_doc'         => $shipment->returnDoc->value,
                'self_drop_off'      => $shipment->selfDropOff ? 1 : 0,
                'updated_at'         => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s'],
            ['%d'],
        );
    }

    public function findAdminListRows(int $offset, int $limit, ?string $statusFilter): array
    {
        $base = "SELECT s.id, s.order_id, s.status, s.current_sid, s.status_label_snapshot, s.created_at,
                (SELECT MIN(p.code) FROM `{$this->packagesTable}` p WHERE p.shipment_id = s.id) AS package_code
             FROM `{$this->table}` s
             WHERE s.deleted_at IS NULL";

        if ($statusFilter !== null && $statusFilter !== '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $this->wpdb->prepare(
                $base . ' AND s.status = %s ORDER BY s.created_at DESC LIMIT %d OFFSET %d',
                $statusFilter,
                $limit,
                $offset,
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $this->wpdb->prepare(
                $base . ' ORDER BY s.created_at DESC LIMIT %d OFFSET %d',
                $limit,
                $offset,
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'                     => (int) $row['id'],
                'order_id'               => (int) $row['order_id'],
                'status'                 => (string) $row['status'],
                'current_sid'            => (int) ($row['current_sid'] ?? 0),
                'status_label_snapshot'  => (string) ($row['status_label_snapshot'] ?? ''),
                'created_at'             => (string) $row['created_at'],
                'package_code'           => $row['package_code'] !== null && $row['package_code'] !== ''
                    ? (string) $row['package_code']
                    : null,
            ];
        }

        return $out;
    }

    public function countAdminList(?string $statusFilter): int
    {
        $base = "SELECT COUNT(*) FROM `{$this->table}` WHERE deleted_at IS NULL";

        if ($statusFilter !== null && $statusFilter !== '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $this->wpdb->prepare($base . ' AND status = %s', $statusFilter);
        } else {
            $sql = $base;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->wpdb->get_var($sql);
    }

    public function countAllNotDeleted(): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$this->table}` WHERE deleted_at IS NULL",
        );
    }

    private function hydrate(array $row): Shipment
    {
        $packages = $this->packages->findByShipmentId((int) $row['id']);

        $bucket = StatusEmailBucket::tryFrom((string) $row['status']) ?? StatusEmailBucket::Other;

        return Shipment::reconstitute(
            id:               (int) $row['id'],
            orderId:          (int) $row['order_id'],
            referenceId:      $row['reference_id'],
            senderLocationId: (int) $row['sender_location_id'],
            emailBucket:      $bucket,
            currentSid:       (int) ($row['current_sid'] ?? 0),
            statusLabelSnapshot: (string) ($row['status_label_snapshot'] ?? ''),
            deliveryType:     DeliveryType::from((int) $row['dl_type_id']),
            paymentBy:        PaymentBy::from((int) $row['payment_by']),
            paymentType:      PaymentType::from((int) $row['payment_type']),
            declaredValue:    Money::fromPara((int) $row['value_para']),
            codAmount:        Money::fromPara((int) $row['cod_amount_para']),
            codBankAccount:   $row['cod_bank_account'],
            totalMass:        Grams::fromGrams((int) $row['total_mass_grams']),
            content:          $row['content'],
            note:             (string) ($row['note'] ?? ''),
            returnDoc:        ReturnDoc::from((int) $row['return_doc']),
            selfDropOff:      (bool) (int) $row['self_drop_off'],
            splitIndex:       (int) $row['split_index'],
            totalSplits:      (int) $row['total_splits'],
            apiResponse:      $row['api_response'],
            packages:         $packages,
            createdAt:        new \DateTimeImmutable($row['created_at']),
        );
    }
}
