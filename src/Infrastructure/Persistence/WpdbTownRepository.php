<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use S7codedesign\DExpress\Infrastructure\StringNormalizer;
use wpdb;

final class WpdbTownRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_towns';
    }

    /**
     * Full-text search on the normalised column (diacritics-insensitive).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        $searchable = StringNormalizer::toSearchable($trimmed);
        $like       = '%' . $this->wpdb->esc_like($searchable) . '%';

        $sql = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, display_name AS name FROM `{$this->table}`
             WHERE name_searchable LIKE %s
             ORDER BY sort_order ASC, display_name ASC
             LIMIT %d",
            $like,
            $limit,
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return is_array($rows) ? $rows : [];
    }

    /** @return array{id: string, name: string}|null */
    public function findById(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, display_name AS name FROM `{$this->table}` WHERE id = %d",
                $id,
            ),
            ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * For shipping labels: postal code + town display name.
     *
     * @return array{display_name: string, postal_code: int|null}|null
     */
    public function findPostalDisplay(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT display_name, postal_code FROM `{$this->table}` WHERE id = %d",
                $id,
            ),
            ARRAY_A,
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'display_name' => (string) $row['display_name'],
            'postal_code'  => isset($row['postal_code']) && $row['postal_code'] !== null && $row['postal_code'] !== ''
                ? (int) $row['postal_code']
                : null,
        ];
    }
}

