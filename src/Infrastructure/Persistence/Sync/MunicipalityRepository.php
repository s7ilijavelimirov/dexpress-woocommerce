<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence\Sync;

use wpdb;

final class MunicipalityRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_municipalities';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsertBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $now      = current_time('mysql');
        $affected = 0;

        foreach ($rows as $row) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT INTO `{$this->table}`
                        (id, name, postal_code, sort_order, created_at, updated_at)
                     VALUES (%d, %s, %d, %d, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        postal_code = VALUES(postal_code),
                        sort_order = VALUES(sort_order),
                        updated_at = VALUES(updated_at)",
                    $row['Id'],
                    $row['Name'] ?? '',
                    $row['PttNo'] ?? 0,
                    $row['O'] ?? 0,
                    $now,
                    $now,
                ),
            );

            if ($result !== false) {
                $affected++;
            }
        }

        return $affected;
    }
}
