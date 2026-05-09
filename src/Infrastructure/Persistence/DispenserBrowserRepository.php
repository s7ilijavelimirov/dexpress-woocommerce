<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use wpdb;

final class DispenserBrowserRepository
{
    private string $dispensersTable;
    private string $locationsTable;
    private string $townsTable;
    private string $municipalitiesTable;

    public function __construct(private readonly wpdb $wpdb)
    {
        $prefix = $wpdb->prefix;
        $this->dispensersTable    = $prefix . 'dexpress_dispensers';
        $this->locationsTable     = $prefix . 'dexpress_locations';
        $this->townsTable         = $prefix . 'dexpress_towns';
        $this->municipalitiesTable = $prefix . 'dexpress_municipalities';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query = '', int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $query = trim($query);

        $locationSql = "
            SELECT
                l.id,
                l.name,
                l.description,
                l.address,
                l.town_name,
                l.town_id,
                m.name AS municipality_name,
                l.work_hours,
                l.work_days,
                l.phone,
                l.latitude,
                l.longitude,
                l.location_type,
                l.pay_by_cash,
                l.pay_by_card
            FROM {$this->locationsTable} l
            LEFT JOIN {$this->townsTable} t ON t.id = l.town_id
            LEFT JOIN {$this->municipalitiesTable} m ON m.id = t.municipality_id
        ";

        if ($query !== '') {
            $like = '%' . $this->wpdb->esc_like($query) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $locationSql .= $this->wpdb->prepare(
                " WHERE (
                    l.name LIKE %s OR
                    l.description LIKE %s OR
                    l.address LIKE %s OR
                    l.town_name LIKE %s OR
                    m.name LIKE %s OR
                    l.work_hours LIKE %s OR
                    l.work_days LIKE %s
                )",
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $locationSql .= $this->wpdb->prepare(' ORDER BY l.town_name ASC, l.name ASC LIMIT %d', $limit);

        $dispenserSql = "
            SELECT
                d.id,
                d.name,
                '' AS description,
                d.address,
                d.town_name,
                d.town_id,
                m.name AS municipality_name,
                d.work_hours,
                d.work_days,
                '' AS phone,
                d.latitude,
                d.longitude,
                '2' AS location_type,
                d.pay_by_cash,
                d.pay_by_card
            FROM {$this->dispensersTable} d
            LEFT JOIN {$this->locationsTable} l ON l.id = d.id
            LEFT JOIN {$this->townsTable} t ON t.id = d.town_id
            LEFT JOIN {$this->municipalitiesTable} m ON m.id = t.municipality_id
            WHERE l.id IS NULL
        ";

        if ($query !== '') {
            $like = '%' . $this->wpdb->esc_like($query) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $dispenserSql .= $this->wpdb->prepare(
                " AND (
                    d.name LIKE %s OR
                    d.address LIKE %s OR
                    d.town_name LIKE %s OR
                    m.name LIKE %s OR
                    d.work_hours LIKE %s OR
                    d.work_days LIKE %s
                )",
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dispenserSql .= $this->wpdb->prepare(' ORDER BY d.town_name ASC, d.name ASC LIMIT %d', $limit);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $locationRows = $this->wpdb->get_results($locationSql, ARRAY_A);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $dispenserRows = $this->wpdb->get_results($dispenserSql, ARRAY_A);

        $locationRows = is_array($locationRows) ? $locationRows : [];
        $dispenserRows = is_array($dispenserRows) ? $dispenserRows : [];

        $rows = array_merge($locationRows, $dispenserRows);
        if ($rows === []) {
            return [];
        }

        $normalized = array_values(array_filter(array_map(
            [$this, 'normalizeRow'],
            $rows,
        )));

        usort($normalized, static function (array $a, array $b): int {
            $town = strcmp((string) ($a['town_name'] ?? ''), (string) ($b['town_name'] ?? ''));
            if ($town !== 0) {
                return $town;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array_slice($normalized, 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $locationSql = "
            SELECT
                l.id,
                l.name,
                l.description,
                l.address,
                l.town_name,
                l.town_id,
                m.name AS municipality_name,
                l.work_hours,
                l.work_days,
                l.phone,
                l.latitude,
                l.longitude,
                l.location_type,
                l.pay_by_cash,
                l.pay_by_card
            FROM {$this->locationsTable} l
            LEFT JOIN {$this->townsTable} t ON t.id = l.town_id
            LEFT JOIN {$this->municipalitiesTable} m ON m.id = t.municipality_id
            WHERE l.id = %d
            LIMIT 1
        ";
        // phpcs:enable

        $prepared = $this->wpdb->prepare($locationSql, $id);
        if (!is_string($prepared) || $prepared === '') {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        if (is_array($row)) {
            return $this->normalizeRow($row);
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $dispenserSql = "
            SELECT
                d.id,
                d.name,
                '' AS description,
                d.address,
                d.town_name,
                d.town_id,
                m.name AS municipality_name,
                d.work_hours,
                d.work_days,
                '' AS phone,
                d.latitude,
                d.longitude,
                '2' AS location_type,
                d.pay_by_cash,
                d.pay_by_card
            FROM {$this->dispensersTable} d
            LEFT JOIN {$this->townsTable} t ON t.id = d.town_id
            LEFT JOIN {$this->municipalitiesTable} m ON m.id = t.municipality_id
            WHERE d.id = %d
            LIMIT 1
        ";
        // phpcs:enable

        $prepared = $this->wpdb->prepare($dispenserSql, $id);
        if (!is_string($prepared) || $prepared === '') {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $latRaw = trim((string) ($row['latitude'] ?? ''));
        $lngRaw = trim((string) ($row['longitude'] ?? ''));
        if ($latRaw === '' || $lngRaw === '') {
            return null;
        }

        $lat = (float) str_replace(',', '.', $latRaw);
        $lng = (float) str_replace(',', '.', $lngRaw);
        if (!is_finite($lat) || !is_finite($lng) || $lat === 0.0 || $lng === 0.0) {
            return null;
        }

        $locationType = trim((string) ($row['location_type'] ?? ''));
        if ($locationType === '') {
            $locationType = '2';
        }

        return [
            'id'                => (int) ($row['id'] ?? 0),
            'name'              => (string) ($row['name'] ?? ''),
            'description'       => (string) ($row['description'] ?? ''),
            'address'           => (string) ($row['address'] ?? ''),
            'town_name'         => (string) ($row['town_name'] ?? ''),
            'town_id'           => (int) ($row['town_id'] ?? 0),
            'municipality_name' => (string) ($row['municipality_name'] ?? ''),
            'work_hours'        => (string) ($row['work_hours'] ?? ''),
            'work_days'         => (string) ($row['work_days'] ?? ''),
            'phone'             => (string) ($row['phone'] ?? ''),
            'latitude'          => $lat,
            'longitude'         => $lng,
            'location_type'     => $locationType,
            'location_type_label' => $this->resolveLocationTypeLabel($locationType),
            'is_dispenser'      => $locationType === '2',
            'pay_by_cash'       => !empty($row['pay_by_cash']),
            'pay_by_card'       => !empty($row['pay_by_card']),
        ];
    }

    private function resolveLocationTypeLabel(string $locationType): string
    {
        return match ($locationType) {
            '2' => 'Paketomat',
            '1', '3' => 'Paket Shop',
            default => 'Lokacija',
        };
    }
}
