<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence\Sync;

use S7codedesign\DExpress\Application\Sync\RowChangeStats;
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
     * Upserts streets u chunkovima (jedan SQL po chunk-u) radi performansi; statistika iz zbira affected_rows po chunk-u.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsertBatch(array $rows): RowChangeStats
    {
        if (empty($rows)) {
            return new RowChangeStats();
        }

        $now   = current_time('mysql');
        $stats = new RowChangeStats();

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $chunkStats = $this->upsertChunk($chunk, $now);
            $stats      = RowChangeStats::merge($stats, $chunkStats);
        }

        return $stats;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     */
    private function upsertChunk(array $chunk, string $now): RowChangeStats
    {
        $placeholders = [];
        $flatValues   = [];

        foreach ($chunk as $row) {
            $name           = (string) ($row['Name'] ?? '');
            $nameSearchable = StringNormalizer::toSearchable($name);
            $deleted        = !empty($row['Del']) ? 1 : 0;

            $placeholders[] = '(%d, %s, %s, %d, %d, %s, %s)';
            $flatValues[]   = (int) $row['Id'];
            $flatValues[]   = $name;
            $flatValues[]   = $nameSearchable;
            $flatValues[]   = (int) ($row['TId'] ?? 0);
            $flatValues[]   = $deleted;
            $flatValues[]   = $now;
            $flatValues[]   = $now;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = 'INSERT INTO `' . $this->table . '`
            (id, name, name_searchable, town_id, deleted, created_at, updated_at)
            VALUES ' . implode(',', $placeholders) . '
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                name_searchable = VALUES(name_searchable),
                town_id = VALUES(town_id),
                deleted = VALUES(deleted),
                updated_at = VALUES(updated_at)';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $prepared = call_user_func_array([$this->wpdb, 'prepare'], array_merge([$sql], $flatValues));
        $result   = $this->wpdb->query($prepared);

        if ($result === false) {
            return new RowChangeStats();
        }

        return UpsertOutcome::fromBatchWpdb($this->wpdb, count($chunk));
    }
}
