<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use wpdb;

/**
 * CRUD za tabelu wp_dexpress_package_profiles.
 * Prati isti obrazac kao WpdbSenderLocationRepository.
 */
final class WpdbPackageProfileRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_package_profiles';
    }

    /**
     * Vraća sve profile sortirane: podrazumevani prvi, pa po nazivu.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->wpdb->get_results(
            "SELECT * FROM `{$this->table}` ORDER BY is_default DESC, name ASC",
            ARRAY_A,
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM `{$this->table}` WHERE id = %d",
                $id,
            ),
            ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Kreira novi ili ažurira postojeći profil.
     * Ako data['id'] > 0 → UPDATE, inače → INSERT.
     *
     * @param array<string, mixed> $data
     */
    public function save(array $data): bool
    {
        $now = current_time('mysql');

        $row = [
            'name'            => (string) ($data['name'] ?? ''),
            'description'     => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            'weight_grams'    => (int) ($data['weight_grams'] ?? 0),
            'dim_x'           => isset($data['dim_x']) && (string) $data['dim_x'] !== '' ? (int) $data['dim_x'] : null,
            'dim_y'           => isset($data['dim_y']) && (string) $data['dim_y'] !== '' ? (int) $data['dim_y'] : null,
            'dim_z'           => isset($data['dim_z']) && (string) $data['dim_z'] !== '' ? (int) $data['dim_z'] : null,
            'default_content' => isset($data['default_content']) && $data['default_content'] !== '' ? (string) $data['default_content'] : null,
        ];

        if (!empty($data['id'])) {
            $row['updated_at'] = $now;

            $result = $this->wpdb->update(
                $this->table,
                $row,
                ['id' => (int) $data['id']],
                ['%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s'],
                ['%d'],
            );

            return $result !== false;
        }

        // Ako nema profila, prvi je automatski podrazumevani.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hasAny = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table}`");

        $row['is_default'] = $hasAny === 0 ? 1 : 0;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $this->wpdb->insert(
            $this->table,
            $row,
            ['%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s'],
        ) !== false;
    }

    public function delete(int $id): bool
    {
        $wasDefault = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT is_default FROM `{$this->table}` WHERE id = %d",
                $id,
            )
        );

        $result = $this->wpdb->delete($this->table, ['id' => $id], ['%d']);

        // Ako je obrisan podrazumevani, postavi sledeći po redu.
        if ($result !== false && $wasDefault) {
            $nextId = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT id FROM `{$this->table}` WHERE id != %d ORDER BY id ASC LIMIT 1",
                    $id,
                )
            );

            if ($nextId > 0) {
                $this->wpdb->update(
                    $this->table,
                    ['is_default' => 1],
                    ['id' => $nextId],
                    ['%d'],
                    ['%d'],
                );
            }
        }

        return $result !== false;
    }

    public function setDefault(int $id): bool
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query("UPDATE `{$this->table}` SET is_default = 0");

        $result = $this->wpdb->update(
            $this->table,
            ['is_default' => 1],
            ['id' => $id],
            ['%d'],
            ['%d'],
        );

        return $result !== false;
    }
}
