<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence\Sync;

use S7codedesign\DExpress\Infrastructure\StringNormalizer;
use wpdb;

final class TownRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_towns';
    }

    /**
     * Upserts a batch of town records.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int number of rows affected
     */
    public function upsertBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $now       = current_time('mysql');
        $affected  = 0;

        foreach ($rows as $row) {
            $nameSearchable = StringNormalizer::toSearchable((string) ($row['Name'] ?? ''));

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $result = $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT INTO `{$this->table}`
                        (id, name, display_name, name_searchable, municipality_id, centre_id, postal_code, sort_order, delivery_days, cut_off_pickup_time, created_at, updated_at)
                     VALUES (%d, %s, %s, %s, %d, %d, %d, %d, %s, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        display_name = VALUES(display_name),
                        name_searchable = VALUES(name_searchable),
                        municipality_id = VALUES(municipality_id),
                        centre_id = VALUES(centre_id),
                        postal_code = VALUES(postal_code),
                        sort_order = VALUES(sort_order),
                        delivery_days = VALUES(delivery_days),
                        cut_off_pickup_time = VALUES(cut_off_pickup_time),
                        updated_at = VALUES(updated_at)",
                    $row['Id'],
                    $row['Name'] ?? '',
                    $row['DName'] ?? '',
                    $nameSearchable,
                    $row['MId'] ?? 0,
                    $row['CentarId'] ?? 0,
                    $row['PttNo'] ?? 0,
                    $row['O'] ?? 0,
                    $row['DeliveryDays'] ?? '',
                    $row['CutOffPickupTime'] ?? '',
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
