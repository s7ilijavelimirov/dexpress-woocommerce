<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence\Sync;

use S7codedesign\DExpress\Infrastructure\StringNormalizer;
use wpdb;

final class StreetRepository
{
    private const BATCH_SIZE = 500;

    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_streets';
    }

    /**
     * Upserts streets in batches of 500 using multi-row INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int total rows processed
     */
    public function upsertBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $now      = current_time('mysql');
        $total    = 0;
        $batches  = array_chunk($rows, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            $placeholders = [];
            $values       = [];

            foreach ($batch as $row) {
                $name           = (string) ($row['Name'] ?? '');
                $nameSearchable = StringNormalizer::toSearchable($name);
                $deleted        = !empty($row['Del']) ? 1 : 0;

                $placeholders[] = '(%d, %s, %s, %d, %d, %s, %s)';
                array_push(
                    $values,
                    $row['Id'],
                    $name,
                    $nameSearchable,
                    $row['TId'] ?? 0,
                    $deleted,
                    $now,
                    $now,
                );
            }

            $sql = "INSERT INTO `{$this->table}`
                        (id, name, name_searchable, town_id, deleted, created_at, updated_at)
                    VALUES " . implode(', ', $placeholders) . "
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        name_searchable = VALUES(name_searchable),
                        town_id = VALUES(town_id),
                        deleted = VALUES(deleted),
                        updated_at = VALUES(updated_at)";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $result = $this->wpdb->query($this->wpdb->prepare($sql, ...$values));

            if ($result !== false) {
                $total += count($batch);
            }
        }

        return $total;
    }
}
