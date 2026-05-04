<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use S7codedesign\DExpress\Infrastructure\StringNormalizer;
use wpdb;

final class WpdbStreetRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_streets';
    }

    /**
     * Searches streets within a town by name (diacritics-insensitive).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function search(int $townId, string $query, int $limit = 20): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        $searchable = StringNormalizer::toSearchable($trimmed);
        $like       = '%' . $this->wpdb->esc_like($searchable) . '%';

        $sql = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, name FROM `{$this->table}`
             WHERE town_id = %d AND deleted = 0 AND name_searchable LIKE %s
             ORDER BY name ASC
             LIMIT %d",
            $townId,
            $like,
            $limit,
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_array($rows) ? $rows : [];
    }
}
