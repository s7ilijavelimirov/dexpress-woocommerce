<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Persistence;

use wpdb;

final class WpdbSenderLocationRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'dexpress_sender_locations';
    }

    /** @return array<int, array<string, mixed>> */
    public function findAll(): array
    {
        $townTable = $this->wpdb->prefix . 'dexpress_towns';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->wpdb->get_results(
            "SELECT sl.*, COALESCE(t.display_name, '') AS town_name
             FROM `{$this->table}` sl
             LEFT JOIN `{$townTable}` t ON t.id = sl.town_id
             WHERE sl.deleted_at IS NULL
             ORDER BY sl.is_default DESC, sl.name ASC",
            ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM `{$this->table}` WHERE id = %d AND deleted_at IS NULL",
                $id,
            ),
            ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Creates or updates a sender location.
     *
     * @param array<string, mixed> $data
     */
    public function save(array $data): bool
    {
        $now = current_time('mysql');

        if (!empty($data['id'])) {
            $result = $this->wpdb->update(
                $this->table,
                [
                    'name'          => $data['name'],
                    'street_id'     => (int) $data['street_id'],
                    'street_name'   => $data['street_name'],
                    'street_number' => $data['street_number'] ?? '',
                    'town_id'       => (int) $data['town_id'],
                    'address_desc'  => $data['address_desc'] ?? '',
                    'contact_name'  => $data['contact_name'],
                    'contact_phone' => $data['contact_phone'] ?? '',
                    'bank_account'  => $data['bank_account'] !== '' ? $data['bank_account'] : null,
                    'updated_at'    => $now,
                ],
                ['id' => (int) $data['id']],
                ['%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'],
                ['%d'],
            );

            return $result !== false;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hasAny = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$this->table}` WHERE deleted_at IS NULL"
        );

        $result = $this->wpdb->insert(
            $this->table,
            [
                'name'          => $data['name'],
                'street_id'     => (int) $data['street_id'],
                'street_name'   => $data['street_name'],
                'street_number' => $data['street_number'] ?? '',
                'town_id'       => (int) $data['town_id'],
                'address_desc'  => $data['address_desc'] ?? '',
                'contact_name'  => $data['contact_name'],
                'contact_phone' => $data['contact_phone'] ?? '',
                'bank_account'  => ($data['bank_account'] ?? '') !== '' ? $data['bank_account'] : null,
                'is_default'    => $hasAny === 0 ? 1 : 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            ['%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
        );

        return $result !== false;
    }

    public function delete(int $id): bool
    {
        $wasDefault = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT is_default FROM `{$this->table}` WHERE id = %d",
                $id,
            )
        );

        $result = $this->wpdb->update(
            $this->table,
            ['deleted_at' => current_time('mysql')],
            ['id' => $id],
            ['%s'],
            ['%d'],
        );

        if ($result !== false && $wasDefault) {
            $nextId = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM `{$this->table}` WHERE deleted_at IS NULL AND id != %d ORDER BY id ASC LIMIT 1",
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
        // No user input — reset all to 0 before setting the chosen one.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            "UPDATE `{$this->table}` SET is_default = 0"
        );

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
