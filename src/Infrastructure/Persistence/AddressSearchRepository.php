<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use S7codedesign\DExpress\Infrastructure\StringNormalizer;
use wpdb;

final class AddressSearchRepository
{
    private string $townsTable;
    private string $streetsTable;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->townsTable   = $wpdb->prefix . 'dexpress_towns';
        $this->streetsTable = $wpdb->prefix . 'dexpress_streets';
    }

    /**
     * @return array<int, array{id: int, name: string, display_name: string, postal_code: int|null}>
     */
    public function searchTowns(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $normalized = StringNormalizer::toSearchable($query);
        $like       = '%' . $this->wpdb->esc_like($normalized) . '%';
        $prefix     = $this->wpdb->esc_like($normalized) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT id, name, display_name, postal_code
             FROM `{$this->townsTable}`
             WHERE name_searchable LIKE %s
             ORDER BY (name_searchable LIKE %s) DESC, name ASC
             LIMIT %d",
            $like,
            $prefix,
            $limit,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'display_name' => $row['display_name'],
                'postal_code'  => isset($row['postal_code']) ? (int) $row['postal_code'] : null,
            ];
        }, $rows);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function searchStreets(string $query, int $townId, int $limit = 20): array
    {
        $query = trim($query);
        if ($townId <= 0 || mb_strlen($query) < 2) {
            return [];
        }

        $normalized = StringNormalizer::toSearchable($query);
        $like       = '%' . $this->wpdb->esc_like($normalized) . '%';
        $prefix     = $this->wpdb->esc_like($normalized) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT id, name
             FROM `{$this->streetsTable}`
             WHERE town_id = %d AND deleted = 0 AND name_searchable LIKE %s
             ORDER BY (name_searchable LIKE %s) DESC, name ASC
             LIMIT %d",
            $townId,
            $like,
            $prefix,
            $limit,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
            ];
        }, $rows);
    }

    /**
     * Exact match on normalized street label (same algorithm as name_searchable indexing).
     */
    public function findStreetIdExact(int $townId, string $streetLabel): ?int
    {
        if ($townId <= 0) {
            return null;
        }

        $streetLabel = trim($streetLabel);
        if ($streetLabel === '') {
            return null;
        }

        $normalized = StringNormalizer::toSearchable($streetLabel);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT id FROM `{$this->streetsTable}`
             WHERE town_id = %d AND deleted = 0 AND name_searchable = %s
             LIMIT 1",
            $townId,
            $normalized,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $id = $this->wpdb->get_var($sql);

        if ($id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    public function findStreetNameById(int $streetId): ?string
    {
        if ($streetId <= 0) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT name FROM `{$this->streetsTable}` WHERE id = %d AND deleted = 0 LIMIT 1",
            $streetId,
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $name = $this->wpdb->get_var($sql);

        if ($name === null || $name === '') {
            return null;
        }

        return (string) $name;
    }
}
