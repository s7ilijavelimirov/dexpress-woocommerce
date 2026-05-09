<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use S7codedesign\DExpress\Application\Sync\RowChangeStats;
use wpdb;

final class WpdbPaymentRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_payments';
    }

    /**
     * Upsert po payment_reference + shipment_code.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsertByReference(string $paymentReference, array $rows): RowChangeStats
    {
        $ref = trim($paymentReference);
        if ($ref === '') {
            return new RowChangeStats();
        }

        $now    = current_time('mysql');
        $parsed = [];
        foreach ($rows as $row) {
            $payment = $this->normalizeApiRow($ref, $row);
            if ($payment === null) {
                continue;
            }
            $parsed[$payment['shipment_code']] = $payment;
        }

        $existingByCode = $this->existingByReferenceAndCodes($ref, array_keys($parsed));
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($parsed as $shipmentCode => $payment) {
            $existing = $existingByCode[$shipmentCode] ?? null;
            if ($existing === null) {
                $result = $this->wpdb->insert(
                    $this->table,
                    [
                        'payment_reference' => $payment['payment_reference'],
                        'shipment_code' => $payment['shipment_code'],
                        'order_reference_id' => $payment['order_reference_id'],
                        'buyout_para' => $payment['buyout_para'],
                        'recipient_name' => $payment['recipient_name'],
                        'recipient_address' => $payment['recipient_address'],
                        'recipient_town' => $payment['recipient_town'],
                        'payment_date' => $payment['payment_date'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'],
                );
                if ($result === false) {
                    throw new \RuntimeException('Greška pri unosu otkupnine: ' . $this->wpdb->last_error);
                }
                ++$inserted;
                continue;
            }

            if ($this->isSamePayment($existing, $payment)) {
                ++$unchanged;
                continue;
            }

            $result = $this->wpdb->update(
                $this->table,
                [
                    'order_reference_id' => $payment['order_reference_id'],
                    'buyout_para' => $payment['buyout_para'],
                    'recipient_name' => $payment['recipient_name'],
                    'recipient_address' => $payment['recipient_address'],
                    'recipient_town' => $payment['recipient_town'],
                    'payment_date' => $payment['payment_date'],
                    'updated_at' => $now,
                ],
                ['id' => (int) $existing['id']],
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s'],
                ['%d'],
            );
            if ($result === false) {
                throw new \RuntimeException('Greška pri ažuriranju otkupnine: ' . $this->wpdb->last_error);
            }
            ++$updated;
        }

        $deleted = $this->deleteMissingForReference($ref, array_keys($parsed));

        return new RowChangeStats(
            inserted: $inserted,
            updated: $updated,
            unchanged: $unchanged,
            deleted: $deleted,
        );
    }

    /**
     * @return array{records:int,total_para:int,min_date:string,max_date:string}
     */
    public function summary(string $search = '', string $dateFrom = '', string $dateTo = ''): array
    {
        [$whereSql, $params] = $this->buildFilters($search, $dateFrom, $dateTo);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) AS records, COALESCE(SUM(buyout_para), 0) AS total_para, MIN(payment_date) AS min_date, MAX(payment_date) AS max_date FROM `{$this->table}` {$whereSql}";
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, ...$params) : $sql;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        if (!is_array($row)) {
            return ['records' => 0, 'total_para' => 0, 'min_date' => '', 'max_date' => ''];
        }

        return [
            'records' => (int) ($row['records'] ?? 0),
            'total_para' => (int) ($row['total_para'] ?? 0),
            'min_date' => (string) ($row['min_date'] ?? ''),
            'max_date' => (string) ($row['max_date'] ?? ''),
        ];
    }

    public function count(string $search = '', string $dateFrom = '', string $dateTo = ''): int
    {
        [$whereSql, $params] = $this->buildFilters($search, $dateFrom, $dateTo);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) FROM `{$this->table}` {$whereSql}";
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, ...$params) : $sql;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->wpdb->get_var($prepared);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find(string $search, string $dateFrom, string $dateTo, int $offset, int $limit): array
    {
        [$whereSql, $params] = $this->buildFilters($search, $dateFrom, $dateTo);
        $params[] = max(0, $offset);
        $params[] = max(1, $limit);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT id, payment_reference, shipment_code, order_reference_id, buyout_para, recipient_name, recipient_address, recipient_town, payment_date, created_at, updated_at
                FROM `{$this->table}`
                {$whereSql}
                ORDER BY payment_date DESC, id DESC
                LIMIT %d, %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForExport(string $search, string $dateFrom, string $dateTo): array
    {
        [$whereSql, $params] = $this->buildFilters($search, $dateFrom, $dateTo);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT payment_reference, shipment_code, order_reference_id, buyout_para, recipient_name, recipient_address, recipient_town, payment_date
                FROM `{$this->table}`
                {$whereSql}
                ORDER BY payment_date DESC, id DESC";

        $prepared = !empty($params) ? $this->wpdb->prepare($sql, ...$params) : $sql;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<string>
     */
    public function latestPaymentReferences(int $limit = 30): array
    {
        $cap = max(1, $limit);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT DISTINCT payment_reference FROM `{$this->table}` WHERE payment_reference <> '' ORDER BY updated_at DESC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_col($this->wpdb->prepare($sql, $cap));
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $rows,
        )));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *  payment_reference:string,
     *  shipment_code:string,
     *  order_reference_id:string,
     *  buyout_para:int,
     *  recipient_name:string,
     *  recipient_address:string,
     *  recipient_town:string,
     *  payment_date:?string
     * }|null
     */
    private function normalizeApiRow(string $paymentReference, array $row): ?array
    {
        $shipmentCode = trim((string) ($row['ShCode'] ?? ''));
        if ($shipmentCode === '') {
            return null;
        }

        return [
            'payment_reference' => $paymentReference,
            'shipment_code' => $shipmentCode,
            'order_reference_id' => trim((string) ($row['ReferenceID'] ?? '')),
            'buyout_para' => max(0, (int) ($row['Buyout'] ?? 0)),
            'recipient_name' => trim((string) ($row['RName'] ?? '')),
            'recipient_address' => trim((string) ($row['RAddress'] ?? '')),
            'recipient_town' => trim((string) ($row['RTown'] ?? '')),
            'payment_date' => $this->parsePaymentDate((string) ($row['PaymentDate'] ?? '')),
        ];
    }

    private function parsePaymentDate(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $dt = \DateTime::createFromFormat('Ymd', $value);
        if (!$dt instanceof \DateTime) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    /**
     * @param list<string> $shipmentCodes
     * @return array<string, array{id:int,order_reference_id:string,buyout_para:int,recipient_name:string,recipient_address:string,recipient_town:string,payment_date:?string}>
     */
    private function existingByReferenceAndCodes(string $paymentReference, array $shipmentCodes): array
    {
        if ($shipmentCodes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($shipmentCodes), '%s'));
        $params = [$paymentReference, ...$shipmentCodes];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT id, shipment_code, order_reference_id, buyout_para, recipient_name, recipient_address, recipient_town, payment_date
                FROM `{$this->table}`
                WHERE payment_reference = %s AND shipment_code IN ({$placeholders})";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $indexed = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['shipment_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $indexed[$code] = [
                'id' => (int) ($row['id'] ?? 0),
                'order_reference_id' => trim((string) ($row['order_reference_id'] ?? '')),
                'buyout_para' => (int) ($row['buyout_para'] ?? 0),
                'recipient_name' => trim((string) ($row['recipient_name'] ?? '')),
                'recipient_address' => trim((string) ($row['recipient_address'] ?? '')),
                'recipient_town' => trim((string) ($row['recipient_town'] ?? '')),
                'payment_date' => $row['payment_date'] !== null ? (string) $row['payment_date'] : null,
            ];
        }

        return $indexed;
    }

    /**
     * @param array{id:int,order_reference_id:string,buyout_para:int,recipient_name:string,recipient_address:string,recipient_town:string,payment_date:?string} $existing
     * @param array{
     *  payment_reference:string,
     *  shipment_code:string,
     *  order_reference_id:string,
     *  buyout_para:int,
     *  recipient_name:string,
     *  recipient_address:string,
     *  recipient_town:string,
     *  payment_date:?string
     * } $incoming
     */
    private function isSamePayment(array $existing, array $incoming): bool
    {
        return $existing['order_reference_id'] === $incoming['order_reference_id']
            && $existing['buyout_para'] === $incoming['buyout_para']
            && $existing['recipient_name'] === $incoming['recipient_name']
            && $existing['recipient_address'] === $incoming['recipient_address']
            && $existing['recipient_town'] === $incoming['recipient_town']
            && $existing['payment_date'] === $incoming['payment_date'];
    }

    /**
     * @param list<string> $remainingShipmentCodes
     */
    private function deleteMissingForReference(string $paymentReference, array $remainingShipmentCodes): int
    {
        if ($remainingShipmentCodes === []) {
            $result = $this->wpdb->delete(
                $this->table,
                ['payment_reference' => $paymentReference],
                ['%s'],
            );

            return $result === false ? 0 : (int) $result;
        }

        $placeholders = implode(', ', array_fill(0, count($remainingShipmentCodes), '%s'));
        $params = [$paymentReference, ...$remainingShipmentCodes];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "DELETE FROM `{$this->table}` WHERE payment_reference = %s AND shipment_code NOT IN ({$placeholders})";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $this->wpdb->query($this->wpdb->prepare($sql, ...$params));

        return $result === false ? 0 : (int) $result;
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private function buildFilters(string $search = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $where = [];
        $params = [];

        $q = trim($search);
        if ($q !== '') {
            $like = '%' . $this->wpdb->esc_like($q) . '%';
            $where[] = '(payment_reference LIKE %s OR shipment_code LIKE %s OR order_reference_id LIKE %s OR recipient_name LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        $from = $this->sanitizeDate($dateFrom);
        if ($from !== '') {
            $where[] = 'payment_date >= %s';
            $params[] = $from;
        }

        $to = $this->sanitizeDate($dateTo);
        if ($to !== '') {
            $where[] = 'payment_date <= %s';
            $params[] = $to;
        }

        $sql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$sql, $params];
    }

    private function sanitizeDate(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $raw);
        if (!$dt instanceof \DateTime) {
            return '';
        }

        return $dt->format('Y-m-d');
    }
}
